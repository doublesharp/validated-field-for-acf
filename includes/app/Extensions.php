<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Installed providers only. Payment and network state never control runtime registration. */
final class Extensions {
    public const API = 1;
    private static $providers = [];
    private static $features = [];
    private static $closed = false;
    /**
     * Register during acf_vf/app/register_extensions, before the registry closes.
     *
     * @param string $id Unique provider ID, such as validated-field-pro.
     * @param array{api:int,features:string[],register:callable} $definition API contract and hook registrar.
     * @throws \InvalidArgumentException For late, duplicate or incompatible registration.
     */
    public static function register(string $id, array $definition): void {
        if (self::$closed || isset(self::$providers[$id]) || !preg_match('/^[a-z][a-z0-9_.-]*$/D', $id) || ($definition['api'] ?? null) !== self::API || !is_array($definition['features'] ?? null) || !is_callable($definition['register'] ?? null)) {
            throw new \InvalidArgumentException('Invalid, duplicate, incompatible, or late extension registration.');
        }
        foreach ($definition['features'] as $feature) {
            if (!is_string($feature) || !preg_match('/^[a-z][a-z0-9_.]*$/D', $feature) || isset(self::$features[$feature])) { throw new \InvalidArgumentException('Invalid or duplicate feature.'); }
        }
        // Hook registration must succeed before the provider is advertised as usable.
        call_user_func($definition['register']);
        self::$providers[$id] = $definition['features'];
        foreach ($definition['features'] as $feature) { self::$features[$feature] = $id; }
    }
    public static function finish(): void { self::$closed = true; }
    public static function has(string $feature): bool { return isset(self::$features[$feature]); }
    /**
     * @return true|\WP_Error Availability of the installed provider, independent of licensing.
     */
    public static function require_feature(string $feature) {
        return self::has($feature) ? true : new \WP_Error('vf_provider_missing', 'This operation requires the compatible Validated Field Pro add-on.', ['status'=>409,'features'=>[$feature]]);
    }
    public static function metadata(): array { return ['api'=>self::API,'features'=>array_keys(self::$features)]; }
}
