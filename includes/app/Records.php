<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** The only application write path. Every proposed record is validated before mutation. */
final class Records {
    private static $writing = 0;
    private static $native_writes = [];
    private static $derived_providers = [];
    private static $derived_validation = [];

    /** Only compatible server providers register calculators, during extension bootstrap. */
    public static function register_derived(string $key, string $feature, array $dependents, callable $calculate): void {
        if (!doing_action('acf_vf/app/register_extensions') || isset(self::$derived_providers[$key])) { throw new \LogicException('Derived calculator registration is closed or duplicated.'); }
        self::$derived_providers[$key] = compact('feature', 'dependents', 'calculate');
    }

    /** This identity exists only during internal derived validation and persistence events. */
    public static function derived_context(string $object, int $id, array $context): bool {
        $frame = end(self::$derived_validation);
        return $frame && $frame['object'] === $object && $frame['id'] === $id && isset($context['_derived_identity']) && $context['_derived_identity'] === $frame['identity'];
    }

    public static function derived_unchanged(string $object, int $id, string $name, $value, array $context): bool {
        if (!self::derived_context($object, $id, $context)) { return false; }
        $frame = end(self::$derived_validation);
        return array_key_exists($name, $frame['before']) && $frame['before'][$name] === $value;
    }

    /** Native ACF validation has no event context; require the exact registered field identity. */
    public static function derived_native_unchanged(string $object, int $id, array $field, $value): bool {
        $frame = end(self::$derived_validation);
        $key = $field['key'] ?? null;
        if (!$frame || $frame['object'] !== $object || $frame['id'] !== $id || !is_string($key) || $key === '') { return false; }
        foreach (Schema::fields($object) as $name=>$definition) {
            if (!NativeFields::contains_key($definition,$key)) { continue; }
            // ACF gives subfields concrete row names. Their registered key identifies
            // the containing field, whose entire proposal must remain unchanged.
            if (!array_key_exists($name,$frame['values']) || !self::derived_unchanged($object,$id,$name,$frame['values'][$name],['_derived_identity'=>$frame['identity']])) { return false; }
            return $definition['key'] !== $key || $frame['before'][$name] === $value;
        }
        return false;
    }

