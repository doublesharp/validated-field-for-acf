<?php
namespace ACF_VF\App;

/** The same declarative rules attach to ordinary ACF fields and legacy wrappers. */
class NativeFields {
    private static $booted=false;
    private static $record_writes=array();
    private static $reading_values=array();
    const JSON_SETTINGS=array('vf_rules','required_when','readonly_when','conditional_defaults','business');

    public static function boot(): void {
        if (self::$booted) { return; } self::$booted=true;
        add_action('acf/render_field_settings',array(__CLASS__,'settings'),30);
        add_filter('acf/update_field',array(__CLASS__,'normalize'),20);
        add_filter('acf/validate_value',array(__CLASS__,'validate_native_value'),25,4);
        add_action('acf/validate_save_post',array(__CLASS__,'validate_native_form'),25);
        add_filter('acf/prepare_field',array(__CLASS__,'prepare_field'),25);
        add_filter('acf/load_value',array(__CLASS__,'load_default'),25,3);
        add_filter('acf/update_value',array(__CLASS__,'preserve_computed'),25,3);
    }

    /** Consumed by the central editor and API schema, without executable validation snippets. */
    public static function metadata(): array {
        return apply_filters('acf_vf/app/editor_metadata', array('operators'=>Rules::OPERATORS,'functions'=>[],'presets'=>BusinessFields::presets(),
            'condition'=>array('any'=>array(array('field'=>'state','operator'=>'eq','value'=>'open'))),
            'cross_field'=>array('field'=>'end','operator'=>'lt','value_field'=>'start'),
            'settings'=>array('vf_rules'=>'array of {when, message}','required_when'=>'condition','readonly_when'=>'condition','conditional_defaults'=>'array of {when, value}','formula'=>'arithmetic expression','business'=>'{preset, ...constraints}'),
            'limits'=>array('condition_depth'=>8,'condition_nodes'=>128,'formula_characters'=>4096,'formula_tokens'=>512,'formula_functions_arguments'=>32)));
    }

    public static function settings(array $field): void {
        if (!function_exists('acf_render_field_setting')) { return; }
        $labels=array('vf_rules'=>'Validation rules','required_when'=>'Required when','readonly_when'=>'Read only when','conditional_defaults'=>'Conditional defaults','business'=>'Business preset and constraints');
        foreach (self::JSON_SETTINGS as $key) {
            $display=$field;
            if (isset($display[$key]) && is_array($display[$key])) { $display[$key]=wp_json_encode($display[$key],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); }
            acf_render_field_setting($display,array('label'=>$labels[$key],'instructions'=>'Optional JSON. Configure visual rules in the Business applications editor. Invalid conditions block saves.','name'=>$key,'type'=>'textarea','rows'=>3));
        }
        do_action('acf_vf/app/native_settings',$field);
    }

    public static function normalize(array $field): array {
        foreach (self::JSON_SETTINGS as $key) {
            if (!isset($field[$key]) || !is_string($field[$key])) { continue; }
            if (trim($field[$key])==='') { unset($field[$key]); continue; }
            $decoded=json_decode($field[$key],true,16);
            // Preserve invalid text so validation rejects a misconfigured field visibly.
            if (json_last_error()===JSON_ERROR_NONE && is_array($decoded)) { $field[$key]=$decoded; }
        }
        return $field;
    }

    private static function extended(array $field): bool {
        foreach (array_merge(self::JSON_SETTINGS,array('formula','readonly')) as $key) { if (isset($field[$key]) && $field[$key]!=='' && $field[$key]!==array()) { return true; } }
        return false;
    }

    /** Obtain registered field names from ACF's submitted tree, including concrete nested paths. */
    public static function form_values($post_id, array $current=array(), $current_value=null): array {
        $values=array(); $definitions=array(); $before=array();
        if ($post_id && function_exists('get_field_objects')) {
            foreach ((array)get_field_objects($post_id,false,false) as $field) { if (!is_array($field) || empty($field['name'])) { continue; } $definitions[$field['name']]=$field; }
        }
        $entries=class_exists('ACF_VF_Validation') ? \ACF_VF_Validation::submitted_entries(null,$post_id) : array();
        foreach ($entries as $entry) { $field=$entry['field']; $field['name']=$entry['name']; $definitions[$entry['name']]=$field; }
        if (!empty($current['name'])) { $definitions[$current['name']]=$current; }
        foreach ($definitions as $name=>$field) { $values[$name]=$post_id ? self::stored_value($field,$post_id) : ($field['default_value'] ?? null); $before[$name]=$values[$name]; }
        foreach ($entries as $entry) { $values[$entry['name']]=$entry['value']; }
        if (!empty($current['name'])) { $values[$current['name']]=$current_value; }
        return array($values,$definitions,$before);
    }

