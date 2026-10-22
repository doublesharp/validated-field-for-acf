<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** InnoDB transactions, savepoints and post-commit application events. */
final class Storage {
    private static $depth = 0;
    private static $callbacks = [];
    private static $marks = [];
    private static $touched = [];
    private static $locks = [];

    public static function table(string $name): string {
        global $wpdb;
        if (!in_array($name, ['audit', 'jobs', 'unique', 'index'], true)) { throw new \InvalidArgumentException('Unknown platform table.'); }
        return $wpdb->prefix . 'vf_app_' . $name;
    }

    public static function install(): void {
        if (get_option('acf_vf_app_storage_version') === '2') { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $audit = self::table('audit'); $jobs = self::table('jobs'); $unique = self::table('unique');
        dbDelta("CREATE TABLE $audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            record_id bigint(20) unsigned NOT NULL,
            object_key varchar(20) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            source varchar(40) NOT NULL,
            event varchar(40) NOT NULL,
            before_data longtext NOT NULL,
            after_data longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY record_id (record_id)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(40) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL,
            attempts int NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            locked_at datetime NULL,
            completed_at datetime NULL,
            last_error text NOT NULL,
            idempotency_key varchar(191) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY due_jobs (status,available_at)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $unique (
            key_hash char(64) NOT NULL,
            object_key varchar(20) NOT NULL,
            record_id bigint(20) unsigned NOT NULL,
            field_key varchar(191) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (key_hash),
            KEY record_id (record_id)
        ) ENGINE=InnoDB $charset;");
        $index = self::table('index');
        dbDelta("CREATE TABLE $index (
            object_key varchar(20) NOT NULL,
            record_id bigint(20) unsigned NOT NULL,
            field_key varchar(191) NOT NULL,
            schema_revision char(64) NOT NULL,
            value_type varchar(12) NOT NULL,
            value_text longblob NOT NULL,
            value_number double NULL,
            value_hash char(64) NOT NULL,
            PRIMARY KEY  (object_key,record_id,field_key),
            KEY text_value (object_key,field_key,value_hash,record_id),
            KEY number_value (object_key,field_key,value_number,record_id),
            KEY text_prefix (object_key,field_key,value_text(191),record_id)
        ) ENGINE=InnoDB $charset;");
        foreach ([$audit, $jobs, $unique, $index] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { throw new \RuntimeException('Platform storage installation failed.'); }
        }
        update_option('acf_vf_app_storage_version', '2', false);
    }

    /**
     * Begin an InnoDB transaction, or a savepoint when nested; pair with commit()/rollback().
     *
     * @return true|\WP_Error Transaction readiness, including required table engines and locks.
     */
    public static function begin() {
        global $wpdb;
        if (!self::$depth) {
            self::install();
            foreach ([$wpdb->options, $wpdb->posts, $wpdb->postmeta, self::table('unique'), self::table('audit'), self::table('jobs'), self::table('index')] as $table) {
                $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
                if (strtoupper((string) $engine) !== 'INNODB') { return new \WP_Error('storage_engine', 'Transactional InnoDB storage is required.', ['status' => 503]); }
            }
        }
        if (!self::$depth && $wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false) { return new \WP_Error('storage_isolation', 'Read-committed transactions are required.', ['status' => 503]); }
        $sql = self::$depth ? 'SAVEPOINT vfa_' . self::$depth : 'START TRANSACTION';
        if ($wpdb->query($sql) === false) { return new \WP_Error('storage_transaction', 'Could not start transaction.', ['status' => 503]); }
        self::$marks[] = count(self::$callbacks);
        self::$depth++;
        if (self::$depth === 1) {
            $locked = self::lock('configuration');
            if (is_wp_error($locked)) { self::rollback(); return $locked; }
        }
        return true;
    }

    public static function in_transaction(): bool { return self::$depth > 0; }

    public static function touch(int $id): void { self::$touched[$id] = true; }

    public static function lock(string $name) {
        global $wpdb;
        $name = 'vfa_' . substr(hash('sha256', $wpdb->prefix . $name), 0, 56);
        if (isset(self::$locks[$name])) { return true; }
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) !== 1) {
            return new \WP_Error('record_busy', 'Record is being changed; retry the request.', ['status' => 409]);
        }
        self::$locks[$name] = true;
        return true;
    }

    /** Defer side effects until the outer commit; runs immediately when no transaction is open. */
    public static function after_commit(callable $callback): void {
        if (self::$depth) { self::$callbacks[] = $callback; }
        else { $callback(); }
    }

    public static function commit(): bool {
        global $wpdb;
        if (!self::$depth) { return false; }
        $depth = self::$depth - 1;
        if ($wpdb->query($depth ? 'RELEASE SAVEPOINT vfa_' . $depth : 'COMMIT') === false) { self::rollback(); return false; }
        self::$depth = $depth;
        array_pop(self::$marks);
        if (!self::$depth) {
            $callbacks = self::$callbacks;
            self::$callbacks = [];
            self::finish();
            // Post-commit hooks may launch fresh transactions. Their failures cannot undo this commit.
            foreach ($callbacks as $callback) {
                try { $callback(); }
                catch (\Throwable $error) {
                    // The database commit already succeeded; do not misreport it as a failed write.
                    // Durable audit/outbox work belongs to the precommit persisted hooks.
                    try { do_action('acf_vf/app/operation_error', new \WP_Error('after_commit', 'A postcommit handler failed.'), 'after_commit', 0); } catch (\Throwable $ignored) { error_log('ACF application postcommit handler failed.'); }
                }
            }
        }
        return true;
    }

    public static function rollback(): void {
        global $wpdb;
        if (!self::$depth) { return; }
        self::$depth--;
        $wpdb->query(self::$depth ? 'ROLLBACK TO SAVEPOINT vfa_' . self::$depth : 'ROLLBACK');
        $mark = array_pop(self::$marks);
        self::$callbacks = array_slice(self::$callbacks, 0, $mark);
        foreach (self::$touched as $id => $_) { Records::flush_cache((int) $id); }
        if (!self::$depth) { self::finish(); }
    }

    private static function finish(): void {
        global $wpdb;
        foreach (self::$locks as $name => $_) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
        self::$locks = []; self::$touched = [];
    }
}