    /** Recompute a declared field; callers cannot supply values, callbacks, or write authority. */
    public static function refresh_derived(int $id, string $name, string $key, array $context = []) {
        $provider = self::$derived_providers[$key] ?? null;
        $object = (string) get_post_type($id);
        if (!get_current_user_id() || !$provider || !Extensions::has($provider['feature']) || !Schema::object($object) || empty(Schema::fields($object)[$name][$key]) || get_post_status($id) === 'trash') { return self::error('derived_unavailable', 'Derived field is unavailable.', 409); }
        $context = ['source' => $key, 'depth' => max(0, (int) ($context['depth'] ?? 0)) + 1];
        if ($context['depth'] > 8) { return self::error('recursion', 'Application write depth exceeded.', 409); }
        $begin = Storage::begin();
        if (is_wp_error($begin)) { return $begin; }
        try {
            $lock = Storage::lock('record:' . $id);
            if (is_wp_error($lock)) { Storage::rollback(); return $lock; }
            self::flush_cache($id);
            $current = Schema::assert_current($object);
            $requirements = Requirements::assert_operation('update', $object, $id, $context);
            if (is_wp_error($current) || is_wp_error($requirements)) { Storage::rollback(); return is_wp_error($current) ? $current : $requirements; }
            $before = self::raw($id);
            $calculated = call_user_func($provider['calculate'], $id, $name, $before['fields']);
            if (is_wp_error($calculated) || !is_array($calculated) || !array_key_exists($name, $calculated)) { Storage::rollback(); return self::error('derived_calculation', 'Derived field could not be calculated.', 422); }
            $definitions = Schema::fields($object);
            $changes = [];
            foreach ($calculated as $field_name => $value) {
                $allowed = $field_name === $name;
                foreach ($provider['dependents'] as $config => $feature) {
                    if (!empty($definitions[$field_name][$config]) && Extensions::has($feature)) { $allowed = true; }
                }
                if (!$allowed || !isset($definitions[$field_name])) { Storage::rollback(); return self::error('derived_fields', 'Derived calculator proposed an undeclared field.', 422); }
                $existing = $before['fields'][$field_name] ?? null;
                if (($value === null && ($existing === null || $existing === '')) || (is_numeric($value) && is_numeric($existing) && (float)$value === (float)$existing) || $existing === $value) { continue; }
                $changes[$field_name] = $value;
            }
            if (!$changes) { return Storage::commit() ? true : self::error('commit_failed', 'Derived transaction could not be committed.', 500); }
            $values = array_replace($before['fields'], $changes);
            // Do not run arbitrary preparation/automation callbacks with derived write authority.
            $identity = new \stdClass();
            self::$derived_validation[] = ['object'=>$object, 'id'=>$id, 'identity'=>$identity, 'before'=>$before['fields'], 'values'=>$values];
            try { $valid = self::validate_proposed($object, $values, $id, $context + ['_derived_identity'=>$identity], $before['status'], true); }
            finally { array_pop(self::$derived_validation); }
            if (is_wp_error($valid)) { Storage::rollback(); return self::error('derived_validation', 'Derived field validation failed.', 422); }
            $claims = Unique::claim($object, $values, $id);
            if (is_wp_error($claims)) { Storage::rollback(); return self::error('derived_unique', 'Derived field uniqueness validation failed.', 422); }
            Storage::touch($id);
            global $wpdb;
            NativeFields::begin_record_write($object, $id, $values);
            try {
                foreach ($changes as $field_name => $value) {
                    $field = $definitions[$field_name]; $wpdb->last_error = '';
                    self::native_write($id, $object, static function () use ($field, $value, $id) { return update_field($field['key'], self::native_value($value, $field), $id); });
                    if ($wpdb->last_error) { throw new \RuntimeException('Derived persistence failed.'); }
                }
            } finally { NativeFields::end_record_write(); }
            $version = $before['version'] + 1;
            $written = self::native_write($id, $object, static function () use ($id, $version) { return update_post_meta($id, '_vf_app_version', $version); });
            if ($written === false || is_wp_error(Unique::finish($object, $claims, $id))) { throw new \RuntimeException('Derived persistence failed.'); }
            $record = self::raw($id);
            self::$derived_validation[] = ['object'=>$object, 'id'=>$id, 'identity'=>$identity, 'before'=>$before['fields'], 'values'=>$values];
            try { do_action('acf_vf/app/record_persisted', $record, $before, $context + ['_derived_identity'=>$identity]); }
            finally { array_pop(self::$derived_validation); }
            Storage::after_commit(static function () use ($record, $before, $context) { do_action('acf_vf/app/record_saved', $record, $before, $context); });
            return Storage::commit() ? true : self::error('commit_failed', 'Derived transaction could not be committed.', 500);
        } catch (\Throwable $error) { Storage::rollback(); return self::error('derived_failed', 'Derived field could not be refreshed.', 500); }
    }
    public static function is_writing(?int $id = null): bool {
        if ($id === null) { return self::$writing > 0; }
        $frame = end(self::$native_writes);
        return $id > 0 && $frame && $frame['id'] === $id;
    }
    /** Consume the one new-post permission; nested native inserts cannot reuse it. */
    public static function permits_post_write(array $post): bool {
        $id = (int) ($post['ID'] ?? 0);
        if ($id) { return self::is_writing($id); }
        $key = count(self::$native_writes) - 1;
        if ($key < 0 || empty(self::$native_writes[$key]['create']) || ($post['post_type'] ?? '') !== self::$native_writes[$key]['object']) { return false; }
        self::$native_writes[$key]['create'] = false;
        return true;
    }

