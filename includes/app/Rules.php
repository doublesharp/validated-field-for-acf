<?php
namespace ACF_VF\App;

/** Bounded declarative conditions shared by validation, defaults, and automation. */
class Rules {
    const OPERATORS = array('eq','ne','contains','starts_with','in','gt','gte','lt','lte','between','empty','not_empty');
    private static $booted = false;

    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        add_filter('acf_vf/app/prepare_fields', array(__CLASS__, 'prepare'), 10, 4);
        add_filter('acf_vf/app/validate_fields', array(__CLASS__, 'validate'), 10, 5);
    }

    /** Validate the entire tree before evaluation, including branches that would short circuit. */
    public static function valid(array $condition): bool {
        $nodes = 0;
        return self::valid_node($condition, 0, $nodes);
    }

    private static function valid_node(array $node, int $depth, int &$nodes): bool {
        if ($depth > 8 || ++$nodes > 128) { return false; }
        foreach (array('all','any') as $join) {
            if (array_key_exists($join, $node)) {
                if (count($node) !== 1 || !is_array($node[$join]) || !$node[$join] || count($node[$join]) > 64) { return false; }
                foreach ($node[$join] as $child) {
                    if (!is_array($child) || !self::valid_node($child, $depth + 1, $nodes)) { return false; }
                }
                return true;
            }
        }
        if (!isset($node['field'], $node['operator']) || !is_string($node['field']) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*){0,3}$/D', $node['field']) || !in_array($node['operator'], self::OPERATORS, true)) { return false; }
        if (array_diff(array_keys($node), array('field','operator','value','value_field'))) { return false; }
        if (in_array($node['operator'], array('empty','not_empty'), true)) { return !isset($node['value_field']); }
        if (array_key_exists('value_field', $node)) {
            return !array_key_exists('value', $node) && is_string($node['value_field']) && (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*){0,3}$/D', $node['value_field']);
        }
        if (!array_key_exists('value', $node)) { return false; }
        if (in_array($node['operator'], array('in','between'), true)) {
            if (!is_array($node['value']) || count($node['value']) > 100 || ($node['operator'] === 'between' && count($node['value']) !== 2)) { return false; }
            foreach ($node['value'] as $value) { if (!is_scalar($value) && $value !== null) { return false; } }
            return true;
        }
        return is_scalar($node['value']) || $node['value'] === null;
    }

    public static function matches(array $condition, array $fields, string $object = '', int $id = 0): bool {
        return self::valid($condition) && self::match_node($condition, $fields, $object, $id);
    }

    /** Check every operand's access before enforcing a condition, without testing its truth. */
    public static function condition_accessible(array $condition, array $values = array(), string $object = '', int $id = 0): bool {
        if (!self::valid($condition)) { return false; }
        return self::accessible_node($condition,$values,$object,$id);
    }

    private static function accessible_node(array $node, array $values, string $object, int $id): bool {
        if (isset($node['all']) || isset($node['any'])) {
            foreach ($node[isset($node['all']) ? 'all' : 'any'] as $child) {
                if (!self::accessible_node($child,$values,$object,$id)) { return false; }
            }
            return true;
        }
        return self::accessible_path($node['field'],$values,$object,$id)
            && (!isset($node['value_field']) || self::accessible_path($node['value_field'],$values,$object,$id));
    }

    private static function accessible_path(string $path, array $values, string $object, int $id): bool {
        $parts=explode('.',$path);
        if ($object==='') { return count($parts)===1; }
        foreach ($parts as $index=>$name) {
            $definitions=Schema::fields($object);
            if (!isset($definitions[$name]) || !Permissions::can('read',$object,$id,$name)) { return false; }
            if ($index===count($parts)-1) { return true; }
            $target=$definitions[$name]['relationship']['object'] ?? '';
            if (!is_string($target) || $target==='' || !Schema::object($target)) { return false; }
            $reference=$values[$name] ?? null;
            $target_id=is_scalar($reference) && ctype_digit((string)$reference) ? (int)$reference : 0;
            if ($target_id && (get_post_type($target_id)!==$target || !Permissions::can('read',$target,$target_id))) { return false; }
            $object=$target; $id=$target_id;
            // An absent relationship still checks schema/role restrictions on its remaining path.
            $next=$parts[$index+1];
            $values=$id ? array($next=>get_post_meta($id,$next,true)) : array();
        }
        return false;
    }

    private static function unavailable_error(): array {
        return array('field'=>'','code'=>'condition_unavailable','message'=>'The record cannot be validated with the current access.');
    }

    private static function match_node(array $node, array $fields, string $object, int $id): bool {
        if (isset($node['all']) || isset($node['any'])) {
            $all = isset($node['all']);
            foreach ($node[$all ? 'all' : 'any'] as $child) {
                $match = self::match_node($child, $fields, $object, $id);
                if ($all && !$match) { return false; }
                if (!$all && $match) { return true; }
            }
            return $all;
        }
        $found = false;
        $left = self::lookup($node['field'], $fields, $object, $id, $found);
        if (!$found) { return false; }
        if (isset($node['value_field'])) {
            $right = self::lookup($node['value_field'], $fields, $object, $id, $found);
            if (!$found) { return false; }
        } else { $right = $node['value'] ?? null; }
        return self::compare($left, $node['operator'], $right);
    }

    /** Dotted paths are single-ID declared relationships, never arbitrary post metadata. */
    public static function lookup(string $path, array $values, string $object, int $id, bool &$found) {
        $found = false;
        $parts = explode('.', $path);
        foreach ($parts as $index => $name) {
            if (!array_key_exists($name, $values)) { return null; }
            if ($object !== '') {
                $definitions = Schema::fields($object);
                if (!isset($definitions[$name]) || !Permissions::can('read', $object, $id, $name)) { return null; }
            }
            $value = $values[$name];
            if ($index === count($parts) - 1) { $found = true; return $value; }
            if ($object === '' || empty($definitions[$name]['relationship']['object']) || !is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1) { return null; }
            $object = $definitions[$name]['relationship']['object'];
            $id = (int)$value;
            if (get_post_type($id) !== $object || !Permissions::can('read', $object, $id)) { return null; }
            $values = array();
            foreach (Schema::fields($object) as $field => $definition) { $values[$field] = get_post_meta($id, $field, true); }
        }
        return null;
    }

    public static function empty_value($value): bool {
        return $value === '' || $value === null || $value === false || $value === array();
    }

    public static function equal($left, $right): bool {
        if (is_array($left) || is_array($right)) { return $left === $right; }
        if (is_numeric($left) && is_numeric($right)) { return (float)$left === (float)$right; }
        return is_scalar($left) && is_scalar($right) ? (string)$left === (string)$right : $left === $right;
    }

    /** Storage and native form values can differ in scalar/empty representation. */
    public static function unchanged($left, $right): bool {
        if (is_array($left) && is_array($right)) {
            if (count($left)!==count($right)) { return false; }
            foreach ($left as $key=>$value) { if (!array_key_exists($key,$right) || !self::unchanged($value,$right[$key])) { return false; } }
            return true;
        }
        if (is_numeric($left) && is_numeric($right)) {
            $left=self::storage_number($left); $right=self::storage_number($right);
            return $left!==null && $right!==null && $left===$right;
        }
        return self::equal($left,$right) || (self::empty_value($left) && self::empty_value($right));
    }

    /** Normalize decimal digits and scale without rounding stored numeric strings. */
    private static function storage_number($value): ?array {
        if (is_float($value)) {
            if (!is_finite($value)) { return array($value); }
            // Find the shortest round-trip representation, independent of PHP's precision settings.
            for ($precision=1; $precision<=17; ++$precision) {
                $text=str_replace(localeconv()['decimal_point'],'.',sprintf('%.'.$precision.'g',$value));
                if ((float)$text===$value) { break; }
            }
        } else { $text=trim((string)$value," \t\n\r\v\f"); }
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D',$text,$parts)!==1) { return null; }
        $fraction=$parts[3] ?? '';
        $digits=ltrim($parts[2].$fraction,'0');
        if ($digits==='') { return array('','0','0'); }
        $sign=$parts[1]==='-' ? '-' : '';
        $coefficient=rtrim($digits,'0');
        $offset=strlen($digits)-strlen($coefficient)-strlen($fraction);
        return array($sign,$coefficient,self::storage_exponent($parts[4] ?? '0',$offset));
    }

    /** Exponents may exceed native integer limits; only the digit-count offset is an int. */
    private static function storage_exponent(string $exponent, int $offset): string {
        $negative=$exponent[0]==='-';
        $digits=ltrim($exponent,'+-0');
        if ($digits==='') { $digits='0'; $negative=false; }
        if ($offset===0) { return ($negative ? '-' : '').$digits; }
        $other=ltrim((string)$offset,'-');
        $other_negative=$offset<0;
        $subtract=$negative!==$other_negative;
        if ($subtract && (strlen($digits)<strlen($other) || (strlen($digits)===strlen($other) && strcmp($digits,$other)<0))) {
            $swap=$digits; $digits=$other; $other=$swap; $negative=$other_negative;
        }
        $result=''; $carry=0;
        for ($i=strlen($digits)-1,$j=strlen($other)-1; $i>=0 || $j>=0 || $carry>0; --$i,--$j) {
            $a=$i>=0 ? (int)$digits[$i] : 0;
            $b=$j>=0 ? (int)$other[$j] : 0;
            $digit=$a+($subtract ? -$b : $b)+$carry;
            if ($subtract) { $carry=$digit<0 ? -1 : 0; if ($digit<0) { $digit+=10; } }
            else { $carry=intdiv($digit,10); $digit%=10; }
            $result.=$digit;
        }
        $result=ltrim(strrev($result),'0');
        if ($result==='') { return '0'; }
        return ($negative ? '-' : '').$result;
    }

    public static function compare($left, string $operator, $right): bool {
        switch ($operator) {
            case 'empty': return self::empty_value($left);
            case 'not_empty': return !self::empty_value($left);
            case 'eq': return self::equal($left, $right);
            case 'ne': return !self::equal($left, $right);
            case 'in':
                if (!is_array($right) || count($right) > 100) { return false; }
                foreach ($right as $item) { if (self::equal($left, $item)) { return true; } }
                return false;
            case 'contains':
                if (is_array($left)) { foreach ($left as $item) { if (self::equal($item,$right)) { return true; } } return false; }
                return is_scalar($left) && is_scalar($right) && strpos((string)$left, (string)$right) !== false;
            case 'starts_with': return is_scalar($left) && is_scalar($right) && strncmp((string)$left, (string)$right, strlen((string)$right)) === 0;
            case 'between': return is_array($right) && count($right) === 2 && self::compare($left, 'gte', array_values($right)[0]) && self::compare($left, 'lte', array_values($right)[1]);
        }
        if (!in_array($operator, array('gt','gte','lt','lte'), true) || !is_scalar($left) || !is_scalar($right)) { return false; }
        if (is_numeric($left) && is_numeric($right)) { $a=(float)$left; $b=(float)$right; }
        elseif (is_string($left) && is_string($right) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$left) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$right)) { $a=$left; $b=$right; }
        else { return false; }
        return $operator === 'gt' ? $a > $b : ($operator === 'gte' ? $a >= $b : ($operator === 'lt' ? $a < $b : $a <= $b));
    }

    public static function prepare(array $fields, string $object, int $id = 0, array $context = array()): array {
        foreach (Schema::fields($object) as $name => $field) {
            // Explicit empty values are intentional; defaults apply to absent values only.
            if (array_key_exists($name, $fields)) { continue; }
            foreach ((array)($field['conditional_defaults'] ?? array()) as $default) {
                if (is_array($default) && isset($default['when']) && is_array($default['when']) && array_key_exists('value',$default) && self::matches($default['when'],$fields,$object,$id)) {
                    $fields[$name]=$default['value']; break;
                }
            }
        }
        return $fields;
    }

    public static function field_errors(array $field, $value, array $values, string $object = '', int $id = 0, $previous = null, array $before = array()): array {
        $name = $field['name'] ?? '';
        $errors = array();
        $comparable=class_exists(NativeFields::class) ? NativeFields::canonical_value($value,$field) : $value;
        $comparable_previous=class_exists(NativeFields::class) ? NativeFields::canonical_value($previous,$field) : $previous;
        $unchanged=self::unchanged($comparable,$comparable_previous);
        if (in_array($field['readonly'] ?? false,array(true,1,'1','true'),true) && empty($field['formula']) && empty($field['rollup']) && !$unchanged) {
            $errors[]=array('field'=>$name,'code'=>'readonly','message'=>'This field is read only.');
        }
        foreach (array('required_when','readonly_when') as $setting) {
            if (empty($field[$setting])) { continue; }
            if (!is_array($field[$setting]) || !self::valid($field[$setting])) {
                $errors[]=array('field'=>$name,'code'=>'rule_configuration','message'=>'The field condition is invalid.'); continue;
            }
            $accessible=self::condition_accessible($field[$setting],$values,$object,$id);
            if ($setting==='readonly_when' && $before) { $accessible=$accessible && self::condition_accessible($field[$setting],$before,$object,$id); }
            if (!$accessible) {
                if (($setting==='required_when' && self::empty_value($value)) || ($setting==='readonly_when' && !$unchanged)) { $errors[]=self::unavailable_error(); }
                continue;
            }
            $matches = self::matches($field[$setting], $values, $object, $id);
            if ($setting === 'readonly_when' && $before) { $matches = $matches || self::matches($field[$setting],$before,$object,$id); }
            if (!$matches) { continue; }
            if ($setting === 'required_when' && self::empty_value($value)) { $errors[]=array('field'=>$name,'code'=>$setting,'message'=>'This field is required by a condition.'); }
            if ($setting === 'readonly_when' && !$unchanged) { $errors[]=array('field'=>$name,'code'=>$setting,'message'=>'This field is read only under the current conditions.'); }
        }
        $rules=$field['vf_rules'] ?? array();
        if (!is_array($rules) || count($rules)>100) { return array_merge($errors,array(array('field'=>$name,'code'=>'rule_configuration','message'=>'The field rules are invalid.'))); }
        foreach ($rules as $rule) {
            if (!is_array($rule) || !isset($rule['when']) || !is_array($rule['when']) || !self::valid($rule['when'])) { $errors[]=array('field'=>$name,'code'=>'rule_configuration','message'=>'A validation condition is invalid.'); continue; }
            if (!self::condition_accessible($rule['when'],$values,$object,$id)) { $errors[]=self::unavailable_error(); continue; }
            if (self::matches($rule['when'],$values,$object,$id)) { $errors[]=array('field'=>$name,'code'=>'business_rule','message'=>self::message($rule)); }
        }
        return $errors;
    }

    private static function message(array $rule): string { return isset($rule['message']) && is_string($rule['message']) ? mb_substr(wp_strip_all_tags($rule['message']),0,300) : 'This value violates a validation rule.'; }

    public static function validate(array $errors, string $object, array $fields, int $id = 0, array $context = array()): array {
        $before=array();
        $definitions=Schema::fields($object);
        foreach ($definitions as $name=>$field) {
            $native_type=$field['type'] ?? '';
            if ($native_type==='validated_field') { $native_type=$field['sub_field']['type'] ?? ''; }
            $aggregate=in_array($native_type,array('group','repeater','flexible_content'),true);
            $before[$name]=$id ? ($aggregate ? get_field($field['key'] ?? $name,$id,false) : get_post_meta($id,$name,true)) : ($field['default_value'] ?? null);
        }
        foreach ($definitions as $name=>$field) {
            $field['name']=$name;
            $errors=array_merge($errors,self::field_errors($field,$fields[$name] ?? null,$fields,$object,$id,$before[$name],$before));
            foreach ((array)($field['conditional_defaults'] ?? array()) as $default) {
                if (!is_array($default) || !isset($default['when']) || !is_array($default['when']) || !self::valid($default['when']) || !array_key_exists('value',$default)) { $errors[]=array('field'=>$name,'code'=>'rule_configuration','message'=>'A conditional default is invalid.'); }
            }
        }
        $definition=Schema::object($object);
        foreach ((array)($definition['rules'] ?? array()) as $rule) {
            if (!is_array($rule) || !isset($rule['when']) || !is_array($rule['when']) || !self::valid($rule['when'])) { $errors[]=array('field'=>'','code'=>'rule_configuration','message'=>'An object rule is invalid.'); continue; }
            if (!self::condition_accessible($rule['when'],$fields,$object,$id)) { $errors[]=self::unavailable_error(); continue; }
            if (self::matches($rule['when'],$fields,$object,$id)) { $errors[]=array('field'=>isset($definitions[$rule['field'] ?? '']) ? $rule['field'] : '', 'code'=>'business_rule','message'=>self::message($rule)); }
        }
        return $errors;
    }
}
