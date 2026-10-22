<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Transactional scalar projection. Query still authorizes and evaluates every candidate. */
final class Index {
    private static $booted = false;

    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        Jobs::register('index_backfill', [__CLASS__, 'job']);
        add_action('acf_vf/app/record_persisted', [__CLASS__, 'persist'], 50, 3);
        add_action('acf_vf/app/record_delete_persisted', [__CLASS__, 'deleted'], 50, 2);
        add_action('updated_option', [__CLASS__, 'configuration'], 20, 3);
    }

    public static function status(string $object) {
        if (!current_user_can('manage_options')) { return new \WP_Error('index_forbidden', 'Index status requires configuration access.', ['status' => 403]); }
        return self::state($object);
    }

    private static function state(string $object): array {
        global $wpdb;
        $revision = Schema::object($object)['_revision'] ?? '';
        if (get_option('acf_vf_app_storage_version') !== '2') { return ['revision' => $revision, 'cursor' => 0, 'complete' => false]; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT schema_revision,value_text FROM ' . Storage::table('index') . ' WHERE object_key=%s AND record_id=0 AND field_key=%s', $object, '__state'), ARRAY_A);
        $state = $row ? json_decode($row['value_text'], true) : null;
        if (!$state || $row['schema_revision'] !== $revision) { return ['revision' => $revision, 'cursor' => 0, 'complete' => false]; }
        return ['revision' => $revision, 'cursor' => (int) ($state['cursor'] ?? 0), 'complete' => !empty($state['complete'])];
    }

    public static function configuration(string $option, $before, $after): void {
        if ($option !== 'acf_vf_objects' || !is_array($after)) { return; }
        Storage::install();
        global $wpdb;
        foreach (array_diff(array_keys((array) $before), array_keys($after)) as $object) {
            if ($wpdb->delete(Storage::table('index'), ['object_key' => $object]) === false) { throw new \RuntimeException('Removed object index could not be cleared.'); }
        }
        foreach ($after as $object => $definition) {
            if (($before[$object]['_revision'] ?? null) === ($definition['_revision'] ?? null) && isset($before[$object])) { continue; }
            if (!isset($before[$object]) && $wpdb->delete(Storage::table('index'), ['object_key' => $object]) === false) { throw new \RuntimeException('New object index could not be reset.'); }
            // Readiness is revision-bound, so old rows are ignored even if scheduling fails.
            $result = self::schedule($object, (string) ($definition['_revision'] ?? ''), 0);
            if (is_wp_error($result)) { throw new \RuntimeException('Index rebuild could not be queued.'); }
        }
    }

    private static function schedule(string $object, string $revision, int $cursor) {
        self::boot();
        Storage::install();
        // A new configuration lifecycle must not reuse a completed job from an older lifecycle.
        // Backfill itself is idempotent under its transaction lock.
        return Jobs::enqueue('index_backfill', ['object' => $object, 'revision' => $revision]);
    }

    public static function job(array $payload) {
        if (!current_user_can('manage_options')) { return new \WP_Error('index_actor', 'Index backfill requires an authorized configuration actor.'); }
        $object = $payload['object'] ?? '';
        if (!is_string($object) || !Schema::object($object)) { return true; }
        if (($payload['revision'] ?? '') !== Schema::object($object)['_revision']) { return true; }
        $result = self::backfill($object, 200);
        if (is_wp_error($result)) { return $result; }
        if (!$result['complete']) { return self::schedule($object, $result['revision'], $result['cursor']); }
        return true;
    }

    public static function backfill(string $object, int $batch_size = 200) {
        if (!current_user_can('manage_options') || !Schema::object($object)) { return new \WP_Error('index_forbidden', 'Index rebuild requires configuration access.', ['status' => 403]); }
        $batch_size = max(1, min(500, $batch_size));
        $transaction = Storage::begin();
        if (is_wp_error($transaction)) { return $transaction; }
        try {
            $current = Schema::assert_current($object);
            if (is_wp_error($current)) { Storage::rollback(); return $current; }
            $lock = Storage::lock('index:' . $object);
            if (is_wp_error($lock)) { Storage::rollback(); return $lock; }
            $state = self::state($object);
            if ($state['complete']) { Storage::commit(); return $state; }
            global $wpdb;
            if (!$state['cursor'] && $wpdb->delete(Storage::table('index'), ['object_key' => $object]) === false) { throw new \RuntimeException('Index reset failed.'); }
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND ID>%d ORDER BY ID ASC LIMIT %d", $object, $state['cursor'], $batch_size));
            if ($wpdb->last_error) { throw new \RuntimeException('Index scan failed.'); }
            foreach ($ids as $id) {
                Records::flush_cache((int) $id);
                $post = get_post((int) $id);
                if (!$post) { continue; }
                $fields = [];
                foreach (Schema::fields($object) as $name => $field) { $fields[$name] = get_field($field['key'], (int) $id, false); }
                self::project(['id' => (int) $id, 'object' => $object, 'title' => $post->post_title, 'status' => $post->post_status, 'version' => (int) get_post_meta($id, '_vf_app_version', true), 'fields' => $fields], $state['revision']);
                $state['cursor'] = (int) $id;
            }
            $state['complete'] = count($ids) < $batch_size;
            self::put($object, 0, '__state', $state['revision'], 'state', wp_json_encode($state), null);
            if (!Storage::commit()) { return self::error(); }
            return $state;
        } catch (\Throwable $error) { Storage::rollback(); return self::error(); }
    }

    public static function persist(array $record, ?array $before = null, array $context = []): void {
        $lock = Storage::lock('index:' . $record['object']);
        if (is_wp_error($lock)) { throw new \RuntimeException('Index lock unavailable.'); }
        self::project($record, Schema::object($record['object'])['_revision']);
    }

    public static function deleted(array $record, array $context = []): void {
        global $wpdb;
        $lock = Storage::lock('index:' . $record['object']);
        if (is_wp_error($lock) || $wpdb->delete(Storage::table('index'), ['object_key' => $record['object'], 'record_id' => $record['id']]) === false) { throw new \RuntimeException('Index deletion failed.'); }
    }

    private static function project(array $record, string $revision): void {
        global $wpdb;
        if ($wpdb->delete(Storage::table('index'), ['object_key' => $record['object'], 'record_id' => $record['id']]) === false) { throw new \RuntimeException('Index update failed.'); }
        $values = array_merge($record['fields'], array_intersect_key($record, array_flip(['id', 'title', 'status', 'version'])));
        foreach (Query::types($record['object']) as $name => $type) {
            if ($type === 'list') { continue; }
            $value = $values[$name] ?? null;
            if ($value === null || $value === '' || $value === false || $value === []) { self::put($record['object'], $record['id'], $name, $revision, 'empty', '', null); }
            elseif ($type === 'number' && is_numeric($value) && is_finite((float) $value)) { self::put($record['object'], $record['id'], $name, $revision, 'number', '', wp_json_encode((float) $value, JSON_PRESERVE_ZERO_FRACTION)); }
            elseif ($type === 'text' && is_scalar($value)) { self::put($record['object'], $record['id'], $name, $revision, 'text', (string) $value, null); }
            else { self::put($record['object'], $record['id'], $name, $revision, 'unsupported', '', null); }
        }
    }

    private static function put(string $object, int $id, string $field, string $revision, string $type, string $text, $number): void {
        global $wpdb;
        $result = $wpdb->replace(Storage::table('index'), ['object_key' => $object, 'record_id' => $id, 'field_key' => $field, 'schema_revision' => $revision, 'value_type' => $type, 'value_text' => $text, 'value_number' => $number, 'value_hash' => hash('sha256', $text)]);
        if ($result === false) { throw new \RuntimeException('Index persistence failed.'); }
    }

    /** Null means use the authoritative scan. IDs are an indexed candidate superset. */
    public static function candidates(string $object, array $filters, array $types, int $limit, array $used = []) {
        if (!$filters || !self::state($object)['complete']) { return null; }
        global $wpdb;
        $clauses = []; $joins = []; $n = 0;
        foreach ($filters as $filter) {
            $type = $types[$filter['field']] ?? '';
            if ($type === 'list' || $filter['operator'] === 'contains') { continue; }
            $args = []; $alias = 'vfi' . $n;
            $predicate = self::predicate($alias, $type, $filter, $args);
            if ($predicate === null) { continue; }
            if ($n) { $joins[] = 'JOIN ' . Storage::table('index') . " $alias ON $alias.object_key=vfi0.object_key AND $alias.record_id=vfi0.record_id"; }
            $clauses[] = $wpdb->prepare("$alias.field_key=%s AND $alias.schema_revision=%s", $filter['field'], Schema::object($object)['_revision']) . ' AND (' . ($args ? $wpdb->prepare($predicate, $args) : $predicate) . ')';
            $n++;
        }
        if (!$n) { return null; }
        $base = 'SELECT vfi0.record_id FROM ' . Storage::table('index') . ' vfi0 JOIN ' . $wpdb->posts . " vfp ON vfp.ID=vfi0.record_id AND vfp.post_type=vfi0.object_key AND vfp.post_status IN ('publish','private','draft','pending') " . implode(' ', $joins) . ' WHERE ' . $wpdb->prepare('vfi0.object_key=%s', $object) . ' AND ' . implode(' AND ', $clauses);
        $ids = []; $cursor = 0;
        do {
            $batch = $wpdb->get_col($base . ' AND vfi0.record_id>' . $cursor . ' ORDER BY vfi0.record_id ASC LIMIT 200');
            if ($wpdb->last_error) { return self::error(); }
            foreach ($batch as $id) {
                $cursor = (int) $id;
                $record = Records::read($cursor);
                if (is_wp_error($record)) { continue; }
                foreach (array_unique(array_merge($used, array_column($filters, 'field'))) as $name) {
                    if (isset(Schema::fields($object)[$name]) && !array_key_exists($name, $record['fields'])) { continue 2; }
                }
                $ids[] = $cursor;
                if (count($ids) > $limit) { return new \WP_Error('query_capacity', 'This query exceeds the configured processing limit.', ['status' => 413]); }
            }
        } while (count($batch) === 200);
        if (!self::state($object)['complete']) { return null; }
        return $ids;
    }

    private static function predicate(string $alias, string $type, array $filter, array &$args): ?string {
        global $wpdb;
        $operator = $filter['operator'];
        if ($operator === 'empty') { return "$alias.value_type='empty'"; }
        if ($operator === 'not_empty') { return "$alias.value_type<>'empty'"; }
        if (!in_array($type, ['text', 'number'], true)) { return null; }
        $column = $type === 'number' ? "$alias.value_number" : "$alias.value_text";
        $value = $filter['value'] ?? null;
        $convert = static function ($v) use ($type) { return $type === 'number' ? wp_json_encode((float) $v, JSON_PRESERVE_ZERO_FRACTION) : (string) $v; };
        $base = "$alias.value_type='$type' AND ";
        if ($operator === 'in') { $args = array_map($convert, $value); return $base . $column . ' IN (' . implode(',', array_fill(0, count($args), '%s')) . ')'; }
        if ($operator === 'between') { $args = [$convert($value[0]), $convert($value[1])]; return $base . "$column BETWEEN %s AND %s"; }
        if ($operator === 'starts_with' && $type === 'text') { $args[] = $wpdb->esc_like($value) . '%'; return $base . "$column LIKE %s"; }
        $operators = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
        if (!isset($operators[$operator])) { return null; }
        if ($operator === 'eq' && $type === 'text') { $args[] = hash('sha256', $value); return $base . "$alias.value_hash=%s"; }
        $args[] = $convert($value);
        $comparison = $base . $column . ' ' . $operators[$operator] . ' %s';
        return $operator === 'ne' ? "$alias.value_type='empty' OR ($comparison)" : $comparison;
    }

    private static function error(): \WP_Error { return new \WP_Error('index_storage', 'The index operation could not be completed.', ['status' => 503]); }
}