    private static function native_write(int $id, string $object, callable $write) {
        self::$writing++;
        self::$native_writes[] = ['id'=>$id,'object'=>$object,'create'=>$id === 0];
        try { return $write(); }
        finally { array_pop(self::$native_writes); self::$writing--; }
    }
    /**
     * Read as the current WordPress user; unreadable fields are omitted.
     *
     * @return array|\WP_Error Record with id, object, title, status, version and fields.
     */
    public static function read(int $id) {
        self::flush_cache($id);
        $post = get_post($id);
        if (!$post || !Schema::object($post->post_type)) { return self::error('record_unavailable', 'Record is unavailable.', 404); }
        $current = Schema::assert_current($post->post_type);
        if (is_wp_error($current)) { return $current; }
        if (!Permissions::can('read', $post->post_type, $id)) { return self::error('record_unavailable', 'Record is unavailable.', 404); }
        $record = self::raw($id);
        $record['fields'] = Permissions::readable_fields($record['object'], $id, $record['fields']);
        $current = Schema::assert_current($record['object']);
        return is_wp_error($current) ? $current : $record;
    }

    public static function flush_cache(int $id): void {
        // Reconcile earlier external persistence before native_write can own this ID.
        if (class_exists(NativeTables::class)) { NativeTables::observe($id); }
        clean_post_cache($id);
        wp_cache_delete($id, 'post_meta');
        if (function_exists('acf_get_store')) {
            $store = acf_get_store('values');
            foreach (array_keys($store->get()) as $key) { if (strpos((string) $key, $id . ':') === 0) { $store->remove($key); } }
        }
    }

    private static function raw(int $id): array {
        $post = get_post($id);
        $values = [];
        foreach (Schema::fields($post->post_type) as $name => $field) { $values[$name] = self::public_value(get_field($field['key'], $id, false), $field); }
        return ['id' => $id, 'object' => $post->post_type, 'title' => $post->post_title, 'status' => $post->post_status, 'version' => (int) get_post_meta($id, '_vf_app_version', true), 'fields' => $values];
    }

    private static function context(array $context): array {
        $context['source'] = sanitize_key($context['source'] ?? 'application');
        $context['depth'] = max(0, (int) ($context['depth'] ?? 0));
        return $context;
    }

