<?php
namespace ACF_VF\App;

/** Optional public APIs only. This service never copies isolated layout leaf metadata. */
final class Localization {
    private static $early = false;
    public static function early_boot(): void {
        if (self::$early) { return; }
        self::$early = true;
        add_filter('pll_get_post_types', [self::class, 'translatable_types'], 20, 2);
    }

    public static function boot(): void {
        self::early_boot();
        add_action('init', [self::class, 'register_strings'], 40);
    }

    /** Explicit object opt-in; keep the multilingual plugin's other configured types. */
    public static function translatable_types(array $types, bool $settings = false): array {
        foreach (Schema::objects() as $object => $definition) {
            if (!empty($definition['localization']['enabled'])) { $types[$object] = $object; }
        }
        return $types;
    }

    public static function provider(): string {
        if (defined('ICL_SITEPRESS_VERSION') && has_filter('wpml_object_id')) { return 'wpml'; }
        if (function_exists('pll_get_post') && function_exists('pll_languages_list')) { return 'polylang'; }
        return '';
    }

    public static function languages(): array {
        if (self::provider() === 'polylang') { return array_values((array) pll_languages_list(['fields' => 'slug'])); }
        if (self::provider() === 'wpml') { return array_keys((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0])); }
        return [];
    }

    private static function target(int $id, string $language): int {
        if (self::provider() === 'polylang') { return (int) pll_get_post($id, $language); }
        if (self::provider() === 'wpml') { return (int) apply_filters('wpml_object_id', $id, get_post_type($id), false, $language); }
        return 0;
    }

    public static function translate_record(int $id, string $language) {
        $source = Records::read($id);
        if (is_wp_error($source)) { return $source; }
        if (!in_array($language, self::languages(), true)) { return new \WP_Error('language', 'Language is unavailable.'); }
        $target = self::target($id, $language);
        if (!$target || get_post_type($target) !== $source['object']) { return new \WP_Error('translation_missing', 'Record translation is unavailable.'); }
        return Records::read($target);
    }

    /** Linking is configuration and requires every linked record to be writable. */
    public static function link_records(array $map) {
        if (!current_user_can('manage_options')) { return new \WP_Error('forbidden', 'Translation linking requires manage_options.'); }
        if (!self::provider()) { return new \WP_Error('localization_missing', 'No supported multilingual provider is active.'); }
        if (count($map) < 2 || count($map) > 100) { return new \WP_Error('translation_group', 'Provide between two and 100 translations.'); }
        $object = null; $seen = [];
        foreach ($map as $language => $id) {
            if (!in_array($language, self::languages(), true) || !is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1 || in_array((int) $id, $seen, true)) { return new \WP_Error('translation_group', 'Translation group is invalid.'); }
            $type = get_post_type((int) $id);
            if (!$type || !Schema::object($type) || ($object && $object !== $type) || !Permissions::can('update', $type, (int) $id)) { return new \WP_Error('translation_permission', 'A translation cannot be linked.'); }
            if (self::provider() === 'polylang' && function_exists('pll_is_translated_post_type') && !pll_is_translated_post_type($type)) { return new \WP_Error('translation_type', 'Enable this object in Polylang before linking translations.'); }
            if (self::provider() === 'wpml' && !apply_filters('wpml_is_translated_post_type', false, $type)) { return new \WP_Error('translation_type', 'Enable this object in WPML before linking translations.'); }
            $object = $type; $seen[] = (int) $id; $map[$language] = (int) $id;
            if (self::provider() === 'wpml') {
                $trid = apply_filters('wpml_element_trid', null, (int) $id, 'post_' . $object);
                $existing = $trid ? apply_filters('wpml_get_element_translations', null, $trid, 'post_' . $object) : [];
                foreach ((array) $existing as $member) {
                    $member_id = is_object($member) ? ($member->element_id ?? 0) : ($member['element_id'] ?? 0);
                    if ($member_id && !in_array((int) $member_id, array_map('intval', array_values($map)), true)) { return new \WP_Error('translation_existing', 'Merge existing translation groups explicitly before linking.'); }
                }
            }
            if (self::provider() === 'polylang' && function_exists('pll_get_post_translations')) {
                $existing = pll_get_post_translations((int) $id);
                foreach ($existing as $existing_id) {
                    if (!in_array((int) $existing_id, array_map('intval', array_values($map)), true)) { return new \WP_Error('translation_existing', 'Merge existing translation groups explicitly before linking.'); }
                }
            }
        }
        if (self::provider() === 'polylang') {
            if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) { return new \WP_Error('localization_api', 'Translation write API is unavailable.'); }
            foreach ($map as $language => $id) { pll_set_post_language($id, $language); }
            pll_save_post_translations($map);
        } else {
            if (!has_action('wpml_set_element_language_details')) { return new \WP_Error('localization_api', 'Translation write API is unavailable.'); }
            $first = true; $trid = false; $source = null;
            foreach ($map as $language => $id) {
                do_action('wpml_set_element_language_details', ['element_id' => $id, 'element_type' => 'post_' . $object, 'trid' => $trid, 'language_code' => $language, 'source_language_code' => $source, 'check_duplicates' => true]);
                if ($first) { $trid = apply_filters('wpml_element_trid', null, $id, 'post_' . $object); $source = $language; $first = false; }
                if (!$trid) { return new \WP_Error('translation_group', 'Provider did not create a translation group.'); }
            }
        }
        foreach ($map as $language => $id) {
            if (self::target((int) reset($map), $language) !== $id) { return new \WP_Error('translation_verify', 'Provider did not confirm the requested translation group.'); }
        }
        return $map;
    }

    /** Translate declared relationships while preserving nested container structure. Caller saves through Records. */
    public static function translate_values(string $object, array $values, string $language) {
        if (!Permissions::can('create', $object) || !in_array($language, self::languages(), true)) { return new \WP_Error('translation_permission', 'Translation values are unavailable.'); }
        $budget = 5000;
        return self::walk(Schema::fields($object), $values, $language, 0, $budget);
    }

    private static function walk(array $definitions, array $values, string $language, int $depth, int &$budget) {
        $budget -= count($values);
        if ($depth > 10 || count($values) > 1000 || $budget < 0) { return new \WP_Error('translation_limit', 'Translation nesting limit exceeded.'); }
        foreach ($definitions as $name => $field) {
            $name = $field['name'] ?? $name;
            $key = array_key_exists($name, $values) ? $name : ($field['key'] ?? $name);
            if (!array_key_exists($key, $values)) { continue; }
            $native = ($field['type'] ?? '') === 'validated_field' ? array_replace($field, $field['sub_field'] ?? []) : $field;
            $type = $native['type'] ?? '';
            if ($type === 'group' && is_array($values[$key])) { $values[$key] = self::walk($native['sub_fields'] ?? [], $values[$key], $language, $depth + 1, $budget); }
            elseif (in_array($type, ['repeater', 'flexible_content'], true) && is_array($values[$key])) {
                foreach ($values[$key] as &$row) {
                    if (!is_array($row)) { return new \WP_Error('translation_shape', 'Invalid nested field structure.'); }
                    $children = $native['sub_fields'] ?? [];
                    if ($type === 'flexible_content') {
                        $children = null;
                        foreach (($native['layouts'] ?? []) as $layout) { if (($layout['name'] ?? '') === ($row['acf_fc_layout'] ?? '')) { $children = $layout['sub_fields'] ?? []; break; } }
                        if ($children === null) { return new \WP_Error('translation_layout', 'Unknown flexible layout.'); }
                    }
                    $row = self::walk($children, $row, $language, $depth + 1, $budget);
                    if (is_wp_error($row)) { return $row; }
                }
                unset($row);
            } elseif (!empty($field['relationship']) || in_array($type, ['post_object', 'relationship'], true)) {
                $ids = Relationships::ids($values[$key]);
                if (is_wp_error($ids)) { return $ids; }
                $translated = [];
                foreach ($ids as $id) {
                    $target = self::target($id, $language);
                    if (!$target || get_post_type($target) !== get_post_type($id)) { return new \WP_Error('translation_reference', 'A related translation is unavailable.'); }
                    $target_object = get_post_type($target);
                    if (!Schema::object($target_object) || !Permissions::can('read', $target_object, $id) || !Permissions::can('read', $target_object, $target)) { return new \WP_Error('translation_reference', 'A related translation is unavailable.'); }
                    $translated[] = $target;
                }
                $values[$key] = is_array($values[$key]) ? $translated : ($translated[0] ?? null);
            }
            if (is_wp_error($values[$key])) { return $values[$key]; }
        }
        return $values;
    }

    private static function text(string $key, string $text): string {
        if (self::provider() === 'polylang' && function_exists('pll__')) { return pll__($text); }
        if (self::provider() === 'wpml') { return (string) apply_filters('wpml_translate_single_string', $text, 'ACF VF applications', $key); }
        return $text;
    }

    /** Labels/options only. Stored choice keys and record values remain stable. */
    public static function definition(array $definition): array {
        foreach (['label', 'singular'] as $key) { if (isset($definition[$key])) { $definition[$key] = self::text(($definition['slug'] ?? '') . ':' . $key, $definition[$key]); } }
        foreach (($definition['fields'] ?? []) as $name => &$field) {
            if (isset($field['label'])) { $field['label'] = self::text(($definition['slug'] ?? '') . ':' . $name, $field['label']); }
            foreach (($field['choices'] ?? []) as $key => $label) { if (is_string($label)) { $field['choices'][$key] = self::text(($definition['slug'] ?? '') . ':' . $name . ':' . $key, $label); } }
            $definition['fields'][$name] = $field;
        }
        unset($field);
        return $definition;
    }

    public static function register_strings(): void {
        if (!is_admin() || !current_user_can('manage_options') || !self::provider()) { return; }
        foreach (Schema::objects() as $object => $definition) {
            $strings = [$object . ':label' => $definition['label'] ?? $object, $object . ':singular' => $definition['singular'] ?? $object];
            foreach (($definition['fields'] ?? []) as $name => $field) {
                $strings[$object . ':' . $name] = $field['label'] ?? $name;
                foreach (($field['choices'] ?? []) as $key => $label) { if (is_string($label)) { $strings[$object . ':' . $name . ':' . $key] = $label; } }
            }
            foreach ($strings as $key => $text) {
                if (self::provider() === 'polylang' && function_exists('pll_register_string')) { pll_register_string($key, $text, 'ACF VF applications'); }
                elseif (self::provider() === 'wpml') { do_action('wpml_register_single_string', 'ACF VF applications', $key, $text); }
            }
        }
    }
}
