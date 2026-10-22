<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Configured objects retain their native WordPress/ACF representation. */
final class Schema {
    private static $registered = [];

    public static function early_boot(): void {
        add_filter('register_post_type_args', [self::class, 'private_registration'], PHP_INT_MAX, 2);
        add_action('registered_post_type', [self::class, 'registered_type'], PHP_INT_MAX, 2);
    }

    public static function private_registration(array $args, string $slug): array {
        if (!self::managed_object($slug)) { return $args; }
        foreach (['public','publicly_queryable','show_in_rest','show_in_graphql','query_var','rewrite'] as $flag) { $args[$flag] = false; }
        $args['exclude_from_search'] = true;
        return $args;
    }

    public static function registered_type(string $slug, $type): void {
        $definition = self::managed_object($slug);
        if (!$definition) { return; }
        foreach (self::private_registration([], $slug) as $flag => $value) { $type->$flag = $value; }
        if (did_action('acf/init') && function_exists('acf_add_local_field_group')) { self::register_fields($slug, Localization::definition($definition)); }
    }

    public static function boot(): void {
        if (did_action('init')) { self::register(); }
        else { add_action('init', [__CLASS__, 'register'], 20); }
    }

    public static function objects(): array {
        $objects = get_option('acf_vf_objects', []);
        if (!is_array($objects)) { return []; }
        foreach ($objects as &$definition) { if (is_array($definition) && !isset($definition['_revision'])) { $definition['_revision'] = self::revision($definition); } }
        unset($definition);
        return $objects;
    }

    public static function object(string $slug): ?array {
        $objects = self::objects();
        return isset($objects[$slug]) && is_array($objects[$slug]) ? $objects[$slug] : NativeTables::definition($slug);
    }

    /** Managed objects alone own registration and native access guards. */
    public static function managed_object(string $slug): ?array { $objects=self::objects(); return isset($objects[$slug]) && is_array($objects[$slug])?$objects[$slug]:null; }
    public static function table_objects(): array { return self::objects()+NativeTables::objects(); }

    public static function fields(string $slug): array {
        return self::object($slug)['fields'] ?? [];
    }

