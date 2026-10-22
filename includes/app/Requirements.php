<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Derive runtime requirements from configuration; never accept a browser's claim. */
final class Requirements {
    private const KEYS = ['formula'=>'calculations.formula','rollup'=>'calculations.rollup','automation'=>'automation.actions','approvals'=>'automation.lifecycle','transitions'=>'automation.lifecycle'];
    public static function for_definition(array $definition, int $depth = 0): array {
        if ($depth > 16) { return ['configuration.unknown']; }
        $result = [];
        $object = isset($definition['slug']) || isset($definition['fields']);
        foreach ($object ? ['automation','approvals','transitions'] : ['formula','rollup'] as $key) {
            if (isset($definition[$key]) && $definition[$key] !== '' && $definition[$key] !== []) { $result[] = self::KEYS[$key]; }
        }
        foreach (self::children($definition) as $child) { $result=array_merge($result,self::for_definition($child,$depth+1)); }
        return array_values(array_unique($result));
    }
    private static function children(array $definition): array {
        $children=[];
        foreach (['fields','sub_fields'] as $key) { foreach ($definition[$key] ?? [] as $child) { if (is_array($child)) { $children[]=$child; } } }
        if (is_array($definition['sub_field'] ?? null)) { $children[]=$definition['sub_field']; }
        foreach ($definition['layouts'] ?? [] as $layout) { foreach ($layout['sub_fields'] ?? [] as $child) { if (is_array($child)) { $children[]=$child; } } }
        return $children;
    }
    private static function rollup_sources(array $definition,int $depth=0): array {
        if ($depth>16) { return ['__unknown']; }
        $sources=isset($definition['rollup']['object']) ? [$definition['rollup']['object']] : [];
        foreach (self::children($definition) as $child) { $sources=array_merge($sources,self::rollup_sources($child,$depth+1)); }
        return $sources;
    }
    public static function for_object(string $object): array {
        $objects = Schema::objects(); $visited = []; $result = [];
        $visit = static function ($slug) use (&$visit, &$visited, &$result, $objects): void {
            if (isset($visited[$slug])) { return; }
            if (count($visited) >= 1000 || !isset($objects[$slug])) { $result[]='configuration.unknown'; return; }
            $visited[$slug]=true;
            $result=array_merge($result,self::for_definition($objects[$slug]));
            // A source mutation may invalidate a rollup in another object, transitively.
            foreach ($objects as $parent=>$definition) {
                if (in_array($slug,self::rollup_sources($definition),true)) { $visit($parent); }
            }
        };
        $visit($object);
        return array_values(array_unique($result));
    }
    public static function assert_operation(string $operation, string $object, int $id = 0, array $context = []) {
        $missing=array_values(array_filter(self::for_object($object), static function ($feature) { return !Extensions::has($feature); }));
        return $missing ? new \WP_Error('vf_provider_missing','A required runtime provider is missing. Restore the compatible Pro add-on before changing these records.',['status'=>409,'features'=>$missing]) : true;
    }
    public static function validate_definition(array $definition, ?array $previous = null) {
        // Preserve unchanged premium settings while allowing safe labels/basic configuration edits.
        foreach (self::KEYS as $key=>$feature) {
            if (Extensions::has($feature)) { continue; }
            if (self::settings($definition,$key) !== self::settings($previous ?? [],$key)) { return Extensions::require_feature($feature); }
        }
        return true;
    }
    private static function settings(array $value,string $key): array {
        $out=[];
        $object=isset($value['slug']) || isset($value['fields']);
        if (($object === in_array($key,['automation','approvals','transitions'],true)) && isset($value[$key]) && $value[$key] !== '' && $value[$key] !== []) { $out[$key]=$value[$key]; }
        foreach (self::children($value) as $index=>$child) { $nested=self::settings($child,$key); if ($nested) { $out['children'][$index]=$nested; } }
        return $out;
    }
}
