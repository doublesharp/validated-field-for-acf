<?php
/** Authenticated workspace. All data operations delegate to shared services. */
namespace ACF_VF\App;
defined('ABSPATH') || exit;

final class Admin {
    public static function boot(): void {
        add_action('admin_menu', static function (): void {
            add_menu_page('Validated Field', 'Validated Field', 'read', 'validated-field-workspace', [__CLASS__, 'render'], 'dashicons-database', 26);
        });
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_ajax_acf_vf_workspace', [__CLASS__, 'handle']);
    }

    public static function assets(string $hook): void {
        if ($hook !== 'toplevel_page_validated-field-workspace') { return; }
        wp_enqueue_media();
        $version = static function ($name) { return (string) filemtime(__DIR__ . '/assets/' . $name); };
        $base = plugin_dir_url(dirname(__DIR__, 2) . '/validated_field.php') . 'includes/app/assets/';
        wp_enqueue_style('acf-vf-workspace', $base . 'workspace.css', [], $version('workspace.css'));
        wp_enqueue_style('acf-vf-designer', $base . 'workspace-designer.css', ['acf-vf-workspace'], $version('workspace-designer.css'));
        wp_enqueue_script('acf-vf-extensions', $base . 'workspace-extensions.js', [], $version('workspace-extensions.js'), true);
        wp_enqueue_script('acf-vf-designer', $base . 'workspace-designer.js', ['acf-vf-extensions'], $version('workspace-designer.js'), true);
        wp_enqueue_script('acf-vf-workspace', $base . 'workspace.js', ['acf-vf-designer'], $version('workspace.js'), true);
        do_action('acf_vf/app/workspace_assets', $hook);
        wp_add_inline_script('acf-vf-workspace', 'window.vfWorkspace=' . wp_json_encode([
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('acf-vf-workspace'),
            'configure' => current_user_can('manage_options'),
            'proUpgradeUrl' => esc_url_raw((string) ACF_VF_PRO_UPGRADE_URL, ['http','https']),
            'proActive' => defined('ACF_VF_PRO_VERSION'),
            'extensions' => Extensions::metadata(),
            'roles' => current_user_can('manage_options') ? wp_roles()->get_names() : [],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }

    public static function render(): void {
        if (!current_user_can('read')) { return; }
        ?>
        <div id="vf-workspace" class="vf-workspace">
            <aside class="vf-sidebar"><div class="vf-brand"><span class="dashicons dashicons-database" aria-hidden="true"></span> Validated Field</div><p class="vf-kicker">DATA WORKSPACE</p><nav id="vf-objects" aria-label="Objects"></nav><button type="button" class="vf-button vf-new-object" id="vf-create-object">+ Create object</button><a class="vf-native-link" href="<?php echo esc_url(admin_url('edit.php?post_type=acf-field-group')); ?>">Open ACF field groups ↗</a></aside>
            <main class="vf-main"><header class="vf-header"><div><p class="vf-kicker">WORKSPACE</p><h1 id="vf-title">Your data, organized</h1><p id="vf-subtitle">Choose an object to manage its records.</p></div><div id="vf-header-actions"></div></header>
                <div id="vf-feedback" role="status" aria-live="polite" aria-atomic="true"></div>
                <section id="vf-content" aria-label="Records" aria-busy="true"><div class="vf-empty">Loading your workspace…</div></section>
            </main>
            <dialog id="vf-dialog" aria-labelledby="vf-dialog-title"><form id="vf-dialog-form"><header class="vf-dialog-header"><h2 id="vf-dialog-title"></h2><button type="button" class="vf-icon-button" data-close aria-label="Close dialog">×</button></header><div id="vf-dialog-body"></div><footer class="vf-dialog-footer"><button type="button" class="vf-button" data-close>Cancel</button><button type="submit" class="vf-button vf-primary" id="vf-dialog-submit">Save</button></footer></form></dialog>
        </div>
        <?php
    }

    public static function handle(): void {
        check_ajax_referer('acf-vf-workspace', 'nonce');
        $raw = isset($_POST['data']) && is_string($_POST['data']) ? wp_unslash($_POST['data']) : '{}';
        if (strlen($raw) > 1048576) { wp_send_json_error(['message' => 'Request exceeds 1 MiB.'], 413); }
        $decoded = json_decode($raw, false, Rest::JSON_DEPTH);
        if (!is_object($decoded)) { wp_send_json_error(['message' => 'Expected a JSON object.'], 400); }
        $data = json_decode($raw, true, Rest::JSON_DEPTH);
        if (!Rest::bounded($data)) { wp_send_json_error(['message' => 'Input nesting or size exceeds limits.'], 400); }
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key($_POST['operation']) : '';
        $result = self::dispatch($operation, $data);
        if (is_wp_error($result)) {
            $details = $result->get_error_data();
            wp_send_json_error(['code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $details], is_array($details) ? ($details['status'] ?? 400) : 400);
        }
        wp_send_json_success($result);
    }

    public static function dispatch(string $operation, array $data) {
        if (!is_user_logged_in() || !current_user_can('read')) { return self::error('forbidden', 'Sign in to use the workspace.', 403); }
        $object = isset($data['object']) && is_string($data['object']) ? $data['object'] : '';
        $id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false) { return self::error('invalid_id', 'Record ID must be a nonnegative integer.'); }
        // Avoid PHP type errors for malformed requests before calling typed service APIs.
        foreach (['payload', 'query', 'columns', 'fields', 'ids', 'definition', 'preferences'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) { return self::error('invalid_payload', "$key must be an object or array."); }
        }
        switch ($operation) {
            case 'objects':
                $items = [];
                foreach (Schema::table_objects() as $slug => $definition) {
                    if (!Permissions::can('read', $slug)) { continue; }
                    $descriptor = self::form_metadata($slug);
                    if (!is_wp_error($descriptor)) { $items[] = $descriptor; }
                }
                return $items;
            case 'schema':
                if (!current_user_can('manage_options')) { return self::error('forbidden', 'Configuration requires administrator access.', 403); }
                $metadata = NativeFields::metadata();
                $metadata['field_types'] = [];
                foreach (acf_get_field_types() as $type) { $metadata['field_types'][$type->name] = $type->label; }
                return ['objects' => self::editor_objects(), 'metadata' => apply_filters('acf_vf/app/editor_metadata', $metadata)];
            case 'save_object': return Schema::save_object($data['definition'] ?? []);
            case 'query':
                $result = Query::run($object, $data['query'] ?? []);
                if (is_wp_error($result)) { return $result; }
                $columns = isset($data['columns']) ? Lists::normalize_columns($object,$data['columns']) : Lists::columns($object);
                if (is_wp_error($columns)) { return $columns; }
                foreach ($result['items'] as &$item) {
                    $item['display'] = [];
                    foreach ($columns as $column) { $item['display'][$column['field']] = Lists::format_value($object, $item, $column); }
                }
                unset($item);
                return $result;
            case 'record': return Records::read($id);
            case 'form': return self::form_metadata($object, $id);
            case 'related':
                $record = Records::read($id);
                if (is_wp_error($record)) { return $record; }
                $related = [];
                foreach (Schema::objects() as $slug => $definition) {
                    if (!Permissions::can('read', $slug)) { continue; }
                    foreach (Schema::fields($slug) as $name => $field) {
                        if (($field['relationship']['object'] ?? '') !== $record['object'] || !Permissions::can('read', $slug, 0, $name)) { continue; }
                        $type = $field['type'] === 'validated_field' ? ($field['sub_field']['type'] ?? '') : $field['type'];
                        $multiple = $type === 'relationship' || !empty($field['multiple']);
                        $result = Query::run($slug, ['per_page' => 20, 'filters' => [['field' => $name, 'operator' => $multiple ? 'contains' : 'eq', 'value' => $id]]]);
                        if (!is_wp_error($result)) { $related[] = ['object' => $slug, 'label' => $definition['label'], 'field' => $name, 'multiple' => $multiple, 'result' => $result]; }
                    }
                }
                return $related;
            case 'save_record': return Records::save($object, $data['payload'] ?? [], $id, ['source' => 'workspace']);
            case 'quick_add': return ListEditing::quick_add($object,$data['payload']??[]);
            case 'inline_update':
                if (!is_string($data['field']??null) || !is_int($data['version']??null) || !array_key_exists('value',$data)) { return self::error('inline_input','Supply field, value and an integer version.'); }
                return ListEditing::inline_update($id,$data['field'],$data['value'],$data['version']);
            case 'table_preferences': return TablePreferences::get($object);
            case 'save_table_preferences': return TablePreferences::save($object,$data['preferences']??[]);
            case 'delete_record': return Records::delete($id, ['source' => 'workspace']);
            case 'columns': return Lists::columns($object);
            case 'configure_columns': return Lists::configure($object, $data['columns'] ?? []);
            case 'export_csv': return Lists::export_csv($object, $data['query'] ?? [], $data['columns'] ?? []);
            case 'audit': return Audit::history($id);
            case 'jobs': return Jobs::listing();
            case 'retry_job': return Jobs::retry($id);
            case 'configuration_export': return Migrations::export();
            case 'index_status':
                if (!current_user_can('manage_options') || !Schema::object($object)) { return self::error('forbidden', 'Index administration requires configuration access.', 403); }
                return Index::status($object);
            case 'index_rebuild': return Index::backfill($object, 200);
            default: return apply_filters('acf_vf/app/workspace_dispatch', self::error('unknown_operation', 'Unknown workspace operation.'), $operation, $data);
        }
    }

    /** Browser drafts never receive persisted webhook signing material. */
    private static function editor_objects(): array {
        $objects = Schema::objects();
        $redact = static function (array $actions) use (&$redact): array {
            foreach ($actions as &$action) {
                if (!is_array($action)) { continue; }
                if (($action['type'] ?? '') === 'webhook') { unset($action['secret']); }
                if (is_array($action['action'] ?? null)) { $nested = $redact([$action['action']]); $action['action'] = $nested[0]; }
            }
            unset($action);
            return $actions;
        };
        foreach ($objects as &$definition) {
            foreach ($definition['automation'] ?? [] as $key => $trigger) {
                if (is_array($trigger) && is_array($trigger['actions'] ?? null)) { $definition['automation'][$key]['actions'] = $redact($trigger['actions']); }
            }
        }
        unset($definition);
        return $objects;
    }

    private static function error(string $code, string $message, int $status = 400): \WP_Error {
        return new \WP_Error('vf_' . $code, $message, ['status' => $status]);
    }

    public static function form_metadata(string $object, int $id = 0) {
        if (!Permissions::can('read', $object, $id)) { return self::error('forbidden', 'This object or record is unavailable.', 403); }
        $definition = Schema::object($object);
        $fields = [];
        foreach (Schema::fields($object) as $name => $field) {
            if (!Permissions::can('read', $object, $id, $name)) { continue; }
            $metadata = self::field_metadata($field);
            $metadata['computed'] = !empty($field['formula']) || !empty($field['rollup']);
            $metadata['provider_missing'] = (!empty($field['formula']) && !Extensions::has('calculations.formula')) || (!empty($field['rollup']) && !Extensions::has('calculations.rollup'));
            $metadata['can_create'] = !$metadata['computed'] && empty($field['readonly']) && Permissions::can('create', $object, 0, $name);
            $metadata['can_update'] = !$metadata['computed'] && empty($field['readonly']) && Permissions::can('update', $object, $id, $name);
            if (isset($field['readonly_when']) && !Rules::condition_accessible($field['readonly_when'], [], $object, $id)) {
                $metadata['readonly'] = true;
                $metadata['can_create'] = false;
                $metadata['can_update'] = false;
            }
            if (isset($field['required_when']) && !Rules::condition_accessible($field['required_when'], [], $object, $id)) { $metadata['required'] = true; }
            $metadata['editable'] = $metadata['can_create'] || $metadata['can_update'];
            if (!empty($field['relationship']['object']) && Permissions::can('read', $field['relationship']['object'])) { $metadata['relationship'] = ['object' => $field['relationship']['object']]; }
            // Conditional form hints are only exposed when every operand is readable.
            foreach (['required_when', 'readonly_when'] as $setting) {
                if (isset($field[$setting]) && self::condition_readable($field[$setting], $object, $id)) { $metadata[$setting] = $field[$setting]; }
            }
            $fields[$name] = apply_filters('acf_vf/app/field_metadata', $metadata, $field, $object, $id);
        }
        return ['slug' => $object, 'label' => $definition['label'], 'singular' => $definition['singular'], 'native_table'=>!empty($definition['native_table']), 'fields' => $fields, 'layout' => self::layout_metadata(Extensions::has('designer.layouts') ? ($definition['layout'] ?? []) : [], $fields), 'record_types' => self::record_type_metadata(Extensions::has('designer.layouts') ? ($definition['record_types'] ?? []) : [], $fields), 'can_create' => Permissions::can('create', $object), 'can_update' => Permissions::can('update', $object, $id), 'can_delete' => Permissions::can('delete', $object, $id), 'can_export' => Permissions::can('export', $object, $id)];
    }
    private static function condition_readable($condition, string $object, int $id, int $depth = 0): bool {
        if (!is_array($condition) || $depth > 8) { return false; }
        foreach (['all', 'any'] as $join) {
            if (isset($condition[$join])) {
                foreach ($condition[$join] as $child) { if (!self::condition_readable($child, $object, $id, $depth + 1)) { return false; } }
                return true;
            }
        }
        foreach (['field', 'value_field'] as $key) {
            if (isset($condition[$key]) && (!is_string($condition[$key]) || strpos($condition[$key], '.') !== false || !Permissions::can('read', $object, $id, $condition[$key]))) { return false; }
        }
        return isset($condition['field']);
    }
    private static function layout_metadata(array $layout, array $fields): array {
        $tabs = [];
        foreach (is_array($layout['tabs'] ?? null) ? $layout['tabs'] : [] as $tab) {
            if (!is_array($tab)) { continue; }
            $sections = [];
            foreach (is_array($tab['sections'] ?? null) ? $tab['sections'] : [] as $section) {
                if (!is_array($section)) { continue; }
                $names = is_array($section['fields'] ?? null) ? $section['fields'] : [];
                $sections[] = ['label' => is_string($section['label'] ?? null) ? $section['label'] : '', 'fields' => array_values(array_filter($names, static function ($name) use ($fields) { return is_string($name) && isset($fields[$name]); }))];
            }
            $tabs[] = ['label' => is_string($tab['label'] ?? null) ? $tab['label'] : '', 'sections' => $sections];
        }
        return ['tabs' => $tabs];
    }
    private static function record_type_metadata(array $types, array $fields): array {
        $result = [];
        foreach ($types as $type) {
            if (!is_array($type) || (isset($type['field']) && !isset($fields[$type['field']]))) { continue; }
            $safe = array_intersect_key($type, array_flip(['id', 'label', 'field', 'value']));
            if (isset($type['layout']) && is_array($type['layout'])) { $safe['layout'] = self::layout_metadata($type['layout'], $fields); }
            $result[] = $safe;
        }
        return $result;
    }

    /** Only input shape and labels are sent to the browser, never executable rules. */
    private static function field_metadata(array $field, int $depth = 0): array {
        if ($depth > 12) { return []; }
        if (($field['type'] ?? '') === 'validated_field') {
            $field = array_replace($field, $field['sub_field'] ?? [], array_intersect_key($field, array_flip(['name', 'label', 'required', 'readonly'])));
        }
        $metadata = array_intersect_key($field, array_flip(['name', 'label', 'type', 'field_type', 'required', 'choices', 'readonly', 'multiple', 'min', 'max', 'step', 'instructions', 'default_value']));
        foreach ($field['sub_fields'] ?? [] as $child) { $metadata['sub_fields'][] = self::field_metadata($child, $depth + 1); }
        foreach ($field['layouts'] ?? [] as $layout) {
            $item = array_intersect_key($layout, array_flip(['name', 'label']));
            $item['sub_fields'] = array_map(static function ($child) use ($depth) { return self::field_metadata($child, $depth + 1); }, $layout['sub_fields'] ?? []);
            $metadata['layouts'][] = $item;
        }
        return $metadata;
    }
}