    /**
     * Create or update through the shared permissions, validation and transaction path.
     *
     * @param string $object Configured object slug or opted-in native post type.
     * @param array $payload Optional title/status/fields; updates must include the last-read version.
     * @param int $id Existing record ID, or zero to create.
     * @param array $context source labels the write; dry_run validates and rolls back a preview.
     * @return array|\WP_Error Saved or previewed record; stale versions return version_conflict.
     * @example Records::save('invoice', ['fields' => ['amount' => 125]]);
     */
    public static function save(string $object, array $payload, int $id = 0, array $context = []) {
        $context = self::context($context);
        if ($context['depth'] > 8) { return self::error('recursion', 'Application write depth exceeded.', 409); }
        if (!Schema::object($object)) { return self::error('object_unavailable', 'Object is unavailable.', 404); }
        if (!Permissions::can($id ? 'update' : 'create', $object, $id)) { return self::error('forbidden', 'This record cannot be changed.', 403); }
        if (array_diff(array_keys($payload), ['title', 'status', 'fields', 'version'])) { return self::error('payload', 'Unknown record property.'); }
        if (isset($payload['fields']) && !is_array($payload['fields'])) { return self::error('payload', 'Fields must be an object keyed by name.'); }
        if (isset($payload['title']) && !is_string($payload['title'])) { return self::error('payload', 'Title must be text.'); }
        if ($id && (!array_key_exists('version', $payload) || filter_var($payload['version'], FILTER_VALIDATE_INT) === false || $payload['version'] < 0)) { return self::error('version_required', 'An integer version is required for updates.', 409); }
        $status = $payload['status'] ?? ($id ? get_post_status($id) : 'private');
        $preserve_native=$id && !empty(Schema::object($object)['native_table']) && is_string($status) && get_post_status_object($status) && !in_array($status,['trash','auto-draft','inherit'],true) && $status===get_post_status($id);
        if (!in_array($status, ['draft', 'pending', 'private', 'publish'], true) && !$preserve_native) { return self::error('status', 'Unsupported record status.'); }
        $type = get_post_type_object($object);
        if (in_array($status, ['private', 'publish'], true) && !current_user_can($type->cap->publish_posts)) { return self::error('forbidden', 'Publishing this record is not permitted.', 403); }
        $transaction = Storage::begin();
        if (is_wp_error($transaction)) { return $transaction; }
        $committed = false;
        try {
            $requirements = Requirements::assert_operation($id ? 'update' : 'create', $object, $id, $context);
            if (is_wp_error($requirements)) { Storage::rollback(); return $requirements; }
            $current_schema = Schema::assert_current($object);
            if (is_wp_error($current_schema)) { Storage::rollback(); return $current_schema; }
            if ($id) {
                $lock = Storage::lock('record:' . $id);
                if (is_wp_error($lock)) { Storage::rollback(); return $lock; }
                self::flush_cache($id);
                if (!Permissions::can('update', $object, $id)) { Storage::rollback(); return self::error('forbidden', 'This record cannot be changed.', 403); }
            }
            $before = $id ? self::raw($id) : null;
            if ($id && (int) $payload['version'] !== $before['version']) { Storage::rollback(); return self::error('version_conflict', 'The record changed. Reload before saving.', 409); }
            if ($preserve_native && $before['status']!==$status) { Storage::rollback(); return self::error('status','Native status changed. Reload before saving.',409); }
            $input = $payload['fields'] ?? [];
            $access = self::field_access($object, $input, $id);
            if (is_wp_error($access)) { Storage::rollback(); return $access; }
            $values = $before ? $before['fields'] : self::defaults($object);
            $values = array_replace($values, $input);
            $values = apply_filters('acf_vf/app/prepare_fields', $values, $object, $id, $context);
            if (!is_array($values)) { Storage::rollback(); return self::error('prepare_fields', 'A field preparation handler failed.', 500); }
            $valid = self::validate_proposed($object, $values, $id, $context, $status);
            if (is_wp_error($valid)) { Storage::rollback(); return $valid; }
            $transition = apply_filters('acf_vf/app/validate_transition', true, $object, $before, ['id'=>$id,'status'=>$status,'fields'=>$values], $context);
            if (is_wp_error($transition)) { Storage::rollback(); return $transition; }
            if ($transition !== true) { Storage::rollback(); return self::error('transition_handler','Transition validation failed.',500); }
            if (!empty($context['dry_run'])) {
                $preview = ['id' => $id, 'object' => $object, 'title' => $payload['title'] ?? ($before['title'] ?? ''), 'status' => $status, 'version' => ($before['version'] ?? 0) + 1, 'fields' => Permissions::readable_fields($object, $id, $values)];
                Storage::rollback();
                return $preview;
            }
            $claims = Unique::claim($object, $values, $id);
            if (is_wp_error($claims)) { Storage::rollback(); return $claims; }
            $post = ['post_type' => $object, 'post_status' => $status, 'post_title' => $payload['title'] ?? ($before['title'] ?? '')];
            if ($id) { $post['ID'] = $id; Storage::touch($id); }
            else { $post['post_author'] = get_current_user_id(); }
            $native_update=$id && !empty(Schema::object($object)['native_table']);
            $saved_id = self::native_write($id, $object, static function () use ($post,$native_update) { return $native_update?wp_update_post(wp_slash($post),true):wp_insert_post(wp_slash($post), true); });
            if (is_wp_error($saved_id)) { Storage::rollback(); return self::error('record_write', 'Record could not be saved.', 500); }
            $id = (int) $saved_id;
            Storage::touch($id);
            global $wpdb;
            $native_context = class_exists(NativeFields::class) && is_callable([NativeFields::class, 'begin_record_write']);
            if ($native_context) { NativeFields::begin_record_write($object, $id, $values); }
            try {
            foreach (Schema::fields($object) as $name => $field) {
                $wpdb->last_error = '';
                self::native_write($id, $object, static function () use ($field, $values, $name, $id) { return update_field($field['key'], self::native_value($values[$name] ?? null, $field), $id); });
                if ($wpdb->last_error) { Storage::rollback(); return self::error('field_write', 'A field could not be saved.', 500); }
            }
            } finally { if ($native_context) { NativeFields::end_record_write(); } }
            $version = ($before['version'] ?? 0) + 1;
            $version_saved = self::native_write($id, $object, static function () use ($id, $version) { return update_post_meta($id, '_vf_app_version', $version); });
            if ($version_saved === false) { Storage::rollback(); return self::error('version_write', 'Record version could not be saved.', 500); }
            $unique_saved = Unique::finish($object, $claims, $id);
            if (is_wp_error($unique_saved)) { Storage::rollback(); return $unique_saved; }
            $record = self::raw($id);
            // Durable audit/outbox work belongs here; external side effects belong in record_saved after commit.
            do_action('acf_vf/app/record_persisted', $record, $before, $context);
            Storage::after_commit(static function () use ($record, $before, $context) { do_action('acf_vf/app/record_saved', $record, $before, $context); });
            if (!Storage::commit()) { return self::error('commit_failed', 'Record transaction could not be committed.', 500); }
            $committed = true;
            $record['fields'] = Permissions::readable_fields($object, $id, $record['fields']);
            return $record;
        } catch (\Throwable $exception) {
            if (!$committed) { Storage::rollback(); }
            // Never include field values, SQL, stack traces or third-party exception text.
            return self::error('record_exception', 'The record operation failed.', 500);
        }
    }

