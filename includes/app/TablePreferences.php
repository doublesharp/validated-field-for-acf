<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Bounded preferences belong to the authenticated user; shared columns stay unchanged. */
final class TablePreferences {
    public static function get(string $object) {
        $error = Lists::access($object);
        if ($error) { return $error; }
        $stored = get_user_meta(get_current_user_id(), self::key($object), true);
        if (!is_array($stored) || !is_array($stored['columns'] ?? null)) { return ['columns'=>Lists::columns($object), 'sticky_header'=>true]; }
        // Removed fields and newly restricted columns are omitted on every read.
        $columns = [];
        foreach (array_slice($stored['columns'], 0, 50) as $column) {
            if (!is_array($column)) { continue; }
            $normalized = self::normalize($object, [$column]);
            if (!is_wp_error($normalized)) { $columns[] = $normalized[0]; }
        }
        return ['columns'=>$columns ?: Lists::columns($object), 'sticky_header'=>($stored['sticky_header'] ?? true) === true];
    }

    public static function save(string $object, array $preferences) {
        $error = Lists::access($object);
        if ($error) { return $error; }
        if (array_diff(array_keys($preferences), ['columns','sticky_header']) || !is_array($preferences['columns'] ?? null) || !$preferences['columns'] || (array_key_exists('sticky_header', $preferences) && !is_bool($preferences['sticky_header']))) { return Lists::error('table_preferences', 'Provide columns and an optional boolean sticky_header.'); }
        foreach ($preferences['columns'] as $column) {
            if (!is_array($column) || array_diff(array_keys($column), ['field','width'])) { return Lists::error('table_preferences', 'Personal columns accept only field and width.'); }
        }
        $columns = self::normalize($object, $preferences['columns']);
        if (is_wp_error($columns)) { return $columns; }
        $stored = ['columns'=>array_map(static function ($column) { return array_intersect_key($column, array_flip(['field','width'])); }, $columns), 'sticky_header'=>$preferences['sticky_header'] ?? true];
        $key = self::key($object);
        if (get_user_meta(get_current_user_id(), $key, true) !== $stored && update_user_meta(get_current_user_id(), $key, $stored) === false) { return Lists::error('table_preferences_write', 'Column preferences could not be saved.', 500); }
        return ['columns'=>$columns, 'sticky_header'=>$stored['sticky_header']];
    }

    private static function key(string $object): string { return 'acf_vf_table_'.$object; }
    private static function normalize(string $object, array $columns) {
        $defaults = Lists::columns($object);
        if (is_wp_error($defaults)) { return $defaults; }
        $defaults = array_column($defaults, null, 'field');
        return Lists::normalize_columns($object, array_map(static function ($column) use ($defaults) {
            return is_array($column) && is_string($column['field'] ?? null) ? array_replace(array_intersect_key($defaults[$column['field']] ?? [], array_flip(['field','label','width','formatter'])), $column) : $column;
        }, $columns));
    }
}
