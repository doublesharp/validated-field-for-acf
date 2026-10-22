<?php
namespace ACF_VF\App;

/** Durable outbox. Workers claim a row with a compare-and-set UPDATE. */
final class Jobs {
    private static $handlers = [];
    private static $transactional = [];
    private const MAX_ATTEMPTS = 5;

    public static function boot(): void {
        add_action('acf_vf_app_jobs', [self::class, 'tick']);
        add_action('init', [self::class, 'schedule']);
    }

    /**
     * Register a worker callback receiving (array $payload, int $job_id).
     *
     * Workers run as the enqueueing actor. Return false/WP_Error or throw to request retry.
     * Transactional handlers commit their work together with the queue completion receipt.
     */
    public static function register(string $kind, callable $handler, bool $transactional = false): void {
        self::$handlers[$kind] = $handler;
        self::$transactional[$kind] = $transactional;
        self::invalidate_parking($kind);
    }

    /** Provider restoration and newly runnable work require a fresh parking pass. */
    private static function invalidate_parking(string $kind, bool $new_work = false): void {
        $state=get_option('acf_vf_job_provider_parked',false);
        if ($new_work || isset($state['kinds'][$kind])) {
            // A fresh generation prevents an overlapping parking pass from
            // publishing its old result after this work becomes runnable.
            update_option('acf_vf_job_provider_parked',['generation'=>wp_generate_uuid4(),'kinds'=>[]],false);
        }
    }