    private static function stored_value(array $field, $post_id) {
        $type=$field['type'] ?? '';
        if ($type==='validated_field') { $type=$field['sub_field']['type'] ?? ''; }
        $key=(string)$post_id.':'.$field['name'];
        if (!in_array($type,array('group','repeater','flexible_content'),true) || isset(self::$reading_values[$key])) { return acf_get_metadata($post_id,$field['name']); }
        self::$reading_values[$key]=true;
        try { return get_field($field['key'] ?? $field['name'],$post_id,false); }
        finally { unset(self::$reading_values[$key]); }
    }

    /** Compare API child names and native registered child keys using one representation. */
    public static function canonical_value($value, array $field) { return self::named_value($value,$field); }

    private static function named_value($value, array $field) {
        if (($field['type'] ?? '')==='validated_field') { $field=array_replace($field,$field['sub_field'] ?? array()); }
        $type=$field['type'] ?? '';
        // ACF expands a missing group into empty child inputs during validation.
        if ($type==='group' && Rules::empty_value($value)) { $value=array(); }
        if (!is_array($value)) { return $value; }
        if ($type==='group') {
            foreach ((array)($field['sub_fields'] ?? array()) as $child) {
                if (!is_array($child) || !isset($child['name'],$child['key'])) { continue; }
                if (array_key_exists($child['key'],$value)) { $value[$child['name']]=$value[$child['key']]; unset($value[$child['key']]); }
                $value[$child['name']]=self::named_value($value[$child['name']] ?? null,$child);
            }
        } elseif ($type==='repeater' || $type==='flexible_content') {
            foreach ($value as &$row) {
                $children=$field['sub_fields'] ?? array();
                if ($type==='flexible_content') { foreach ((array)($field['layouts'] ?? array()) as $layout) { if (($layout['name'] ?? '')===($row['acf_fc_layout'] ?? null)) { $children=$layout['sub_fields'] ?? array(); break; } } }
                $row=self::named_value($row,array('type'=>'group','sub_fields'=>$children));
            } unset($row);
        }
        return $value;
    }

    private static function application_context(array $field, $post_id): array {
        if (!class_exists(Schema::class)) { return array('',0); }
        $id=is_numeric($post_id) ? (int)$post_id : 0;
        $key=$field['key'] ?? '';
        foreach (Schema::objects() as $object=>$schema) {
            if ($id && get_post_type($id)!==$object) { continue; }
            foreach ((array)($schema['fields'] ?? array()) as $definition) {
                if (is_array($definition) && self::contains_key($definition,$key)) { return array($object,$id); }
            }
        }
        return array('',0);
    }

    public static function contains_key(array $definition, string $key): bool {
        if (($definition['key'] ?? '')===$key && $key!=='') { return true; }
        foreach ((array)($definition['sub_fields'] ?? array()) as $child) { if (is_array($child) && self::contains_key($child,$key)) { return true; } }
        foreach ((array)($definition['layouts'] ?? array()) as $layout) {
            foreach ((array)($layout['sub_fields'] ?? array()) as $child) { if (is_array($child) && self::contains_key($child,$key)) { return true; } }
        }
        return false;
    }

    /** Omitted/hidden computed inputs must not allow their ordinary sources to change. */
    public static function validate_native_form(): void {
        if (!class_exists('ACF_VF_Validation')) { return; }
        $post_id=\ACF_VF_Validation::context();
        $entries=\ACF_VF_Validation::submitted_entries(null,$post_id);
        if (!$entries) { return; }
        $definitions=$post_id && function_exists('get_field_objects') ? (array)get_field_objects($post_id,false,false) : [];
        $parents=[];
        foreach ($entries as $entry) {
            $field=$entry['field'];
            $definitions[]=$field;
            // Read registered siblings, including fields absent from POST and metadata.
            // Climb containers so formulas outside a submitted group are also retained.
            for ($depth=0; $depth<16 && !empty($field['parent']); ++$depth) {
                $parent=$field['parent'];
                if (isset($parents[$parent])) { break; }
                $parents[$parent]=true;
                if (function_exists('acf_get_fields')) { $definitions=array_merge($definitions,(array)acf_get_fields($parent)); }
                $field=function_exists('acf_get_field') ? acf_get_field($parent) : false;
                if (!is_array($field)) { break; }
            }
        }
        foreach ($definitions as $definition) {
            if (!is_array($definition)) { continue; }
            foreach (Requirements::for_definition($definition) as $feature) {
                if (!Extensions::has($feature)) {
                    acf_add_validation_error('', 'A required Pro runtime provider is unavailable. Restore the compatible add-on before changing this form.');
                    return;
                }
            }
        }
    }