    /**
     * Validate proposed fields against defaults or an existing record without saving values.
     *
     * @return true|\WP_Error Validation result; use save() for authoritative write-time checks.
     */
    public static function validate(string $object, array $fields, int $id = 0, array $context = []) {
        if (!Schema::object($object)) { return self::error('object_unavailable', 'Object is unavailable.', 404); }
        if (!Permissions::can($id ? 'update' : 'create', $object, $id)) { return self::error('forbidden', 'This record cannot be changed.', 403); }
        $requirements = Requirements::assert_operation($id ? 'update' : 'create', $object, $id, $context);
        if (is_wp_error($requirements)) { return $requirements; }
        $access = self::field_access($object, $fields, $id);
        if (is_wp_error($access)) { return $access; }
        $context = self::context($context);
        $values = array_replace($id ? self::raw($id)['fields'] : self::defaults($object), $fields);
        $values = apply_filters('acf_vf/app/prepare_fields', $values, $object, $id, $context);
        if (!is_array($values)) { return self::error('prepare_fields', 'A field preparation handler failed.', 500); }
        Storage::install();
        return self::validate_proposed($object, $values, $id, $context, $id ? get_post_status($id) : 'private');
    }

    private static function defaults(string $object): array {
        $values = [];
        foreach (Schema::fields($object) as $name => $field) { if (array_key_exists('default_value', $field) && $field['default_value'] !== null) { $values[$name] = $field['default_value']; } }
        return $values;
    }

    private static function field_access(string $object, array $fields, int $id) {
        $schema = Schema::fields($object);
        foreach ($fields as $name => $value) {
            if (!isset($schema[$name])) { return self::error('unknown_field', 'Unknown field.'); }
            if (!Permissions::can($id ? 'update' : 'create', $object, $id, $name)) { return self::error('field_forbidden', 'A submitted field cannot be changed.', 403); }
            if (!empty($schema[$name]['formula']) || !empty($schema[$name]['rollup'])) { return self::error('computed_field', 'Computed fields cannot be submitted.', 403); }
        }
        return true;
    }

