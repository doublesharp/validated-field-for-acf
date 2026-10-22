<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Versioned configuration plans, with an explicit stale-plan check before apply. */
final class Migrations {
    public const VERSION = 1;

    public static function export() {
        if (!current_user_can('manage_options')) { return Rest::error('forbidden', 'Configuration requires manage_options.', 403); }
        return ['schema_version' => self::VERSION, 'objects' => Schema::objects()];
    }

    public static function definition(array $definition) {
        $error = Rest::keys($definition, ['slug', 'label', 'singular', 'public', 'existing', 'fields', 'access', 'rules', 'relationships', 'automation', 'layout', 'record_types', 'approvals', 'transitions', 'localization', 'unique_constraints', '_revision']);
        if ($error) { return $error; }
        if (isset($definition['unique_constraints'])) {
            if (!Rest::is_list($definition['unique_constraints']) || count($definition['unique_constraints']) > 32) { return Rest::error('configuration_unique', 'Unique constraints must be a list of at most 32 definitions.'); }
            foreach ($definition['unique_constraints'] as $constraint) {
                if (!is_array($constraint) || Rest::keys($constraint, ['name', 'fields']) || !is_string($constraint['name'] ?? null) || !Rest::is_list($constraint['fields'] ?? null)) { return Rest::error('configuration_unique', 'A unique constraint requires a name and ordered field list.'); }
            }
        }
        if (isset($definition['_revision']) && (!is_string($definition['_revision']) || !preg_match('/^[a-f0-9]{64}$/D', $definition['_revision']))) { return Rest::error('configuration_revision', 'Invalid schema revision.'); }
        if (isset($definition['localization']) && (!is_array($definition['localization']) || Rest::keys($definition['localization'], ['enabled']) || !is_bool($definition['localization']['enabled'] ?? null))) { return Rest::error('configuration_localization', 'Localization requires a boolean enabled setting.'); }
        foreach (['label', 'singular', 'slug'] as $key) {
            if (array_key_exists($key, $definition) && (!is_string($definition[$key]) || strlen($definition[$key]) > 200)) { return Rest::error('configuration_text', 'Object labels and identifiers must be bounded text.'); }
        }
        foreach (['public', 'existing'] as $key) { if (array_key_exists($key, $definition) && !is_bool($definition[$key])) { return Rest::error('configuration_boolean', 'public and existing must be booleans.'); } }
        foreach (['fields', 'access', 'rules', 'relationships', 'automation', 'layout', 'record_types', 'approvals', 'transitions'] as $key) { if (array_key_exists($key, $definition) && !is_array($definition[$key])) { return Rest::error('configuration_array', 'Configuration collections must be objects or arrays.'); } }
        foreach ($definition['access'] ?? [] as $access) {
            if (!is_array($access) || Rest::keys($access, ['scope', 'roles', 'owner_field', 'team_field', 'user_team_meta'])) { return Rest::error('configuration_access', 'Unknown access configuration.'); }
        }
        foreach ($definition['rules'] ?? [] as $rule) {
            if (!is_array($rule) || Rest::keys($rule, ['when', 'field', 'message']) || !is_array($rule['when'] ?? null) || !Rules::valid($rule['when']) || (isset($rule['message']) && !is_string($rule['message'])) || (isset($rule['field']) && (!is_string($rule['field']) || !isset($definition['fields'][$rule['field']])))) { return Rest::error('configuration_rule', 'Invalid object validation rule.'); }
        }
        foreach (['approvals', 'transitions'] as $setting) {
            foreach ($definition[$setting] ?? [] as $transition) {
                $allowed = $setting === 'approvals' ? ['status', 'users', 'roles', 'when'] : ['from', 'to', 'users', 'roles', 'when'];
                if (!is_array($transition) || Rest::keys($transition, $allowed)) { return Rest::error('configuration_transition', 'Invalid transition configuration.'); }
                foreach ($setting === 'approvals' ? ['status'] : ['from', 'to'] as $key) {
                    if (!in_array($transition[$key] ?? null, ['', 'draft', 'pending', 'publish', 'private'], true)) { return Rest::error('configuration_transition', 'Invalid transition status.'); }
                }
                foreach (['users', 'roles'] as $key) { if (isset($transition[$key]) && !Rest::is_list($transition[$key])) { return Rest::error('configuration_transition', 'Transition users and roles must be lists.'); } }
                if (isset($transition['when']) && (!is_array($transition['when']) || !Rules::valid($transition['when']))) { return Rest::error('configuration_transition', 'Invalid transition condition.'); }
            }
        }
        foreach ($definition['automation'] ?? [] as $automation) {
            if (!is_array($automation) || Rest::keys($automation, ['id', 'event', 'timing', 'when', 'actions']) || !is_string($automation['id'] ?? null) || !in_array($automation['event'] ?? null, ['create', 'update', 'delete'], true) || !in_array($automation['timing'] ?? 'after', ['before', 'after'], true) || !Rest::is_list($automation['actions'] ?? null) || count($automation['actions']) > 20) { return Rest::error('configuration_automation', 'Invalid automation trigger.'); }
            if (isset($automation['when']) && (!is_array($automation['when']) || !Rules::valid($automation['when']))) { return Rest::error('configuration_automation', 'Invalid automation condition.'); }
            foreach ($automation['actions'] as $action) { $error = self::action($action); if ($error) { return $error; } }
        }
        foreach ($definition['fields'] ?? [] as $field) {
            if (!is_array($field)) { return Rest::error('configuration_field', 'Fields must be definitions.'); }
            $error = self::field($field);
            if ($error) { return $error; }
        }
        return null;
    }

