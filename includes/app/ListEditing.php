<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Table writes share exactly the record form's validation, ACL, audit and version checks. */
final class ListEditing {
    public static function supports(array $definition): bool {
        $field = in_array($definition['type'] ?? '', ['validated_field','validated'], true) ? array_replace($definition, $definition['sub_field'] ?? []) : $definition;
        return Lists::scalar_field($definition) && !in_array($field['type'] ?? '', ['select','radio','button_group'], true);
    }

    public static function inline_update(int $id, string $field, mixed $value, int $version) {
        if ($version < 0) { return Lists::error('version_required', 'A nonnegative record version is required.', 409); }
        $record = Records::read($id);
        if (is_wp_error($record)) { return $record; }
        $definition = Schema::fields($record['object'])[$field] ?? null;
        if (!$definition) { return Lists::error('lists_inline_type', 'Unknown inline field.'); }
        if (!Permissions::can('update', $record['object'], $id, $field)) { return Lists::error('lists_forbidden', 'Field update is unavailable.', 403); }
        if (!self::supports($definition)) {
            $missing = Extensions::require_feature('lists.inline');
            return apply_filters('acf_vf/app/inline_update', is_wp_error($missing) ? $missing : Lists::error('lists_inline_type', 'The installed provider cannot edit this field.'), $id, $field, $value, $version);
        }
        if (!is_scalar($value) && $value !== null) { return Lists::error('lists_inline_type', 'This field requires a scalar value.'); }
        return Records::save($record['object'], ['version'=>$version,'fields'=>[$field=>$value]], $id, ['source'=>'lists_inline']);
    }

    public static function quick_add(string $object, array $payload) {
        if (array_diff(array_keys($payload), ['title','status','fields'])) { return Lists::error('lists_payload', 'Quick Add accepts title, status and fields.'); }
        return Records::save($object, $payload, 0, ['source'=>'lists_quick_add']);
    }
}