    private static function validate_proposed(string $object, array $values, int $id, array $context, string $status, bool $derived = false) {
        $definitions = Schema::fields($object);
        if (!$derived && !Permissions::can_propose($id ? 'update' : 'create', $object, $values, $id)) { return self::error('scope_forbidden', 'Proposed values are outside the permitted record scope.', 403); }
        if (array_diff(array_keys($values), array_keys($definitions))) { return self::error('unknown_field', 'Unknown prepared field.'); }
        if (!function_exists('acf_validate_value')) { return self::error('acf_unavailable', 'ACF validation is unavailable.', 503); }
        $errors = [];
        $post_state = $_POST; $request_state = $_REQUEST;
        $form_state = acf_get_form_data('post_id');
        $vf_form_state = acf_get_form_data('vf_post_id');
        $validation_state = acf()->validation->errors;
        try {
            $submitted = [];
            foreach ($definitions as $name => $field) { $submitted[$field['key']] = self::native_value($values[$name] ?? null, $field); }
            $_POST = ['acf' => $submitted, '_acf_post_id' => $id, 'post_ID' => $id, 'post_status' => $status];
            $_REQUEST = $_POST;
            acf_set_form_data('post_id', $id);
            acf_set_form_data('vf_post_id', $id);
            acf_reset_validation_errors();
            foreach ($definitions as $name => $field) {
                $value = $values[$name] ?? null;
                $native_field = acf_get_field($field['key']) ?: $field;
                if (!empty($field['required']) && ($value === null || $value === '' || $value === false || $value === [])) { $errors[] = ['field' => $name, 'code' => 'required', 'message' => 'A value is required.']; }
                $shape = self::shape($value, $native_field);
                if ($shape !== true) { $errors[] = ['field' => $name, 'code' => 'field_type', 'message' => $shape]; continue; }
                acf_validate_value($submitted[$field['key']], $native_field, 'acf[' . $field['key'] . ']');
            }
            foreach ((acf_get_validation_errors() ?: []) as $error) {
                $name = '';
                foreach ($definitions as $candidate => $field) { if (strpos((string) ($error['input'] ?? ''), '[' . $field['key'] . ']') !== false) { $name = $candidate; break; } }
                $errors[] = ['field' => $name, 'code' => 'native_validation', 'message' => wp_strip_all_tags((string) $error['message'])];
            }
        } finally {
            $_POST = $post_state; $_REQUEST = $request_state;
            acf_set_form_data('post_id', $form_state);
            acf_set_form_data('vf_post_id', $vf_form_state);
            acf()->validation->errors = $validation_state;
        }
        $errors = apply_filters('acf_vf/app/validate_fields', $errors, $object, $values, $id, $context);
        if (!is_array($errors)) { return self::error('validation_handler', 'A validation handler failed.', 500); }
        if ($errors) {
            foreach ($errors as &$error) {
                if (empty($error['field']) || !Permissions::can('read', $object, $id, $error['field'])) { $error = ['field' => '', 'code' => 'validation', 'message' => 'The record does not satisfy its validation rules.']; }
            }
            unset($error);
            return new \WP_Error('validation', 'Record validation failed.', ['status' => 422, 'errors' => array_values($errors)]);
        }
        return Unique::check($object, $values, $id);
    }

    /** Convert nested field names to native ACF keys for validation and persistence. */
    private static function native_value($value, array $field) {
        if (!is_array($value)) { return $value; }
        if ($field['type'] === 'group') { return self::native_row($value, $field['sub_fields'] ?? []); }
        if ($field['type'] === 'repeater') { foreach ($value as $i => $row) { if (is_array($row)) { $value[$i] = self::native_row($row, $field['sub_fields'] ?? []); } } }
        if ($field['type'] === 'flexible_content') {
            foreach ($value as $i => $row) {
                foreach ($field['layouts'] ?? [] as $layout) {
                    if (is_array($row) && ($row['acf_fc_layout'] ?? '') === $layout['name']) { $value[$i] = self::native_row($row, $layout['sub_fields'] ?? []); }
                }
            }
        }
        return $value;
    }

