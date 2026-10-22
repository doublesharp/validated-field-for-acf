<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Confidentiality of retained computed values is enforced even without their calculator. */
final class ComputedAccess {
    private static $checking=[];
    public static function can_read(string $object,int $id,string $name,int $user_id): bool {
        $key=$user_id.':'.$object.':'.$id.':'.$name;
        if (isset(self::$checking[$key]) || count(self::$checking)>=40) { return false; }
        self::$checking[$key]=true;
        try {
            $field=Schema::fields($object)[$name] ?? [];
            if (!empty($field['formula'])) {
                if (!is_string($field['formula'])) { return false; }
                $dependencies=ExpressionSyntax::dependencies($field['formula']);
                if (is_wp_error($dependencies)) { return false; }
                foreach ($dependencies as $dependency) { if (!Permissions::can('read',$object,$id,$dependency,$user_id)) { return false; } }
            }
            $config=$field['rollup'] ?? [];
            if (!$config) { return true; }
            if (!is_array($config) || !is_string($config['object'] ?? null) || !is_string($config['relationship_field'] ?? null) || !in_array($config['operation'] ?? '',['count','sum','min','max'],true)) { return false; }
            if (!Permissions::can('read',$config['object'],0,$config['relationship_field'],$user_id)) { return false; }
            if ($config['operation'] !== 'count' && (!is_string($config['field'] ?? null) || !Permissions::can('read',$config['object'],0,$config['field'],$user_id))) { return false; }
            // List columns and generated schemas have no record to inspect yet.
            if (!$id) { return true; }
            foreach (Relationships::incoming($id,$object) as $ref) {
                if ($ref['object'] !== ($config['object'] ?? '') || $ref['field'] !== ($config['relationship_field'] ?? '')) { continue; }
                if (!Permissions::can('read',$ref['object'],$ref['id'],null,$user_id) || (($config['operation'] ?? '') !== 'count' && !Permissions::can('read',$ref['object'],$ref['id'],$config['field'] ?? '',$user_id))) { return false; }
            }
            return true;
        } finally { unset(self::$checking[$key]); }
    }
}
