<?php
namespace ACF_VF\App;

/** Privileged, dynamically field-filtered history; restores use the normal write path. */
final class Audit {
    public static function boot(): void {
        add_action('acf_vf/app/record_persisted', [self::class, 'saved'], 10, 3);
        add_action('acf_vf/app/record_delete_persisted', [self::class, 'deleted'], 10, 2);
    }

    public static function saved(array $record, ?array $before = null, array $context = []): void { self::append($before ? 'update' : 'create', $record, $before, $context); }
    public static function deleted(array $record, array $context = []): void { self::append('delete', null, $record, $context); }

    private static function append(string $event, ?array $after, ?array $before, array $context): void {
        global $wpdb;
        $record = $after ?? $before;
        $previous_errors = $wpdb->suppress_errors(true);
        try { $result = $wpdb->insert(Storage::table('audit'), ['record_id' => $record['id'], 'object_key' => $record['object'], 'actor_id' => get_current_user_id(), 'source' => substr(sanitize_key($context['source'] ?? 'records'), 0, 40), 'event' => $event, 'before_data' => $before ? wp_json_encode($before) : 'null', 'after_data' => $after ? wp_json_encode($after) : 'null', 'created_at' => gmdate('Y-m-d H:i:s')]); }
        finally { $wpdb->suppress_errors($previous_errors); }
        if (!$result) { throw new \RuntimeException('Audit event could not be recorded.'); }
    }

    public static function history(int $id, array $args = []) {
        global $wpdb;
        $table = Storage::table('audit');
        $object = get_post_type($id);
        $permission_id = $id;
        if (!$object) {
            $object = $wpdb->get_var($wpdb->prepare("SELECT object_key FROM $table WHERE record_id=%d ORDER BY id DESC LIMIT 1", $id));
            $permission_id = 0;
            if (!$object || !self::deleted_access($object)) { return new \WP_Error('forbidden', 'Audit history is unavailable.'); }
        }
        if (!current_user_can('manage_options') || !Permissions::can('read', $object, $permission_id)) { return new \WP_Error('forbidden', 'Audit history is unavailable.'); }
        $limit = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE record_id=%d ORDER BY id DESC LIMIT %d OFFSET %d", $id, $limit, ($page - 1) * $limit), ARRAY_A);
        foreach ($rows as &$row) {
            foreach (['before_data', 'after_data'] as $side) {
                $state = json_decode($row[$side] ?? '', true);
                if (is_array($state)) { $state['fields'] = Permissions::readable_fields($object, $permission_id, $state['fields'] ?? []); }
                $row[$side] = $state;
            }
        }
        unset($row);
        return ['items' => $rows, 'total' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE record_id=%d", $id)), 'page' => $page, 'per_page' => $limit];
    }

    public static function deleted_access(string $object): bool {
        $definition = Schema::object($object);
        $type = get_post_type_object($object);
        return current_user_can('manage_options') && $definition && $type && (($definition['access']['read']['scope'] ?? 'all') === 'all') && current_user_can($type->cap->read_private_posts) && Permissions::can('read', $object);
    }


}