    /** Public records use declared names; ACF raw values use native child keys. */
    private static function public_value($value, array $field) {
        if (!is_array($value)) { return $value; }
        if (($field['type'] ?? '') === 'validated_field') { $field = array_replace($field, ['type' => 'text'], $field['sub_field'] ?? []); }
        if (($field['type'] ?? '') === 'group') { return self::public_row($value, $field['sub_fields'] ?? []); }
        if (($field['type'] ?? '') === 'repeater') { foreach ($value as $index => $row) { if (is_array($row)) { $value[$index] = self::public_row($row, $field['sub_fields'] ?? []); } } }
        if (($field['type'] ?? '') === 'flexible_content') {
            foreach ($value as $index => $row) {
                if (!is_array($row)) { continue; }
                foreach ($field['layouts'] ?? [] as $layout) { if (($row['acf_fc_layout'] ?? '') === $layout['name']) { $value[$index] = ['acf_fc_layout' => $layout['name']] + self::public_row($row, $layout['sub_fields'] ?? []); break; } }
            }
        }
        return $value;
    }

    private static function public_row(array $row, array $fields): array {
        $public = [];
        foreach ($fields as $field) {
            if (array_key_exists($field['key'], $row)) { $public[$field['name']] = self::public_value($row[$field['key']], $field); }
            elseif (array_key_exists($field['name'], $row)) { $public[$field['name']] = self::public_value($row[$field['name']], $field); }
        }
        return $public;
    }

    private static function native_row(array $row, array $fields): array {
        foreach ($fields as $field) {
            if (array_key_exists($field['name'], $row)) { $row[$field['key']] = self::native_value($row[$field['name']], $field); unset($row[$field['name']]); }
            elseif (array_key_exists($field['key'], $row)) { $row[$field['key']] = self::native_value($row[$field['key']], $field); }
        }
        return $row;
    }

    private static function shape($value, array $field) {
        if ($value === null || $value === '' || $value === false || $value === []) { return true; }
        $type = $field['type'];
        if ($type === 'validated_field') { return self::shape($value, array_replace($field, ['type' => 'text'], $field['sub_field'] ?? [])); }
        if (in_array($type, ['group', 'repeater', 'flexible_content', 'checkbox', 'relationship', 'gallery', 'link', 'google_map'], true) || !empty($field['multiple']) || ($type === 'taxonomy' && in_array($field['field_type'] ?? 'checkbox', ['checkbox', 'multi_select'], true))) {
            if (!is_array($value)) { return 'Expected a list or structured value.'; }
        } elseif (is_object($value) || (is_array($value) && in_array($type, ['text', 'textarea', 'email', 'url', 'password', 'number', 'range', 'true_false', 'select', 'radio', 'button_group', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'image', 'file', 'user', 'post_object', 'oembed', 'wysiwyg'], true))) { return 'Expected a scalar value.'; }
        if ($type === 'link' && (array_diff(array_keys($value), ['url', 'title', 'target']) || !is_string($value['url'] ?? null) || (isset($value['target']) && !in_array($value['target'], ['', '_blank', '_self'], true)))) { return 'Invalid link value.'; }
        if (in_array($type, ['checkbox', 'relationship', 'gallery'], true) || !empty($field['multiple'])) {
            foreach ($value as $item) { if (!is_scalar($item)) { return 'List entries must be scalar values.'; } }
        }
        if ($type === 'group') { return self::row_shape($value, $field['sub_fields'] ?? []); }
        if ($type === 'repeater') {
            foreach ($value as $row) { $valid = self::row_shape($row, $field['sub_fields'] ?? []); if ($valid !== true) { return $valid; } }
        }
        if ($type === 'flexible_content') {
            foreach ($value as $row) {
                if (!is_array($row) || !is_string($row['acf_fc_layout'] ?? null)) { return 'Each layout requires a declared layout name.'; }
                $layout = null;
                foreach ($field['layouts'] ?? [] as $candidate) { if ($candidate['name'] === $row['acf_fc_layout']) { $layout = $candidate; break; } }
                if (!$layout) { return 'Unknown layout.'; }
                unset($row['acf_fc_layout']);
                $valid = self::row_shape($row, $layout['sub_fields'] ?? []);
                if ($valid !== true) { return $valid; }
            }
        }
        if (in_array($type, ['number', 'range'], true) && (!is_numeric($value) || !is_finite((float) $value))) { return 'Expected a finite number.'; }
        if ($type === 'email' && !is_email((string) $value)) { return 'Enter a valid email address.'; }
        if (in_array($type, ['select', 'checkbox', 'radio', 'button_group'], true) && empty($field['allow_custom'])) {
            foreach ((array) $value as $choice) { if (!is_scalar($choice) || !array_key_exists((string) $choice, $field['choices'] ?? [])) { return 'Choose a configured option.'; } }
        }
        return true;
    }