    private static function action($action, int $depth = 0) {
        if (!is_array($action) || $depth > 1) { return Rest::error('configuration_action', 'Invalid automation action.'); }
        $allowed = [
            'set_field' => ['field', 'value'], 'create_record' => ['object', 'payload'],
            'email' => ['to', 'subject', 'message'], 'webhook' => ['url', 'secret', 'body'],
            'schedule' => ['delay', 'action'],
        ];
        $type = $action['type'] ?? null;
        if (!is_string($type) || !isset($allowed[$type]) || Rest::keys($action, array_merge(['type'], $allowed[$type]))) { return Rest::error('configuration_action', 'Unknown automation action or properties.'); }
        if ($type === 'set_field' && (!is_string($action['field'] ?? null) || !array_key_exists('value', $action))) { return Rest::error('configuration_action', 'Set-field requires a field and value.'); }
        if ($type === 'create_record' && (!is_string($action['object'] ?? null) || !is_array($action['payload'] ?? null) || Rest::record_payload($action['payload']))) { return Rest::error('configuration_action', 'Create-record requires a typed record payload.'); }
        if ($type === 'email' && (!is_string($action['to'] ?? null) || !is_email($action['to']) || !is_string($action['subject'] ?? '') || preg_match('/[\r\n]/', $action['subject'] ?? '') || !is_string($action['message'] ?? ''))) { return Rest::error('configuration_action', 'Invalid email action.'); }
        if ($type === 'webhook' && (!is_string($action['url'] ?? null) || is_wp_error(HttpEndpoint::validate_endpoint($action['url'])) || !is_string($action['secret'] ?? null) || strlen($action['secret']) < 16)) { return Rest::error('configuration_action', 'Webhook requires a safe public HTTPS URL and signing secret.'); }
        if ($type === 'schedule') {
            if (!is_int($action['delay'] ?? 60) || ($action['delay'] ?? 60) < 0 || ($action['delay'] ?? 60) > YEAR_IN_SECONDS) { return Rest::error('configuration_action', 'Invalid schedule delay.'); }
            return self::action($action['action'] ?? null, $depth + 1);
        }
        return null;
    }

