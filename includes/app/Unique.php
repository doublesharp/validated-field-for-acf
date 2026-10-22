<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Database primary keys arbitrate concurrent claims. Values never enter error messages. */
final class Unique {
    private static function empty_component($value): bool { return $value === null || $value === '' || $value === []; }

    private static function normalize($value, array $field) {
        if (($field['type'] ?? '') === 'validated_field') { $field = array_replace($field, ['type' => 'text'], $field['sub_field'] ?? []); }
        if (($field['type'] ?? '') === 'email' && is_string($value)) { return strtolower(trim($value)); }
        if (($field['type'] ?? '') === 'true_false') { return $value ? '1' : '0'; }
        if (in_array($field['type'] ?? '', ['number', 'range', 'user', 'post_object', 'image', 'file'], true) && is_numeric($value)) { return (string) (0 + $value); }
        return is_scalar($value) ? (string) $value : $value;
    }

    private static function keys(string $object, array $values): array {
        $keys = []; $definitions = Schema::fields($object);
        foreach ($definitions as $name => $field) {
            if (empty($field['unique']) || !array_key_exists($name, $values) || self::empty_component($values[$name]) || ($field['type'] ?? '') === 'validated_field') { continue; }
            $value = self::normalize($values[$name], $field);
            // Preserve existing single-field claim identities.
            $keys[hash('sha256', $object . "\0" . $name . "\0" . wp_json_encode($value))] = $name;
        }
        foreach (Schema::object($object)['unique_constraints'] ?? [] as $constraint) {
            $tuple = [];
            foreach ($constraint['fields'] as $name) {
                if (!array_key_exists($name, $values) || self::empty_component($values[$name])) { continue 2; }
                $value = self::normalize($values[$name], $definitions[$name]);
                if (self::empty_component($value)) { continue 2; }
                $tuple[] = [$name, $value];
            }
            $key = '@' . $constraint['name'];
            $keys[hash('sha256', $object . "\0constraint\0" . $constraint['name'] . "\0" . wp_json_encode($tuple))] = $key;
        }
        return $keys;
    }

    private static function components(string $object, string $key): array {
        if (strpos($key, '@') !== 0) { return [$key]; }
        foreach (Schema::object($object)['unique_constraints'] ?? [] as $constraint) { if ('@' . $constraint['name'] === $key) { return $constraint['fields']; } }
        return [];
    }

    public static function check(string $object, array $values, int $id = 0) {
        global $wpdb;
        $keys = self::keys($object, $values);
        if (!$keys) { return true; }
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND ID <> %d", $object, $id));
        if ($wpdb->last_error) { return self::storage_error(); }
        foreach ($keys as $hash => $name) {
            $owner = $wpdb->get_var($wpdb->prepare('SELECT record_id FROM ' . self::table() . ' WHERE key_hash = %s', $hash));
            if ($wpdb->last_error) { return self::storage_error(); }
            if ($owner !== null && (int) $owner !== $id) { return self::duplicate($object, $name, $id); }
            // Existing native records may predate either single-field or compound constraints.
            foreach ($ids as $other_id) {
                Records::flush_cache((int) $other_id);
                $existing = [];
                foreach (self::components($object, $name) as $component) { $existing[$component] = get_field(Schema::fields($object)[$component]['key'], (int) $other_id, false); }
                if (isset(self::keys($object, $existing)[$hash])) { return self::duplicate($object, $name, $id); }
            }
        }
        return true;
    }

    public static function claim(string $object, array $values, int $id = 0) {
        global $wpdb;
        $check = self::check($object, $values, $id);
        if (is_wp_error($check)) { return $check; }
        $keys = self::keys($object, $values);
        // Stable order avoids deadlocks when two requests claim several values.
        ksort($keys);
        foreach ($keys as $hash => $name) {
            $previous = $wpdb->suppress_errors(true);
            $result = $wpdb->query($wpdb->prepare('INSERT INTO ' . self::table() . ' (key_hash,object_key,record_id,field_key,created_at) VALUES (%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE key_hash=key_hash', $hash, $object, $id, $name, current_time('mysql', true)));
            $wpdb->suppress_errors($previous);
            if ($result === false) { return new \WP_Error('unique_storage', 'Unable to reserve unique value.', ['status' => 503]); }
            $owner = $wpdb->get_var($wpdb->prepare('SELECT record_id FROM ' . self::table() . ' WHERE key_hash = %s FOR UPDATE', $hash));
            // A new record temporarily claims with ID zero inside its uncommitted transaction.
            if ((int) $owner !== $id || (!$id && $result === 0)) { return self::duplicate($object, $name, $id); }
        }
        return array_keys($keys);
    }

    public static function finish(string $object, array $hashes, int $id) {
        global $wpdb;
        foreach ($hashes as $hash) { if ($wpdb->update(self::table(), ['record_id' => $id], ['key_hash' => $hash, 'object_key' => $object]) === false) { return self::storage_error(); } }
        $rows = $wpdb->get_col($wpdb->prepare('SELECT key_hash FROM ' . self::table() . ' WHERE record_id = %d AND object_key = %s', $id, $object));
        if ($wpdb->last_error) { return self::storage_error(); }
        foreach (array_diff($rows, $hashes) as $hash) { if ($wpdb->delete(self::table(), ['key_hash' => $hash, 'record_id' => $id]) === false) { return self::storage_error(); } }
        return true;
    }

    /** Schema unlink owns the transaction; native edits may follow after it commits. */
    public static function release_object(string $object) {
        if (!Storage::in_transaction() || !current_user_can('manage_options')) { return self::storage_error(); }
        global $wpdb;
        return $wpdb->delete(self::table(), ['object_key' => $object]) === false ? self::storage_error() : true;
    }

    public static function release(int $id) { global $wpdb; return $wpdb->delete(self::table(), ['record_id' => $id]) === false ? self::storage_error() : true; }
    private static function storage_error(): \WP_Error { return new \WP_Error('unique_storage', 'Unique constraints could not be persisted.', ['status' => 503]); }
    private static function table(): string { return Storage::table('unique'); }
    private static function duplicate(string $object, string $key, int $id): \WP_Error {
        $components = self::components($object, $key); $field = $components[0] ?? '';
        foreach ($components as $name) { if (!Permissions::can('read', $object, $id, $name)) { $field = ''; break; } }
        return new \WP_Error('unique', 'The record does not satisfy its unique constraints.', ['status' => 422, 'errors' => [['field' => $field, 'code' => 'unique', 'message' => $field === '' ? 'The record does not satisfy its validation rules.' : 'This value or combination is already in use.']]]);
    }
}