    private static function row_shape($row, array $fields) {
        if (!is_array($row)) { return 'Expected a structured field row.'; }
        $allowed = [];
        foreach ($fields as $field) {
            $allowed[] = $field['name']; $allowed[] = $field['key'];
            if (array_key_exists($field['name'], $row) && array_key_exists($field['key'], $row)) { return 'Submit each nested field once.'; }
            $valid = self::shape($row[$field['name']] ?? $row[$field['key']] ?? null, $field);
            if ($valid !== true) { return $valid; }
        }
        return array_diff(array_keys($row), $allowed) ? 'Unknown nested field.' : true;
    }

    /**
     * Permanently delete through application policies, unique-claim cleanup and audit hooks.
     *
     * @return true|\WP_Error Result for the current WordPress user.
     */
    public static function delete(int $id, array $context = []) {
        $requirements = Requirements::assert_operation('delete', (string)get_post_type($id), $id, $context);
        if (is_wp_error($requirements)) { return $requirements; }
        $record = self::read($id);
        if (is_wp_error($record)) { return $record; }
        if (!Permissions::can('delete', $record['object'], $id)) { return self::error('forbidden', 'This record cannot be deleted.', 403); }
        $context = self::context($context);
        if ($context['depth'] > 8) { return self::error('recursion', 'Application write depth exceeded.', 409); }
        $transaction = Storage::begin();
        if (is_wp_error($transaction)) { return $transaction; }
        try {
            $current_schema = Schema::assert_current($record['object']);
            if (is_wp_error($current_schema)) { Storage::rollback(); return $current_schema; }
            $lock = Storage::lock('record:' . $id);
            if (is_wp_error($lock)) { Storage::rollback(); return $lock; }
            self::flush_cache($id);
            if (!Permissions::can('delete', $record['object'], $id)) { Storage::rollback(); return self::error('forbidden', 'This record cannot be deleted.', 403); }
            $record = self::raw($id);
            $allowed = apply_filters('acf_vf/app/can_delete', true, $record, $context);
            if ($allowed !== true) { Storage::rollback(); return is_wp_error($allowed) ? $allowed : self::error('delete_restricted', 'This record cannot be deleted.', 409); }
            Storage::touch($id);
            $deleted = self::native_write($id, $record['object'], static function () use ($id) { return wp_delete_post($id, true); });
            if (!$deleted) { Storage::rollback(); return self::error('delete_failed', 'Record deletion failed.', 500); }
            $unique_released = Unique::release($id);
            if (is_wp_error($unique_released)) { Storage::rollback(); return $unique_released; }
            do_action('acf_vf/app/record_delete_persisted', $record, $context);
            Storage::after_commit(static function () use ($record, $context) { do_action('acf_vf/app/record_deleted', $record, $context); });
            return Storage::commit() ? true : self::error('commit_failed', 'Record transaction could not be committed.', 500);
        } catch (\Throwable $exception) { Storage::rollback(); return self::error('delete_failed', 'Record deletion failed.', 500); }
    }

    private static function error(string $code, string $message, int $status = 400): \WP_Error { return new \WP_Error($code, $message, ['status' => $status]); }
}
