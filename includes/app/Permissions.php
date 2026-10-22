<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

final class Permissions {
    private static $dependencies = [];
    private static $native_depth = 0;
    public static function checking_native(): bool { return self::$native_depth > 0; }
    public static function can(string $action, string $object, int $id = 0, ?string $field = null, ?int $user_id = null): bool {
        $user_id = $user_id ?? get_current_user_id();
        if (!$user_id) { return false; }
        if ($action === 'configure') { return user_can($user_id, 'manage_options'); }
        $schema = Schema::object($object);
        $type = get_post_type_object($object);
        if (!$schema || !$type || !in_array($action, ['read', 'create', 'update', 'delete', 'export'], true)) { return false; }
        $post = $id ? get_post($id) : null;
        if ($id && (!$post || $post->post_type !== $object)) { return false; }
        if ($post && $post->post_status === 'trash' && (empty($schema['native_table']) || !in_array($action,['read','export'],true) || !user_can($user_id,'edit_post',$id))) { return false; }
        $native = ['read' => 'read_post', 'export' => 'read_post', 'update' => 'edit_post', 'delete' => 'delete_post'];
        self::$native_depth++;
        try {
            $native_allowed = $id && isset($native[$action])
                ? user_can($user_id, $native[$action], $id)
                : user_can($user_id, in_array($action, ['read', 'export'], true) ? 'read' : $type->cap->edit_posts);
        } finally { self::$native_depth--; }
        if (!$native_allowed) { return false; }
        $user = get_userdata($user_id);
        if (!$user) { return false; }
        $access = $schema['access'] ?? [];
        $rule = $access[$action] ?? ($action === 'export' ? ($access['read'] ?? []) : []);
        if (isset($rule['roles']) && !array_intersect($user->roles, $rule['roles'])) { return false; }
        if ($id && !self::scope($rule, $post, $user_id)) { return false; }
        // Read scope remains a prerequisite for operations on existing records.
        if ($id && $action !== 'read' && !self::can('read', $object, $id, null, $user_id)) { return false; }
        if ($field !== null) {
            $definition = $schema['fields'][$field] ?? null;
            if (!$definition) { return false; }
            if (in_array($action, ['read','export'], true) && (!empty($definition['formula']) || !empty($definition['rollup'])) && !ComputedAccess::can_read($object,$id,$field,$user_id)) { return false; }
            $key = in_array($action, ['read', 'export'], true) ? 'read_roles' : 'write_roles';
            if (array_key_exists($key, $definition) && !array_intersect($user->roles, $definition[$key])) { return false; }
            if ($key === 'write_roles' && !self::can('read', $object, $id, $field, $user_id)) { return false; }
        }
        return true;
    }

    public static function can_propose(string $action, string $object, array $values, int $id = 0, ?int $user_id = null): bool {
        $user_id = $user_id ?? get_current_user_id();
        if (!self::can($action, $object, $id, null, $user_id)) { return false; }
        $schema = Schema::object($object);
        $post = $id ? get_post($id) : new \WP_Post((object) ['ID' => 0, 'post_author' => $user_id]);
        foreach ([$schema['access'][$action] ?? [], $schema['access']['read'] ?? []] as $rule) {
            if (!self::scope($rule, $post, $user_id, $values)) { return false; }
        }
        return true;
    }

    private static function scope(array $rule, \WP_Post $post, int $user_id, ?array $values = null): bool {
        switch ($rule['scope'] ?? 'all') {
            case 'own':
                $owner = empty($rule['owner_field']) ? $post->post_author : ($values !== null ? ($values[$rule['owner_field']] ?? null) : get_post_meta($post->ID, $rule['owner_field'], true));
                return is_scalar($owner) && (string) $owner === (string) $user_id;
            case 'team':
                if (empty($rule['team_field'])) { return false; }
                $record_teams = (array) ($values !== null ? ($values[$rule['team_field']] ?? []) : get_post_meta($post->ID, $rule['team_field'], true));
                $user_teams = (array) get_user_meta($user_id, $rule['user_team_meta'] ?? 'vf_app_teams', true);
                $record_teams = array_filter($record_teams, static function ($v) { return is_scalar($v) && (string) $v !== ''; });
                $user_teams = array_filter($user_teams, static function ($v) { return is_scalar($v) && (string) $v !== ''; });
                return (bool) array_intersect(array_map('strval', $record_teams), array_map('strval', $user_teams));
            case 'all': return true;
            default: return false;
        }
    }

    public static function readable_fields(string $object, int $id, array $values): array {
        return array_filter($values, static function ($name) use ($object, $id) { return self::can('read', $object, $id, $name); }, ARRAY_FILTER_USE_KEY);
    }
}