    public static function save_object(array $definition, bool $dry_run = false) {
        if (!current_user_can('manage_options')) { return self::error('forbidden', 'Configuration requires manage_options.', 403); }
        $previous = self::object(is_string($definition['slug'] ?? null) ? $definition['slug'] : '');
        if ($previous && is_string($definition['_revision'] ?? null) && hash_equals($previous['_revision'], $definition['_revision'])) {
            $definition = self::restore_editor_secrets($definition, $previous);
            foreach (['layout','record_types'] as $presentation) {
                if (!array_key_exists($presentation,$definition) && array_key_exists($presentation,$previous)) { $definition[$presentation]=$previous[$presentation]; }
            }
        }
        // Every authoring route shares the REST/import setting allowlist.
        $shape = Migrations::definition($definition);
        if (is_wp_error($shape)) { return $shape; }
        $requirements = Requirements::validate_definition($definition, $previous);
        if (is_wp_error($requirements)) { return $requirements; }
        $extension = apply_filters('acf_vf/app/validate_definition', true, $definition, $previous);
        if (is_wp_error($extension)) { return $extension; }
        if ($extension !== true) { return self::error('schema_extension','Configuration validation failed.',500); }
        if (!empty($definition['public'])) { return self::error('schema_public', 'Managed application objects require authenticated access; public registration is unavailable.'); }
        foreach (['label', 'singular'] as $setting) {
            if (isset($definition[$setting]) && !is_string($definition[$setting])) { return self::error('schema_label', 'Object labels must be strings.'); }
        }
        foreach (['rules', 'relationships', 'layout', 'record_types'] as $setting) {
            if (isset($definition[$setting]) && (!is_array($definition[$setting]) || count($definition[$setting]) > 1000)) { return self::error('schema_structure', 'Object settings must use bounded arrays.'); }
        }
        $slug = $definition['slug'] ?? '';
        $reserved = ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'user', 'option'];
        if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $slug) || strpos($slug, 'acf') === 0 || strpos($slug, 'wp_') === 0 || in_array($slug, $reserved, true)) {
            return self::error('schema_slug', 'Use an unreserved object slug of at most 20 lowercase letters, digits or underscores.');
        }
        if (post_type_exists($slug) && !isset(self::$registered[$slug]) && empty($definition['existing'])) {
            return self::error('schema_exists', 'Linking a registered post type requires existing: true.');
        }
        if (!isset($definition['fields'])) { $definition['fields'] = []; }
        if (!is_array($definition['fields'])) { return self::error('schema_fields', 'Fields must be an object keyed by name.'); }
        $fields = [];
        foreach ($definition['fields'] as $name => $field) {
            if (!is_array($field) || !is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) || in_array($name, ['id', 'object', 'title', 'status', 'version'], true)) {
                return self::error('schema_field', 'Field names must be stable lowercase identifiers, excluding record property names.');
            }
            $field['name'] = $name;
            $normalized = self::normalize_field($field, [$slug, $name], self::fields($slug)[$name] ?? null);
            if (is_wp_error($normalized)) { return $normalized; }
            $fields[$name] = $normalized;
        }
        $constraints = $definition['unique_constraints'] ?? [];
        if (!is_array($constraints) || count($constraints) > 32 || ($constraints && array_keys($constraints) !== range(0, count($constraints) - 1))) { return self::error('schema_unique', 'Unique constraints must be a list of at most 32 definitions.'); }
        $constraint_names = [];
        foreach ($constraints as $constraint) {
            if (!is_array($constraint) || array_diff(array_keys($constraint), ['name', 'fields']) || !is_string($constraint['name'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $constraint['name']) || isset($constraint_names[$constraint['name']]) || !is_array($constraint['fields'] ?? null) || count($constraint['fields']) < 2 || count($constraint['fields']) > 8) { return self::error('schema_unique', 'Unique constraints require a distinct stable name and 2–8 fields.'); }
            $constraint_names[$constraint['name']] = true; $seen = [];
            if (array_keys($constraint['fields']) !== range(0, count($constraint['fields']) - 1)) { return self::error('schema_unique', 'Constraint fields must be an ordered list.'); }
            foreach ($constraint['fields'] as $name) {
                if (!is_string($name) || !isset($fields[$name]) || isset($seen[$name])) { return self::error('schema_unique', 'Unique constraint fields must be distinct declared names.'); }
                $seen[$name] = true; $field = $fields[$name];
                if ($field['type'] === 'validated_field') { $field = array_replace($field, ['type' => 'text'], $field['sub_field'] ?? []); }
                if (!empty($field['multiple']) || !in_array($field['type'], ['number', 'range', 'true_false', 'user', 'post_object', 'image', 'file', 'text', 'textarea', 'email', 'url', 'password', 'select', 'radio', 'button_group', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker'], true)) { return self::error('schema_unique', 'Compound uniqueness requires scalar fields.'); }
            }
        }
        foreach ($definition['rules'] ?? [] as $rule) {
            if (!is_array($rule) || !self::condition($rule['when'] ?? null) || (isset($rule['field']) && (!is_string($rule['field']) || ($rule['field'] !== '' && !isset($fields[$rule['field']])))) || (isset($rule['message']) && !is_string($rule['message']))) { return self::error('schema_rules', 'Invalid object validation rule.'); }
        }
        $access = $definition['access'] ?? [];
        if (!is_array($access)) { return self::error('schema_access', 'Access must be keyed by action.'); }
        foreach ($access as $action => $rule) {
            if (!in_array($action, ['read', 'create', 'update', 'delete', 'export'], true) || !is_array($rule) || !in_array($rule['scope'] ?? 'all', ['all', 'own', 'team'], true)) {
                return self::error('schema_access', 'Invalid access action or scope.');
            }
            if (isset($rule['roles']) && (!is_array($rule['roles']) || count(array_filter($rule['roles'], 'is_string')) !== count($rule['roles']))) {
                return self::error('schema_access', 'Access roles must be an array of role names.');
            }
            foreach (['owner_field', 'team_field'] as $setting) {
                if (isset($rule[$setting]) && (!is_string($rule[$setting]) || !isset($fields[$rule[$setting]]))) {
                    return self::error('schema_access', 'Access field must be a declared field.');
                }
            }
            if (($rule['scope'] ?? '') === 'team' && empty($rule['team_field'])) { return self::error('schema_access', 'Team access requires team_field.'); }
            if (isset($rule['user_team_meta']) && (!is_string($rule['user_team_meta']) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $rule['user_team_meta']))) {
                return self::error('schema_access', 'Invalid team user metadata key.');
            }
        }
        $definition = array_replace(['slug' => $slug, 'label' => ucfirst(str_replace('_', ' ', $slug)), 'singular' => '', 'public' => false, 'access' => [], 'rules' => [], 'relationships' => [], 'automation' => [], 'layout' => [], 'record_types' => []], $definition);
        $definition['label'] = sanitize_text_field($definition['label']);
        $definition['singular'] = sanitize_text_field($definition['singular'] ?: $definition['label']);
        $definition['public'] = (bool) $definition['public'];
        $definition['fields'] = $fields;
        $keys = self::field_keys($definition['fields']);
        if (is_wp_error($keys)) { return $keys; }
        foreach (self::objects() as $other_slug => $other) {
            if ($other_slug === $slug) { continue; }
            $other_keys = self::field_keys($other['fields'] ?? []);
            if (is_wp_error($other_keys) || array_intersect($keys, $other_keys)) { return self::error('schema_key_collision', 'Existing field keys collide; a reviewed key migration is required.', 409); }
        }
        if ($dry_run) { $definition['_revision'] = self::revision($definition); return $definition; }
        $expected = self::objects();
        if (isset($expected[$slug]) && (!is_string($definition['_revision'] ?? null) || !hash_equals($expected[$slug]['_revision'], $definition['_revision']))) { return self::error('schema_revision', 'The latest object revision is required. Reload its configuration.', 409); }
        $objects = $expected;
        $definition['_revision'] = self::revision($definition);
        $objects[$slug] = $definition;
        $written = self::write_objects($objects, $expected);
        return is_wp_error($written) ? $written : self::object($slug);
    }

    public static function validate_object(array $definition) { return self::save_object($definition, true); }

    private static function normalize_field(array $field, array $path, ?array $existing = null) {
        foreach (['business', 'choices', 'sub_field', 'layouts', 'vf_rules', 'conditional_defaults'] as $setting) {
            if (isset($field[$setting]) && (!is_array($field[$setting]) || count($field[$setting]) > 1000)) { return self::error('schema_field', 'Field settings must use bounded arrays.'); }
        }
        foreach (['label', 'formula', 'instructions'] as $setting) {
            if (isset($field[$setting]) && !is_string($field[$setting])) { return self::error('schema_field', 'Field text settings must be strings.'); }
        }
        foreach (['required_when', 'readonly_when'] as $setting) {
            if (!empty($field[$setting]) && !self::condition($field[$setting])) { return self::error('schema_condition', 'Invalid field condition.'); }
        }
        foreach ($field['vf_rules'] ?? [] as $rule) {
            if (!is_array($rule) || !self::condition($rule['when'] ?? null) || (isset($rule['message']) && !is_string($rule['message']))) { return self::error('schema_rules', 'Invalid field validation rule.'); }
        }
        foreach ($field['conditional_defaults'] ?? [] as $rule) {
            if (!is_array($rule) || !self::condition($rule['when'] ?? null) || !array_key_exists('value', $rule)) { return self::error('schema_defaults', 'Invalid conditional default.'); }
        }
        if (isset($field['business']) && (!is_string($field['business']['preset'] ?? null) || !isset(BusinessFields::presets()[$field['business']['preset']]))) { return self::error('schema_business', 'Unknown business field preset.'); }
        if (isset($field['sub_field']['read_roles']) || isset($field['sub_field']['write_roles'])) { return self::error('schema_roles', 'Put access rules on the containing wrapper field.'); }
        if (isset($field['relationship'])) {
            $relation = $field['relationship'];
            if (!is_array($relation) || !is_string($relation['object'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $relation['object']) || !in_array($relation['kind'] ?? 'lookup', ['lookup', 'parent', 'junction'], true) || !in_array($relation['on_delete'] ?? 'restrict', ['restrict', 'nullify'], true)) { return self::error('schema_relationship', 'Invalid relationship configuration.'); }
        }
        if (isset($field['rollup'])) {
            $rollup = $field['rollup'];
            if (!is_array($rollup) || !is_string($rollup['object'] ?? null) || !is_string($rollup['relationship_field'] ?? null) || !in_array($rollup['operation'] ?? null, ['count', 'sum', 'min', 'max'], true) || (($rollup['operation'] ?? '') !== 'count' && !is_string($rollup['field'] ?? null))) { return self::error('schema_rollup', 'Invalid rollup configuration.'); }
        }
        if (class_exists(BusinessFields::class) && is_callable([BusinessFields::class, 'apply_preset'])) { $field = BusinessFields::apply_preset($field); }
        $field = array_replace(['type' => 'text', 'label' => ucfirst(str_replace('_', ' ', $field['name'])), 'required' => false, 'choices' => [], 'default_value' => null], $field);
        if (!is_string($field['type']) || !function_exists('acf_get_field_type') || !acf_get_field_type($field['type'])) {
            return self::error('schema_type', 'This native ACF field type is not installed.');
        }
        // Keys are generated, not accepted from untrusted configuration imports.
        $field['key'] = $existing['key'] ?? self::stable_key('field', $path);
        foreach (['read_roles', 'write_roles'] as $setting) {
            if (isset($field[$setting]) && (!is_array($field[$setting]) || count(array_filter($field[$setting], 'is_string')) !== count($field[$setting]))) {
                return self::error('schema_roles', 'Field roles must be an array of role names.');
            }
        }
        if (isset($field['sub_fields'])) {
            if (!is_array($field['sub_fields'])) { return self::error('schema_fields', 'Subfields must be an array.'); }
            $seen = [];
            foreach ($field['sub_fields'] as $index => $sub) {
                if (!is_array($sub) || !is_string($sub['name'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $sub['name'] ?? '')) { return self::error('schema_field', 'Invalid subfield name.'); }
                if (isset($seen[$sub['name']])) { return self::error('schema_duplicate', 'Sibling field names must be unique.'); }
                $seen[$sub['name']] = true;
                // A field-level access boundary belongs to the top-level container.
                if (isset($sub['read_roles']) || isset($sub['write_roles'])) { return self::error('schema_roles', 'Put access rules on the containing field.'); }
                $normalized = self::normalize_field($sub, array_merge($path, [$sub['name']]), self::named_child($existing['sub_fields'] ?? [], $sub['name']));
                if (is_wp_error($normalized)) { return $normalized; }
                $field['sub_fields'][$index] = $normalized;
            }
        }
        if (isset($field['layouts']) && is_array($field['layouts'])) {
            $seen_layouts = [];
            foreach ($field['layouts'] as $index => $layout) {
                if (!is_array($layout) || !is_string($layout['name'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $layout['name'] ?? '')) { return self::error('schema_layout', 'Invalid layout name.'); }
                if (isset($seen_layouts[$layout['name']])) { return self::error('schema_duplicate', 'Layout names must be unique.'); }
                $seen_layouts[$layout['name']] = true;
                $existing_layout = self::named_child($existing['layouts'] ?? [], $layout['name']);
                $layout_path = array_merge($path, ['layout', $layout['name']]);
                $layout['key'] = $existing_layout['key'] ?? self::stable_key('layout', $layout_path);
                $seen = [];
                foreach ($layout['sub_fields'] ?? [] as $i => $sub) {
                    if (!is_array($sub) || !is_string($sub['name'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $sub['name'] ?? '') || isset($sub['read_roles']) || isset($sub['write_roles'])) { return self::error('schema_field', 'Invalid layout field or nested access rule.'); }
                    if (isset($seen[$sub['name']])) { return self::error('schema_duplicate', 'Layout field names must be unique.'); }
                    $seen[$sub['name']] = true;
                    $normalized = self::normalize_field($sub, array_merge($layout_path, [$sub['name']]), self::named_child($existing_layout['sub_fields'] ?? [], $sub['name']));
                    if (is_wp_error($normalized)) { return $normalized; }
                    $layout['sub_fields'][$i] = $normalized;
                }
                $field['layouts'][$index] = $layout;
            }
        }
        return $field;
    }

    /** Every configuration writer uses this full-map compare-and-swap. */
    /** All dependencies share one configuration snapshot, including cross-object permissions. */
    public static function assert_current(string $object) {
        if (!self::managed_object($object) && NativeTables::enabled($object)) { return NativeTables::assert_current($object); }
        global $wpdb;
        $cached = self::objects();
        $raw = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'acf_vf_objects')));
        if (!is_array($raw) || !isset($cached[$object], $raw[$object])) { return self::error('schema_conflict', 'Configuration changed. Reload and retry.', 409); }
        foreach ($raw as &$definition) { if (is_array($definition) && !isset($definition['_revision'])) { $definition['_revision'] = self::revision($definition); } }
        unset($definition);
        if (self::hash($cached) !== self::hash($raw)) { return self::error('schema_conflict', 'Configuration changed. Reload and retry.', 409); }
        return true;
    }

    public static function write_objects(array $proposed, array $expected) {
        if (!current_user_can('manage_options')) { return self::error('forbidden', 'Configuration requires manage_options.', 403); }
        $begin = Storage::begin();
        if (is_wp_error($begin)) { return $begin; }
        try {
            $result = self::write_locked($proposed, $expected);
            if (is_wp_error($result)) { Storage::rollback(); return $result; }
            if (!Storage::commit()) { return self::error('schema_storage', 'Configuration could not be committed.', 503); }
            return true;
        } catch (\Throwable $error) {
            Storage::rollback();
            wp_cache_delete('acf_vf_objects', 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
            return self::error('schema_storage', 'Configuration could not be saved.', 503);
        }
    }

    /** Restore omitted credentials only for the same revision and unchanged delivery target. */
    private static function restore_editor_secrets(array $proposed,array $previous): array {
        if (($proposed['type'] ?? '') === 'webhook' && ($previous['type'] ?? '') === 'webhook' && ($proposed['url'] ?? null) === ($previous['url'] ?? null) && !array_key_exists('secret',$proposed) && isset($previous['secret'])) { $proposed['secret']=$previous['secret']; }
        foreach ($proposed as $key=>$value) {
            if (!is_array($value)) { continue; }
            $old=$previous[$key] ?? [];
            if (isset($value['id']) && (!is_array($old) || ($old['id'] ?? null) !== $value['id'])) {
                $old=[];
                foreach ($previous as $candidate) { if (is_array($candidate) && ($candidate['id'] ?? null)===$value['id']) { $old=$candidate; break; } }
            }
            if (is_array($old)) { $proposed[$key]=self::restore_editor_secrets($value,$old); }
        }
        return $proposed;
    }

    private static function write_locked(array $proposed, array $expected) {
        if (!current_user_can('manage_options')) { return self::error('forbidden', 'Configuration requires manage_options.', 403); }
        $normalized = [];
        foreach ($proposed as $slug => $definition) {
            if (!is_array($definition) || ($definition['slug'] ?? null) !== $slug) { return self::error('schema_object', 'Object map keys must match slugs.'); }
            $requirements = Requirements::validate_definition($definition, $expected[$slug] ?? null);
            if (is_wp_error($requirements)) { return $requirements; }
            $value = self::validate_object($definition);
            if (is_wp_error($value)) { return $value; }
            if (isset($expected[$slug])) { $compatible = self::compatible($expected[$slug], $value); if (is_wp_error($compatible)) { return $compatible; } }
            $normalized[$slug] = $value;
        }
        $all_keys = [];
        foreach ($normalized as $definition) {
            $keys = self::field_keys($definition['fields']);
            if (is_wp_error($keys) || array_intersect($all_keys, $keys)) { return self::error('schema_key_collision', 'Field keys must be globally unique.', 409); }
            $all_keys = array_merge($all_keys, $keys);
        }
        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'acf_vf_objects'));
        $before = $stored === null ? [] : maybe_unserialize($stored);
        if (!is_array($before)) { return self::error('schema_storage', 'Stored configuration is invalid.', 500); }
        $with_revisions = $before;
        foreach ($with_revisions as &$definition) { if (!isset($definition['_revision'])) { $definition['_revision'] = self::revision($definition); } }
        unset($definition);
        if (self::hash($with_revisions) !== self::hash($expected)) { return self::error('schema_conflict', 'Configuration changed. Reload and retry.', 409); }
        foreach (array_diff(array_keys($before), array_keys($normalized)) as $removed_object) {
            $released = Unique::release_object($removed_object);
            if (is_wp_error($released)) { return $released; }
        }
        $serialized = maybe_serialize($normalized);
        if ($stored === null) {
            $previous = $wpdb->suppress_errors(true);
            $changed = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,%s)", 'acf_vf_objects', $serialized, 'off'));
            $wpdb->suppress_errors($previous);
        } elseif ($stored === $serialized) { return true; }
        else { $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $serialized, 'acf_vf_objects', $stored)); }
        if ($changed !== 1) { return self::error('schema_conflict', 'Configuration changed or could not be saved.', 409); }
        wp_cache_delete('acf_vf_objects', 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
        do_action('update_option_acf_vf_objects', $before, $normalized, 'acf_vf_objects');
        do_action('updated_option', 'acf_vf_objects', $before, $normalized);
        self::register();
        return true;
    }

    public static function compatible(array $before, array $after) {
        foreach ($before['fields'] ?? [] as $name => $field) {
            if (!isset($after['fields'][$name]) || self::storage_shape($field) !== self::storage_shape($after['fields'][$name])) { return self::error('schema_migration_required', 'Removing fields or changing their storage shape requires a reviewed data migration.', 409); }
        }
        return true;
    }

    public static function storage_shape(array $field): string {
        $shape = ['type' => $field['type'] ?? 'text', 'multiple' => !empty($field['multiple']), 'sub_field' => isset($field['sub_field']) ? self::storage_shape($field['sub_field']) : null];
        if (($field['type'] ?? '') === 'taxonomy') { $shape['field_type'] = $field['field_type'] ?? 'checkbox'; $shape['save_terms'] = !empty($field['save_terms']); $shape['load_terms'] = !empty($field['load_terms']); }
        if (($field['type'] ?? '') === 'clone') { foreach (['clone', 'display', 'prefix_name'] as $setting) { $shape[$setting] = $field[$setting] ?? null; } }
        foreach ($field['sub_fields'] ?? [] as $sub) { $shape['sub_fields'][$sub['name']] = self::storage_shape($sub); }
        foreach ($field['layouts'] ?? [] as $layout) { $shape['layouts'][$layout['name']] = []; foreach ($layout['sub_fields'] ?? [] as $sub) { $shape['layouts'][$layout['name']][$sub['name']] = self::storage_shape($sub); } }
        return self::hash($shape);
    }

    private static function revision(array $definition): string { unset($definition['_revision']); return self::hash($definition); }
    private static function hash($value): string {
        $normalize = static function ($item) use (&$normalize) {
            if (!is_array($item)) { return $item; }
            ksort($item);
            foreach ($item as &$child) { $child = $normalize($child); }
            return $item;
        };
        return hash('sha256', wp_json_encode($normalize($value)));
    }
    private static function stable_key(string $prefix, array $path): string { return $prefix . '_vfa_' . substr(implode('_', $path), 0, 60) . '_' . substr(hash('sha256', wp_json_encode($path)), 0, 24); }
    private static function named_child(array $fields, string $name): ?array { foreach ($fields as $field) { if (($field['name'] ?? null) === $name) { return $field; } } return null; }
    private static function field_keys(array $fields) {
        $keys = [];
        foreach ($fields as $field) {
            if (isset($field['key'])) { $keys[] = $field['key']; }
            $children = self::field_keys($field['sub_fields'] ?? []);
            if (is_wp_error($children)) { return $children; }
            $keys = array_merge($keys, $children);
            foreach ($field['layouts'] ?? [] as $layout) { $children = self::field_keys($layout['sub_fields'] ?? []); if (is_wp_error($children)) { return $children; } $keys = array_merge($keys, $children); }
        }
        return count(array_unique($keys)) === count($keys) ? $keys : self::error('schema_key_collision', 'Field keys collide; a reviewed key migration is required.', 409);
    }

    public static function register(): void {
        foreach (self::objects() as $slug => $definition) {
            if (class_exists(Localization::class) && is_callable([Localization::class, 'definition'])) { $definition = Localization::definition($definition); }
            if (!post_type_exists($slug)) {
                // The owning theme/plugin decides when an adopted type exists.
                if (!empty($definition['existing'])) { continue; }
                register_post_type($slug, ['label' => $definition['label'], 'labels' => ['singular_name' => $definition['singular']], 'public' => !empty($definition['public']), 'show_ui' => true, 'show_in_rest' => false, 'map_meta_cap' => true, 'capability_type' => 'post', 'supports' => ['title', 'author']]);
                self::$registered[$slug] = true;
            } elseif (!isset(self::$registered[$slug]) && empty($definition['existing'])) {
                continue;
            }
            $managed_type = get_post_type_object($slug);
            foreach (['public', 'publicly_queryable', 'show_in_rest', 'show_in_graphql', 'query_var', 'rewrite'] as $setting) { $managed_type->$setting = false; }
            $managed_type->exclude_from_search = true;
            if (function_exists('acf_add_local_field_group')) { self::register_fields($slug, $definition); }
        }
    }

    private static function register_fields(string $slug, array $definition): void {
        self::forget_fields('group_vfa_' . $slug);
        acf_remove_local_field_group('group_vfa_' . $slug);
        acf_add_local_field_group(['key'=>'group_vfa_' . $slug, 'title'=>$definition['label'] ?? $slug, 'fields'=>array_values($definition['fields'] ?? []), 'location'=>[[['param'=>'post_type','operator'=>'==','value'=>$slug]]]]);
    }

    private static function condition($condition): bool { return is_array($condition) && class_exists(Rules::class) && Rules::valid($condition); }

    private static function forget_fields(string $parent): void {
        foreach (acf_get_local_fields($parent) as $field) {
            self::forget_fields($field['key']);
            acf_remove_local_field($field['key']);
            acf_get_store('fields')->remove($field['key']);
        }
    }

    private static function error(string $code, string $message, int $status = 400): \WP_Error {
        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
