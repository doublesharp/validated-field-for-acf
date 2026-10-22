<?php
namespace ACF_VF\App;

/** Explicit record references. Junction attributes live on ordinary junction records. */
final class Relationships {
    public static function boot(): void {
        add_filter('acf_vf/app/validate_fields', [self::class, 'validate'], 20, 5);
        add_filter('acf_vf/app/can_delete', [self::class, 'can_delete'], 20, 3);
    }

    /** Normalize native ACF ID values, rejecting silently truncated or malformed references. */
    public static function ids($value) {
        if ($value === null || $value === '' || $value === false || $value === []) { return []; }
        $values = is_array($value) ? $value : [$value];
        if (count($values) > 500) { return new \WP_Error('relationship_limit', 'At most 500 references are allowed.'); }
        $ids = [];
        foreach ($values as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) { return new \WP_Error('relationship_id', 'References must be positive record IDs.'); }
            if ((int) $id < 1) { return new \WP_Error('relationship_id', 'References must be positive record IDs.'); }
            $ids[] = (int) $id;
        }
        return array_values(array_unique($ids));
    }

    public static function validate(array $errors, string $object, array $values, int $id, array $context): array {
        foreach (Schema::fields($object) as $name => $field) {
            $rel = $field['relationship'] ?? null;
            if (!$rel) { continue; }
            $native = ($field['type'] ?? '') === 'validated_field' ? ($field['sub_field'] ?? []) : $field;
            $ids = self::ids($values[$name] ?? null);
            $message = '';
            if (!is_array($rel) || !Schema::object($rel['object'] ?? '') || !in_array($rel['kind'] ?? 'lookup', ['lookup', 'parent', 'junction'], true) || !in_array($rel['on_delete'] ?? 'restrict', ['restrict', 'nullify'], true)) {
                $message = 'Relationship configuration is invalid.';
            } elseif (is_wp_error($ids)) { $message = $ids->get_error_message(); }
            elseif ((!empty($rel['required']) || !empty($field['required'])) && !$ids) { $message = 'A related record is required.'; }
            elseif ((($rel['kind'] ?? '') === 'parent' || (($native['type'] ?? '') === 'post_object' && empty($native['multiple']))) && count($ids) > 1) { $message = 'This field accepts one related record.'; }
            else {
                $lock_ids = $ids;
                if ($id && Storage::in_transaction()) {
                    $previous_ids = self::ids(get_post_meta($id, $name, true));
                    if (!is_wp_error($previous_ids)) { $lock_ids = array_values(array_unique(array_merge($lock_ids, $previous_ids))); }
                }
                sort($lock_ids, SORT_NUMERIC);
                foreach ($lock_ids as $target) {
                    if (Storage::in_transaction()) {
                        $lock = Storage::lock('record:' . $target);
                        if (is_wp_error($lock)) { $message = 'A related record is busy. Retry the operation.'; break; }
                        clean_post_cache($target);
                    }
                    if (!in_array($target, $ids, true)) { continue; }
                    if (get_post_type($target) !== $rel['object'] || get_post_status($target) === 'trash' || (!Records::derived_unchanged($object, $id, $name, $values[$name] ?? null, $context) && !Permissions::can('read', $rel['object'], $target))) {
                        $message = 'A related record is unavailable.'; break;
                    }
                }
            }
            if ($message) { $errors[] = ['field' => $name, 'message' => $message, 'code' => 'relationship']; }
        }
        return $errors;
    }

    /** Internal reference discovery includes unreadable rows so restrict cannot be bypassed. */
    public static function incoming(int $target, string $target_object): array {
        $references = [];
        foreach (Schema::objects() as $object => $definition) {
            foreach (Schema::fields($object) as $name => $field) {
                $rel = $field['relationship'] ?? [];
                if (($rel['object'] ?? '') !== $target_object) { continue; }
                $page = 1;
                do {
                    $ids = get_posts(['post_type' => $object, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 100, 'paged' => $page++, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true]);
                    foreach ($ids as $id) {
                        $value = get_post_meta($id, $name, true);
                        $targets = self::ids($value);
                        if (!is_wp_error($targets) && in_array($target, $targets, true)) {
                            $references[] = ['id' => (int) $id, 'object' => $object, 'field' => $name, 'value' => $value, 'config' => $rel, 'targets' => $targets];
                        }
                    }
                } while (count($ids) === 100);
            }
        }
        return $references;
    }

    public static function can_delete($allowed, array $record, array $context) {
        if (is_wp_error($allowed) || !$allowed) { return $allowed; }
        $outgoing = [];
        foreach (Schema::fields($record['object']) as $name => $field) {
            if (empty($field['relationship'])) { continue; }
            $ids = self::ids($record['fields'][$name] ?? []);
            if (!is_wp_error($ids)) { $outgoing = array_merge($outgoing, $ids); }
        }
        $outgoing = array_unique($outgoing); sort($outgoing, SORT_NUMERIC);
        foreach ($outgoing as $target) {
            if (Storage::in_transaction()) { $lock = Storage::lock('record:' . $target); if (is_wp_error($lock)) { return $lock; } }
        }
        $changes = [];
        foreach (self::incoming((int) $record['id'], $record['object']) as $ref) {
            if ($ref['id'] === (int) $record['id']) { continue; }
            if (($ref['config']['on_delete'] ?? 'restrict') !== 'nullify') { return new \WP_Error('relationship_restrict', 'This record is referenced and cannot be deleted.'); }
            if (!Permissions::can('update', $ref['object'], $ref['id'], $ref['field'])) { return new \WP_Error('relationship_permission', 'A dependent record cannot be updated.'); }
            $remaining = array_values(array_diff($ref['targets'], [(int) $record['id']]));
            $changes[$ref['id']][$ref['field']] = is_array($ref['value']) ? $remaining : ($remaining[0] ?? null);
        }
        foreach ($changes as $id => $fields) {
            $current = Records::read((int) $id);
            if (is_wp_error($current)) { return $current; }
            $valid = Records::validate($current['object'], $fields, (int) $id, $context);
            if (is_wp_error($valid)) { return $valid; }
        }
        foreach ($changes as $id => $fields) {
            $current = Records::read((int) $id);
            if (is_wp_error($current)) { return $current; }
            $saved = Records::save(get_post_type($id), ['fields' => $fields, 'version' => $current['version']], (int) $id, array_merge($context, ['source' => 'relationship_nullify']));
            if (is_wp_error($saved)) { return $saved; }
        }
        return true;
    }
}
