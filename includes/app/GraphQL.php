<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Delegates validated wrappers to WPGraphQL for ACF's native field-type registry. */
final class GraphQL {
    private static $booted = false;
    private static $registered = false;
    private static $input_types = [];

    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        // The optional integration may be absent. Never load its classes at plugin load.
        add_action('wpgraphql/acf/registry_init', [__CLASS__, 'register']);
        if (function_exists('register_graphql_acf_field_type')) { self::register(); }
        add_filter('wpgraphql/acf/should_field_group_show_in_graphql', [__CLASS__, 'group_visibility'], 20, 2);
    }

    public static function group_visibility(bool $show, array $group): bool {
        // Application objects are served by the permission-aware record API. A separate
        // native WPGraphQL post connection would bypass object scope and private totals.
        foreach (Schema::objects() as $slug => $definition) {
            if (($group['key'] ?? '') === 'group_vfa_' . $slug) { return false; }
        }
        return $show;
    }

    public static function register(): void {
        if (self::$registered || !function_exists('register_graphql_acf_field_type')) { return; }
        self::$registered = true;
        register_graphql_acf_field_type('validated_field', [
            'exclude_admin_fields' => ['graphql_non_null'],
            'graphql_type' => static function ($config) {
                [$native, $proxy] = self::adapter($config);
                // Unsupported native field types remain absent, never stringified.
                return $native ? $native->get_resolve_type($proxy) : 'NULL';
            },
            'resolve' => static function ($root, $args, $context, $info, $field_type, $config) {
                [$native, $proxy] = self::adapter($config);
                if (!$native) { return null; }
                self::authorize($root, $proxy->get_acf_field());
                $value = $native->get_resolver($root, $args, $context, $info, $native, $proxy);
                $type = $proxy->get_acf_field()['type'];
                if ($type === 'true_false') { return (bool) $value; }
                if (in_array($type, ['select', 'checkbox'], true)) { return $value === null ? null : (is_array($value) ? $value : [$value]); }
                return $value;
            },
        ]);
    }

    private static function adapter($config): array {
        if (!class_exists('WPGraphQL\\Acf\\FieldConfig') || !class_exists('WPGraphQL\\Acf\\Utils')) { return [null, null]; }
        $wrapper = $config->get_acf_field();
        $field = self::unwrap($wrapper);
        if (!$field) { return [null, null]; }
        $native = \WPGraphQL\Acf\Utils::get_graphql_field_type($field['type']);
        if (!$native) { return [null, null]; }
        // FieldConfig's native methods register the nested objects and connections.
        // Overridden getters expose native settings while retaining storage identity.
        $proxy = new class($field, $config->get_acf_field_group(), $config->get_registry()) extends \WPGraphQL\Acf\FieldConfig {
            private $native_field;
            public function __construct(array $field, array $group, \WPGraphQL\Acf\Registry $registry) {
                parent::__construct($field, $group, $registry);
                $this->native_field = $field;
            }
            public function get_acf_field(): array { return $this->native_field; }
            public function get_raw_acf_field(): array { return $this->native_field; }
            public function resolve_field($root, array $args, \WPGraphQL\AppContext $context, \GraphQL\Type\Definition\ResolveInfo $info, $acf_field = []) {
                GraphQL::authorize($root, $this->native_field);
                return parent::resolve_field($root, $args, $context, $info, array_replace($this->native_field, $acf_field));
            }
        };
        return [$native, $proxy];
    }

    public static function unwrap(array $wrapper): ?array {
        $sub = $wrapper['sub_field'] ?? null;
        if (!is_array($sub) || !is_string($sub['type'] ?? null) || $sub['type'] === 'validated_field' || $sub['type'] === '') { return null; }
        $field = array_replace($wrapper, $sub);
        // The wrapper owns identity, parent, visibility and GraphQL naming. Native settings
        // such as choices, post_type, sub_fields and layouts come from the underlying field.
        foreach (['key', 'name', '_name', '__key', 'parent', 'parent_layout', 'parent_layout_group', 'label', 'show_in_graphql', 'graphql_field_name', 'graphql_type_name'] as $key) {
            if (array_key_exists($key, $wrapper)) { $field[$key] = $wrapper[$key]; }
        }
        unset($field['sub_field'], $field['graphql_non_null'], $field['graphql_resolve_type'], $field['graphql_resolver']);
        return $field;
    }

    public static function authorize($root, array $field): void {
        $node = is_array($root) ? ($root['node'] ?? null) : $root;
        $id = $node ? \WPGraphQL\Acf\Utils::get_node_acf_id($node) : 0;
        if (!is_numeric($id) || (int) $id < 1) { return; }
        $id = (int) $id;
        $object = get_post_type($id);
        $denied = false;
        if ($object && Schema::managed_object($object)) {
            // Private application data never gets a second API permission model.
            $denied = !Permissions::can('read', $object, $id, $field['name'] ?? '');
        } elseif ($object) {
            $post = get_post($id);
            $denied = !is_post_publicly_viewable($post) && !current_user_can('read_post', $id);
        }
        if ($denied) { throw new \GraphQL\Error\UserError('Field is unavailable.'); }
    }

}