    private static function field(array $field) {
        $allowed = ['key', 'name', 'label', 'type', 'required', 'readonly', 'choices', 'default_value', 'instructions', 'read_roles', 'write_roles', 'required_when', 'readonly_when', 'conditional_defaults', 'formula', 'rollup', 'unique', 'relationship', 'vf_rules', 'sub_field', 'sub_fields', 'layouts', 'min', 'max', 'step', 'minlength', 'maxlength', 'multiple', 'allow_null', 'return_format', 'post_type', 'taxonomy', 'filters', 'ui', 'ajax', 'rows', 'new_lines', 'format', 'display_format', 'save_format', 'wrapper', 'conditional_logic', 'function', 'pattern', 'message', 'mask', 'placeholder', 'prepend', 'append', 'business', 'mime_types', 'library', 'preview_size', 'button_label', 'collapsed', 'layout', 'parent', 'menu_order', 'show_in_graphql', 'graphql_field_name', 'graphql_type_name'];
        $allowed = array_merge($allowed, ['read_only', 'mask_autoclear', 'mask_placeholder', 'unique_statuses', 'drafts', 'field_type', 'save_terms', 'load_terms', 'add_term']);
        $error = Rest::keys($field, $allowed);
        if ($error) { return $error; }
        foreach (['name', 'label', 'type', 'key', 'formula'] as $key) { if (isset($field[$key]) && !is_string($field[$key])) { return Rest::error('configuration_field', 'Field identifiers, labels and formulas must be strings.'); } }
        foreach (['required', 'multiple', 'allow_null', 'ui', 'ajax', 'show_in_graphql', 'read_only', 'mask_autoclear', 'drafts'] as $key) { if (isset($field[$key]) && !is_bool($field[$key]) && !in_array($field[$key], [0, 1], true)) { return Rest::error('configuration_field', 'Field flags must be booleans or 0/1.'); } }
        if (isset($field['unique'])) {
            $valid_unique = ($field['type'] ?? '') === 'validated_field' ? in_array($field['unique'], ['non-unique', 'global', 'post_type', 'post_key', 'this_post', 'this_post_key'], true) : (is_bool($field['unique']) || in_array($field['unique'], [0, 1], true));
            if (!$valid_unique) { return Rest::error('configuration_unique', 'Invalid uniqueness configuration.'); }
        }
        if (isset($field['function']) && !in_array($field['function'], ['none', 'regex', 'php'], true)) { return Rest::error('configuration_validation', 'Unknown wrapper validation mode.'); }
        if (($field['type'] ?? '') === 'taxonomy' && isset($field['field_type']) && !in_array($field['field_type'], ['checkbox', 'multi_select', 'radio', 'select'], true)) { return Rest::error('configuration_taxonomy', 'Unknown taxonomy widget.'); }
        foreach (['read_roles', 'write_roles'] as $key) {
            if (isset($field[$key]) && (!Rest::is_list($field[$key]) || count(array_filter($field[$key], 'is_string')) !== count($field[$key]))) { return Rest::error('configuration_roles', 'Roles must be a list of strings.'); }
        }
        foreach (['sub_fields', 'layouts', 'choices', 'vf_rules', 'conditional_defaults'] as $key) {
            if (array_key_exists($key, $field) && !is_array($field[$key])) { return Rest::error('configuration_field', 'Field collections must be arrays or objects.'); }
        }
        foreach (['required_when', 'readonly_when'] as $key) {
            if (isset($field[$key]) && (!is_array($field[$key]) || !Rules::valid($field[$key]))) { return Rest::error('configuration_rule', 'Invalid field condition.'); }
        }
        foreach ($field['vf_rules'] ?? [] as $rule) {
            if (!is_array($rule) || Rest::keys($rule, ['when', 'message']) || !is_array($rule['when'] ?? null) || !Rules::valid($rule['when']) || (isset($rule['message']) && !is_string($rule['message']))) { return Rest::error('configuration_rule', 'Invalid field validation rule.'); }
        }
        foreach ($field['conditional_defaults'] ?? [] as $default) {
            if (!is_array($default) || Rest::keys($default, ['when', 'value']) || !array_key_exists('value', $default) || !is_array($default['when'] ?? null) || !Rules::valid($default['when'])) { return Rest::error('configuration_rule', 'Invalid conditional default.'); }
        }
        if (isset($field['relationship'])) {
            $rel = $field['relationship'];
            if (!is_array($rel) || Rest::keys($rel, ['object', 'kind', 'required', 'on_delete']) || !is_string($rel['object'] ?? null) || !in_array($rel['kind'] ?? 'lookup', ['lookup', 'parent', 'junction'], true) || !in_array($rel['on_delete'] ?? 'restrict', ['restrict', 'nullify'], true) || (isset($rel['required']) && !is_bool($rel['required']))) { return Rest::error('configuration_relationship', 'Invalid relationship configuration.'); }
        }
        if (isset($field['rollup'])) {
            $rollup = $field['rollup'];
            if (!is_array($rollup) || Rest::keys($rollup, ['object', 'relationship_field', 'operation', 'field']) || !is_string($rollup['object'] ?? null) || !is_string($rollup['relationship_field'] ?? null) || !in_array($rollup['operation'] ?? null, ['count', 'sum', 'min', 'max'], true) || (($rollup['operation'] ?? '') !== 'count' && !is_string($rollup['field'] ?? null))) { return Rest::error('configuration_rollup', 'Invalid rollup configuration.'); }
        }
        if (isset($field['business'])) {
            $business = $field['business'];
            if (!is_array($business) || Rest::keys($business, ['preset', 'precision', 'min', 'max', 'allowed', 'required_parts', 'max_days', 'max_bytes', 'mime_types']) || !is_string($business['preset'] ?? null) || !array_key_exists($business['preset'], BusinessFields::presets())) { return Rest::error('configuration_business', 'Invalid business-field configuration.'); }
        }
        if (isset($field['sub_field'])) { if (!is_array($field['sub_field'])) { return Rest::error('configuration_field', 'Wrapped field must be a definition.'); } $error = self::field($field['sub_field']); if ($error) { return $error; } }
        foreach ($field['sub_fields'] ?? [] as $sub) { if (!is_array($sub)) { return Rest::error('configuration_field', 'Nested fields must be definitions.'); } $error = self::field($sub); if ($error) { return $error; } }
        foreach ($field['layouts'] ?? [] as $layout) {
            if (!is_array($layout) || Rest::keys($layout, ['key', 'name', 'label', 'display', 'sub_fields', 'min', 'max'])) { return Rest::error('configuration_layout', 'Malformed layout.'); }
            if (isset($layout['sub_fields']) && !is_array($layout['sub_fields'])) { return Rest::error('configuration_layout', 'Layout sub_fields must be a list.'); }
            foreach ($layout['sub_fields'] ?? [] as $sub) { if (!is_array($sub)) { return Rest::error('configuration_field', 'Layout fields must be definitions.'); } $error = self::field($sub); if ($error) { return $error; } }
        }
        return null;
    }


}