    private static function remember_parking($before, array $state): void {
        global $wpdb;
        $name='acf_vf_job_provider_parked';
        if ($before===false) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')",$name,maybe_serialize($state)));
        } else {
            $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value=%s WHERE option_name=%s AND BINARY option_value=%s",maybe_serialize($state),$name,maybe_serialize($before)));
        }
        // The conditional write can lose to enqueue/retry in another request.
        // Read its current generation on the next pass rather than cache ours.
        wp_cache_delete($name,'options');
        wp_cache_delete('notoptions','options');
    }

    public static function schedule(): void {
        if (!Schema::objects() && get_option('acf_vf_app_storage_version', false) === false) { return; }
        if (!wp_next_scheduled('acf_vf_app_jobs')) { wp_schedule_single_event(time() + 60, 'acf_vf_app_jobs'); }
    }

    public static function tick(): void { self::run(10); self::schedule(); }

    /** Preserve unavailable work for explicit administrator review, without creating storage. */
    public static function park_unavailable(?array $kinds = null): void {
        if (get_option('acf_vf_app_storage_version', false) === false) { return; }
        if ($kinds !== null) {
            $before=get_option('acf_vf_job_provider_parked',false);
            $state=$before===false ? ['generation'=>wp_generate_uuid4(),'kinds'=>[]] : $before;
            $parked=$state['kinds'];
            $kinds=array_values(array_filter($kinds,static function ($kind) use ($parked) { return !isset($parked[$kind]); }));
            if (!$kinds) { return; }
            $paused=get_option('acf_vf_job_provider_paused',[]);
            foreach ($kinds as $kind) { $paused[$kind]=gmdate('Y-m-d H:i:s'); }
            update_option('acf_vf_job_provider_paused',$paused,false);
            global $wpdb; $table=Storage::table('jobs');
            $placeholders=implode(',',array_fill(0,count($kinds),'%s'));
            // Fence active leases too: an old worker's finish CAS must no longer match.
            if ($wpdb->query($wpdb->prepare("UPDATE $table SET status='blocked',last_error='provider_missing' WHERE kind IN ($placeholders) AND status IN ('queued','retry','running')",$kinds)) !== false) {
                foreach ($kinds as $kind) { $parked[$kind]=true; }
                $state['kinds']=$parked;
                self::remember_parking($before,$state);
            }
            return;
        }
        global $wpdb; $table=Storage::table('jobs');
        $pending=$wpdb->get_col("SELECT DISTINCT kind FROM $table WHERE status IN ('queued','retry','running')");
        foreach ($pending as $kind) {
            if (!isset(self::$handlers[$kind])) {
                // Fence active leases too: an old worker's finish CAS must no longer match.
                $wpdb->query($wpdb->prepare("UPDATE $table SET status='blocked',last_error='provider_missing' WHERE kind=%s AND status IN ('queued','retry','running')",$kind));
            }
        }
    }

    /**
     * Queue work for the current actor; enqueue inside a record transaction for atomic outbox writes.
     *
     * @param array $options delay in seconds and optional idempotency_key scoped to kind and actor.
     * @return int|\WP_Error New job ID, existing idempotent job ID, or queue error.
     */
    public static function enqueue(string $kind, array $payload, array $options = []) {
        global $wpdb;
        if (!isset(self::$handlers[$kind]) || !get_current_user_id()) { return new \WP_Error('job_kind', 'Job type or actor is unavailable.'); }
        $delay = $options['delay'] ?? 0;
        if (!is_numeric($delay) || $delay < 0 || $delay > YEAR_IN_SECONDS) { return new \WP_Error('job_delay', 'Job delay is invalid.'); }
        $valid=apply_filters('acf_vf/app/job_payload',true,$kind,$payload);
        if (is_wp_error($valid)) { return $valid; }
        $payload['_actor'] = get_current_user_id();
        $payload['_history'] = [];
        $encoded = wp_json_encode($payload);
        if (!$encoded || strlen($encoded) > 262144) { return new \WP_Error('job_payload', 'Job payload is too large or invalid.'); }
        if (isset($options['idempotency_key']) && (!is_string($options['idempotency_key']) || strlen($options['idempotency_key']) > 191)) { return new \WP_Error('job_idempotency', 'Job idempotency key must be text of at most 191 bytes.'); }
        $key = !empty($options['idempotency_key']) ? hash('sha256', $kind . ':' . get_current_user_id() . ':' . $options['idempotency_key']) : null;
        Storage::install();
        $table = Storage::table('jobs');
        if ($key) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE idempotency_key=%s", $key));
            if ($existing) { return (int) $existing; }
        }
        $previous_errors = $wpdb->suppress_errors(true);
        try { $ok = $wpdb->insert($table, ['kind' => $kind, 'payload' => $encoded, 'status' => 'queued', 'attempts' => 0, 'available_at' => gmdate('Y-m-d H:i:s', time() + (int) $delay), 'idempotency_key' => $key, 'last_error' => '']); }
        finally { $wpdb->suppress_errors($previous_errors); }
        if (!$ok && $key) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE idempotency_key=%s", $key));
            if ($existing) { return (int) $existing; }
        }
        if (!$ok) { return new \WP_Error('job_storage', 'Job could not be queued.'); }
        $id=(int)$wpdb->insert_id;
        // Publish the new generation only after the job is visible to parking
        // scans, without holding the shared option row through a record save.
        Storage::after_commit(static function () use ($kind) { self::invalidate_parking($kind,true); });
        self::schedule();
        return $id;
    }

    /**
     * Process due jobs from cron, WP-CLI or an administrator request.
     *
     * @return array<int,string> Claimed job IDs mapped to their resulting queue status.
     */
    public static function run(int $limit = 10): array {
        global $wpdb;
        if (!wp_doing_cron() && !(defined('WP_CLI') && WP_CLI) && !current_user_can('manage_options')) { return []; }
        if (!Schema::objects() && get_option('acf_vf_app_storage_version', false) === false) { return []; }
        Storage::install();
        $table = Storage::table('jobs');
        $now = gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', time() - 600);
        // Recover only a bounded batch, preserving a receipt for the interrupted attempt.
        $paused=get_option('acf_vf_job_provider_paused',[]);
        $expired = $wpdb->get_results($wpdb->prepare("SELECT id,kind,payload,attempts,locked_at FROM $table WHERE status='running' AND locked_at < %s ORDER BY id LIMIT 50", $stale), ARRAY_A);
        foreach ($expired as $lease) {
            $data = json_decode($lease['payload'], true) ?: [];
            $data['_history'][] = ['attempt' => (int) $lease['attempts'], 'at' => gmdate('c'), 'status' => 'lease_expired'];
            $data['_history'] = array_slice($data['_history'], -20);
            $previous_errors = $wpdb->suppress_errors(true);
            $held=!isset(self::$handlers[$lease['kind']]) || (isset($paused[$lease['kind']]) && $lease['locked_at'] <= $paused[$lease['kind']]);
            try { $wpdb->update($table, ['status' => $held ? 'blocked' : ((int) $lease['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'retry'), 'locked_at' => null, 'payload' => wp_json_encode($data), 'last_error'=>$held ? 'provider_missing' : ''], ['id' => $lease['id'], 'status' => 'running', 'locked_at' => $lease['locked_at']]); }
            finally { $wpdb->suppress_errors($previous_errors); }
        }
        self::park_unavailable();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE status IN ('queued','retry') AND available_at <= %s AND attempts < %d ORDER BY id LIMIT %d", $now, self::MAX_ATTEMPTS, max(1, min(50, $limit))));
        $results = [];
        foreach ($ids as $id) {
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='running',locked_at=%s,attempts=attempts+1 WHERE id=%d AND status IN ('queued','retry') AND available_at <= %s", $now, $id, $now));
            if ($claimed !== 1) { continue; }
            $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
            $payload = json_decode($job['payload'], true);
            $previous_user = get_current_user_id();
            $transaction = false;
            try {
                if (!is_array($payload) || !get_user_by('id', $payload['_actor'] ?? 0)) { throw new \RuntimeException('Job actor is unavailable.'); }
                wp_set_current_user((int) $payload['_actor']);
                if (!empty(self::$transactional[$job['kind']])) {
                    $begin = Storage::begin();
                    if (is_wp_error($begin)) { throw new \RuntimeException('Job transaction is unavailable.'); }
                    $transaction = true;
                }
                $handler = self::$handlers[$job['kind']] ?? null;
                $result = $handler ? call_user_func($handler, $payload, (int) $id) : new \WP_Error('job_kind', 'Job handler unavailable.');
                if (is_wp_error($result) || $result === false) { throw new \RuntimeException('Job handler failed.'); }
                if (!self::finish($job, $payload, 'completed', '')) { throw new \RuntimeException('Job lease was lost.'); }
                if ($transaction && !Storage::commit()) { throw new \RuntimeException('Job commit failed.'); }
                $transaction = false;
                $status = 'completed';
            } catch (\Throwable $error_object) {
                if ($transaction) { Storage::rollback(); }
                $current = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE id=%d AND attempts=%d", $id, $job['attempts']));
                $committed = $current === 'completed';
                $status = $committed ? 'completed' : ($current === 'blocked' ? 'blocked' : ((int) $job['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'retry'));
                if (!$committed && $status !== 'blocked') { self::finish($job, is_array($payload) ? $payload : [], $status, 'Job execution failed.'); }
                do_action('acf_vf/app/job_failed', (int) $id, $error_object);
            } finally { wp_set_current_user($previous_user); }
            $results[(int) $id] = $status;
        }
        return $results;
    }

    private static function finish(array $job, array $payload, string $status, string $error): bool {
        global $wpdb;
        $payload['_history'][] = ['attempt' => (int) $job['attempts'], 'at' => gmdate('c'), 'status' => $status];
        $payload['_history'] = array_slice($payload['_history'], -20);
        $changes = ['status' => $status, 'locked_at' => null, 'payload' => wp_json_encode($payload), 'last_error' => $error, 'available_at' => gmdate('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** (int) $job['attempts']))), 'completed_at' => $status === 'completed' ? gmdate('Y-m-d H:i:s') : null];
        // A recovered worker owns a different lease and must not be overwritten.
        $previous_errors = $wpdb->suppress_errors(true);
        try { return 1 === $wpdb->update(Storage::table('jobs'), $changes, ['id' => (int) $job['id'], 'status' => 'running', 'locked_at' => $job['locked_at'], 'attempts' => $job['attempts']]); }
        finally { $wpdb->suppress_errors($previous_errors); }
    }

    public static function listing(array $args = []) {
        global $wpdb;
        if (!current_user_can('manage_options')) { return new \WP_Error('forbidden', 'Job administration requires manage_options.'); }
        Storage::install();
        $table = Storage::table('jobs');
        $limit = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,kind,status,attempts,available_at,locked_at,completed_at,last_error FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, ($page - 1) * $limit), ARRAY_A);
        return ['items' => $rows, 'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"), 'page' => $page, 'per_page' => $limit];
    }

    public static function history(int $id) {
        global $wpdb;
        if (!current_user_can('manage_options')) { return new \WP_Error('forbidden', 'Job administration requires manage_options.'); }
        Storage::install();
        $table = Storage::table('jobs');
        $payload = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE id=%d", $id)), true);
        return $payload['_history'] ?? [];
    }

    public static function retry(int $id) {
        global $wpdb;
        if (!current_user_can('manage_options')) { return new \WP_Error('forbidden', 'Job administration requires manage_options.'); }
        Storage::install();
        $table = Storage::table('jobs');
        $kind=$wpdb->get_var($wpdb->prepare("SELECT kind FROM $table WHERE id=%d",$id));
        if (!isset(self::$handlers[$kind])) { return new \WP_Error('vf_provider_missing','Restore the job provider before retrying.',['status'=>409]); }
        $retried=1 === $wpdb->query($wpdb->prepare("UPDATE $table SET status='queued',attempts=0,available_at=%s,locked_at=NULL,completed_at=NULL WHERE id=%d AND status IN ('failed','retry','blocked')", gmdate('Y-m-d H:i:s'), $id));
        if ($retried) { self::invalidate_parking($kind,true); }
        return $retried;
    }


}