    public static function validate_native_value($valid, $value, array $field, $input) {
        if ($valid!==true || !self::extended($field)) { return $valid; }
        $field=self::normalize($field);
        foreach (Requirements::for_definition($field) as $feature) { if (!Extensions::has($feature)) { return 'A required Pro runtime provider is unavailable.'; } }
        $post_id=class_exists('ACF_VF_Validation') ? \ACF_VF_Validation::context() : 0;
        $entries=class_exists('ACF_VF_Validation') ? \ACF_VF_Validation::submitted_entries(null,$post_id) : array();
        $original=$field['name'];
        if (isset($entries[$input])) { $field['name']=$entries[$input]['name']; }
        list($values,$definitions,$before)=self::form_values($post_id,$field,$value);
        $previous=$before[$field['name']] ?? ($field['default_value'] ?? null);
        if (isset($entries[$input]) && $entries[$input]['previous_name']!==$field['name']) { $previous=acf_get_metadata($post_id,$entries[$input]['previous_name']); }
        list($object,$record_id)=self::application_context($field,$post_id);
        $errors=Rules::field_errors($field,self::named_value($value,$field),$values,$object,$record_id,self::named_value($previous,$field),$before);
        $retained_file=($field['business']['preset'] ?? '')==='file' && Records::derived_native_unchanged($object,$record_id,$field,$value);
        $business=BusinessFields::validate_value($value,$field,$retained_file);
        if ($business!==true) { return $business; }
        foreach ((array)($field['conditional_defaults'] ?? array()) as $default) { if (!is_array($default) || !isset($default['when']) || !is_array($default['when']) || !Rules::valid($default['when']) || !array_key_exists('value',$default)) { return 'A conditional default is invalid.'; } }
        $extended=apply_filters('acf_vf/app/native_validate',true,$field,$definitions,$values);
        if (is_wp_error($extended)) { return $extended->get_error_message(); }
        return $errors ? $errors[0]['message'] : true;
    }

    public static function prepare_field($field) {
        if (!is_array($field) || !self::extended($field)) { return $field; }
        $field=self::normalize($field);
        $post_id=class_exists('ACF_VF_Validation') ? \ACF_VF_Validation::context() : 0;
        list($values)=self::form_values($post_id,$field,$field['value'] ?? null);
        list($object,$record_id)=self::application_context($field,$post_id);
        if (!empty($field['formula']) || (isset($field['readonly_when']) && is_array($field['readonly_when']) && (!Rules::condition_accessible($field['readonly_when'],$values,$object,$record_id) || Rules::matches($field['readonly_when'],$values,$object,$record_id)))) { $field['readonly']=1; }
        if (isset($field['required_when']) && is_array($field['required_when']) && (!Rules::condition_accessible($field['required_when'],$values,$object,$record_id) || Rules::matches($field['required_when'],$values,$object,$record_id))) { $field['required']=1; }
        return $field;
    }

    public static function load_default($value, $post_id, array $field) {
        if ($value!==null || empty($field['conditional_defaults'])) { return $value; }
        $field=self::normalize($field);
        list($values)=self::form_values($post_id,$field,$value);
        list($object,$record_id)=self::application_context($field,$post_id);
        foreach ((array)$field['conditional_defaults'] as $default) {
            if (is_array($default) && isset($default['when']) && is_array($default['when']) && array_key_exists('value',$default) && Rules::matches($default['when'],$values,$object,$record_id)) { return $default['value']; }
        }
        return $value;
    }

    /** Core brackets its already validated native persistence loop with this stack. */
    public static function begin_record_write(string $object, int $id, array $values): void {
        self::$record_writes[]=array('object'=>$object,'id'=>$id,'values'=>$values);
    }

    public static function end_record_write(): void { array_pop(self::$record_writes); }

    public static function record_write() { return end(self::$record_writes); }
    public static function preserve_computed($value,$post_id,array $field) {
        foreach (Requirements::for_definition($field) as $feature) { if (!Extensions::has($feature)) { return acf_get_metadata($post_id,$field['name']); } }
        return $value;
    }

}
