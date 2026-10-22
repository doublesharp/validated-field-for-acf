<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Permission-filtered queries. Pagination and metrics share the same authorized set. */
final class Query {
    public const MAX_FILTER_DEPTH = 8;
    private const OPERATORS = ['eq','ne','contains','starts_with','in','gt','gte','lt','lte','between','empty','not_empty','relative_date'];

    public static function run(string $object, array $args = [], ?array $native_statuses = null) {
        if (!Schema::object($object)) { return self::error('Object is unavailable.',404); }
        $current = Schema::assert_current($object);
        if (is_wp_error($current)) { return $current; }
        if (!Permissions::can('read',$object)) { return self::error('This object cannot be read.',403); }
        $statuses=self::statuses($object,$native_statuses);
        if (is_wp_error($statuses)) { return $statuses; }
        if (array_diff(array_keys($args),['page','per_page','search','sort','filters','metrics'])) { return self::error('Unknown query option.'); }
        $page=$args['page']??1; $per_page=$args['per_page']??25;
        if (filter_var($page,FILTER_VALIDATE_INT)===false || $page<1 || filter_var($per_page,FILTER_VALIDATE_INT)===false || $per_page<1 || $per_page>100) { return self::error('Page must be positive; per_page must be between 1 and 100.'); }
        if (isset($args['search']) && (!is_string($args['search']) || strlen($args['search'])>200)) { return self::error('Search must be text of at most 200 bytes.'); }
        $definitions=Schema::fields($object); $types=self::types($object); $used=[]; $leaves=0; $index_safe=true;
        $filters=self::filters($args['filters']??[],$object,$definitions,$types,$used,$leaves,$index_safe);
        if (is_wp_error($filters)) { return $filters; }
        $sort=$args['sort']??['field'=>'id','direction'=>'DESC'];
        if (!is_array($sort) || !$sort) { return self::error('Invalid sort field or direction.'); }
        if (array_key_exists('field',$sort)) { $sort=[$sort]; }
        elseif (!self::is_list($sort) || count($sort)>10) { return self::error('Sort must contain at most 10 columns.'); }
        if (count($sort)>1) { $pro=self::advanced(); if (is_wp_error($pro)) { return $pro; } }
        foreach ($sort as &$column) {
            if (!is_array($column) || array_diff(array_keys($column),['field','direction','mode'])) { return self::error('Invalid sort column.'); }
            $name=$column['field']??null; $valid=self::field($name,$object,$definitions,$types);
            if (is_wp_error($valid)) { return $valid; }
            if (!is_string($column['direction']??null) || !in_array(strtoupper($column['direction']),['ASC','DESC'],true)) { return self::error('Invalid sort direction.'); }
            $mode=self::mode($column['mode']??'raw',$definitions[$name]??[]);
            if (is_wp_error($mode)) { return $mode; }
            if ($mode==='raw' && $types[$name]==='list') { return self::error('List sorting requires a display mode.'); }
            if ($mode!=='raw') { $index_safe=false; }
            $column['mode']=$mode; $used[$name]=true;
        }
        unset($column);
        $metrics=self::metrics($args['metrics']??[],$object,$definitions,$types,$used);
        if (is_wp_error($metrics)) { return $metrics; }
        $items=[]; $keys=[]; $examined=0;
        $scan_limit=max(1,min(10000,(int)apply_filters('acf_vf/app/query_scan_limit',10000,$object)));
        $work=0; $work_limit=max(1,min(10000,(int)apply_filters('acf_vf/app/query_work_limit',10000,$object)));
        $consume=static function(int $cost=1)use(&$work,$work_limit): bool {
            if ($cost>$work_limit-$work) { return false; }
            $work+=$cost; return true;
        };
        // Index accepts flat conjunctions only. Transformed/date predicates must scan.
        $candidates=$index_safe && class_exists(Index::class) && apply_filters('acf_vf/app/use_index',true,$object,$args)
            ? Index::candidates($object,$filters['conditions'],$types,$scan_limit,array_keys($used),$statuses,$consume) : null;
        if (is_wp_error($candidates)) { return $candidates; }
        for ($batch=1;;$batch++) {
            $query_args=['post_type'=>$object,'post_status'=>$statuses,'posts_per_page'=>200,'paged'=>$batch,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,'suppress_filters'=>false];
            if (is_array($candidates)) { $query_args['post__in']=$candidates?:[0]; }
            $query_args=apply_filters('acf_vf/app/query_post_args',$query_args,$object);
            $query=new \WP_Query($query_args);
            foreach ($query->posts as $id) {
                if (!$consume()) { return self::work_capacity(); }
                $record=Records::read((int)$id);
                if (is_wp_error($record)) { continue; }
                foreach ($used as $name=>$_) { if (isset($definitions[$name]) && !array_key_exists($name,$record['fields'])) { continue 2; } }
                // Resolve every display dependency before capacity and OR evaluation.
                // Hidden targets must not influence errors, totals, or ordering.
                $values=[];
                foreach ($used as $name=>$_) {
                    $value=self::value($record,$name);
                    if (is_array($value) && !$consume(count($value))) { return self::work_capacity(); }
                    $values[$name.':raw']=$value;
                }
                $resolved=self::resolve_filters($filters,$record,$definitions,$values,$consume);
                if (is_wp_error($resolved)) { return $resolved; }
                if (!$resolved) { continue; }
                $row_keys=[];
                foreach ($sort as $column) {
                    $value=self::resolved($record,$column['field'],$column['mode'],$definitions,$values,$consume);
                    if (is_wp_error($value)) { if ($value->get_error_code()==='query_work_capacity') { return $value; } continue 2; }
                    $row_keys[]=self::comparable($value,$definitions[$column['field']]??[],$column['mode']==='raw'?$types[$column['field']]:'text');
                }
                if (++$examined>$scan_limit) { return new \WP_Error('query_capacity','This query exceeds the configured processing limit.',['status'=>413]); }
                if (isset($args['search']) && $args['search']!=='') {
                    $match=stripos($record['title'],$args['search'])!==false;
                    foreach ($record['fields'] as $name=>$value) { if (($types[$name]??'')==='text' && is_scalar($value) && stripos((string)$value,$args['search'])!==false) { $match=true; break; } }
                    if (!$match) { continue; }
                }
                if (!self::tree_matches($filters,$values,$definitions,$types)) { continue; }
                $keys[$record['id']]=$row_keys; $items[]=$record;
            }
            if (count($query->posts)<200) { break; }
        }
        usort($items,static function($left,$right)use($sort,$keys){
            foreach ($sort as $i=>$column) {
                $a=$keys[$left['id']][$i]; $b=$keys[$right['id']][$i];
                $comparison=$a===null || $b===null ? (($a!==null)<=>($b!==null)) : (is_float($a)||is_int($a) ? ($a<=>$b) : strnatcasecmp($a,$b));
                if ($comparison) { return $comparison*(strtoupper($column['direction'])==='ASC'?1:-1); }
            }
            return ($left['id']<=>$right['id'])*(strtoupper($sort[0]['direction'])==='ASC'?1:-1);
        });
        $aggregates=self::aggregate($items,$metrics);
        if (is_wp_error($aggregates)) { return $aggregates; }
        $current=Schema::assert_current($object);
        if (is_wp_error($current)) { return $current; }
        $total=count($items);
        $total_pages=(int)ceil($total/$per_page);
        $page_items=(int)$page>$total_pages?[]:array_slice($items,((int)$page-1)*(int)$per_page,(int)$per_page);
        $result=['items'=>$page_items,'total'=>$total,'page'=>(int)$page,'per_page'=>(int)$per_page,'total_pages'=>$total_pages];
        if (array_key_exists('metrics',$args)) { $result['metrics']=$aggregates; }
        return $result;
    }

    /** Status context is supplied by trusted native adapters, never by query payloads. */
    public static function statuses(string $object,?array $native_statuses=null) {
        $native=!empty(Schema::object($object)['native_table']);
        if (!$native) { return $native_statuses===null?['publish','private','draft','pending']:self::error('Native status context requires a native table.'); }
        $statuses=$native_statuses??NativeTables::statuses();
        if (!$statuses || !self::is_list($statuses) || count($statuses)>50) { return self::error('Native tables support at most 50 registered statuses.'); }
        foreach ($statuses as $status) { if (!is_string($status) || !get_post_status_object($status)) { return self::error('Unsupported native status.'); } }
        return array_values(array_unique($statuses));
    }

    public static function types(string $object): array {
        $types=['id'=>'number','title'=>'text','status'=>'text','version'=>'number'];
        foreach (Schema::fields($object) as $name=>$field) {
            $field=self::definition($field); $type=$field['type'];
            if (in_array($type,['number','range','true_false','user','post_object','image','file'],true) && empty($field['multiple'])) { $types[$name]='number'; }
            elseif (in_array($type,['text','textarea','email','url','password','select','radio','button_group','date_picker','date_time_picker','time_picker','color_picker'],true) && empty($field['multiple'])) { $types[$name]='text'; }
            elseif (in_array($type,['checkbox','relationship','gallery'],true) || !empty($field['multiple'])) { $types[$name]='list'; }
        }
        return $types;
    }

    private static function filters($input,string $object,array $definitions,array $types,array &$used,int &$leaves,bool &$index_safe,int $depth=0) {
        if (!is_array($input) || $depth>self::MAX_FILTER_DEPTH) { return self::error('Invalid filter tree or depth.'); }
        $group=array_key_exists('relation',$input)||array_key_exists('conditions',$input);
        if ($group) {
            $pro=self::advanced(); if (is_wp_error($pro)) { return $pro; }
            $index_safe=false;
            if (array_diff(array_keys($input),['relation','conditions']) || !in_array($input['relation']??null,['AND','OR'],true) || !is_array($input['conditions']??null) || !$input['conditions']) { return self::error('Malformed filter group.'); }
            $conditions=$input['conditions']; $relation=$input['relation'];
        } else { $conditions=$input; $relation='AND'; }
        if (!self::is_list($conditions) || count($conditions)>50) { return self::error('Filters must contain at most 50 conditions.'); }
        $normalized=[];
        foreach ($conditions as $filter) {
            if (is_array($filter) && (array_key_exists('relation',$filter)||array_key_exists('conditions',$filter))) {
                $node=self::filters($filter,$object,$definitions,$types,$used,$leaves,$index_safe,$depth+1);
                if (is_wp_error($node)) { return $node; }
                $normalized[]=$node; continue;
            }
            if (++$leaves>50 || !is_array($filter) || array_diff(array_keys($filter),['field','operator','value','mode'])) { return self::error('Malformed filter or too many conditions.'); }
            $name=$filter['field']??null; $operator=$filter['operator']??null;
            $valid=self::field($name,$object,$definitions,$types); if (is_wp_error($valid)) { return $valid; }
            if (!is_string($operator) || !in_array($operator,self::OPERATORS,true)) { return self::error('Unsupported operator.'); }
            $mode=self::mode($filter['mode']??'raw',$definitions[$name]??[]); if (is_wp_error($mode)) { return $mode; }
            $type=$mode==='raw'?$types[$name]:'text';
            if ($mode!=='raw' || self::is_date($definitions[$name]??[])) { $index_safe=false; }
            if (in_array($operator,['contains','starts_with'],true) && $type==='number') { return self::error('Text operators require text or list fields.'); }
            if ($type==='list' && !in_array($operator,['contains','empty','not_empty'],true)) { return self::error('List fields support contains, empty and not_empty.'); }
            $used[$name]=true;
            if ($operator==='relative_date') {
                $pro=self::advanced(); if (is_wp_error($pro)) { return $pro; }
                if (!in_array(self::definition($definitions[$name]??[])['type']??'',['date_picker','date_time_picker'],true) || $mode!=='raw') { return self::error('Relative dates require a calendar date field.'); }
                $range=self::relative($filter['value']??null); if (is_wp_error($range)) { return $range; }
                $normalized[]=['field'=>$name,'operator'=>'between','value'=>$range,'mode'=>'raw']; continue;
            }
            if (!in_array($operator,['empty','not_empty'],true)) {
                if (!array_key_exists('value',$filter)) { return self::error('Filter value is required.'); }
                $multiple=in_array($operator,['in','between'],true);
                if ($multiple && (!is_array($filter['value']) || !self::is_list($filter['value']) || !$filter['value'] || count($filter['value'])>100 || ($operator==='between' && count($filter['value'])!==2))) { return self::error('Invalid filter value list.'); }
                $values=[];
                foreach ($multiple?$filter['value']:[$filter['value']] as $value) {
                    $typed=self::typed($value,$type); if (is_wp_error($typed)) { return $typed; }
                    if ($mode==='raw' && self::is_date($definitions[$name]??[])) {
                        $typed=self::date($typed,$definitions[$name]); if ($typed===null) { return self::error('Invalid date filter value.'); }
                    }
                    $values[]=$typed;
                }
                $filter['value']=$multiple?$values:$values[0];
            }
            $filter['mode']=$mode; $normalized[]=$filter;
        }
        return ['relation'=>$relation,'conditions'=>$normalized];
    }

    private static function field($name,string $object,array $definitions,array $types) {
        if (!is_string($name) || !isset($types[$name])) { return self::error('Unsupported query field.'); }
        if (isset($definitions[$name]) && !Permissions::can('read',$object,0,$name)) { return self::error('A query field is not readable.',403); }
        return true;
    }

    private static function mode($mode,array $field) {
        if (!in_array($mode,['raw','label','display'],true)) { return self::error('Unsupported query value mode.'); }
        if ($mode==='raw') { return $mode; }
        $pro=self::advanced(); if (is_wp_error($pro)) { return $pro; }
        $field=self::definition($field);
        if (($mode==='label' && !in_array($field['type']??'',['select','radio','button_group','checkbox'],true)) || ($mode==='display' && !in_array($field['type']??'',['post_object','relationship','user','image','file','gallery'],true))) { return self::error('Display mode does not support this field.'); }
        return $mode;
    }

    private static function metrics($metrics,string $object,array $definitions,array $types,array &$used) {
        if (!is_array($metrics) || !self::is_list($metrics) || count($metrics)>20) { return self::error('Metrics must contain at most 20 definitions.'); }
        if (!$metrics) { return []; }
        $pro=self::advanced(); if (is_wp_error($pro)) { return $pro; }
        $ids=[];
        foreach ($metrics as $metric) {
            if (!is_array($metric) || array_diff(array_keys($metric),['id','operation','field']) || !is_string($metric['id']??null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$metric['id']) || isset($ids[$metric['id']]) || !in_array($metric['operation']??null,['sum','average','min','max','count'],true)) { return self::error('Invalid metric definition.'); }
            $ids[$metric['id']]=true;
            if (array_key_exists('field',$metric)) {
                $valid=self::field($metric['field'],$object,$definitions,$types); if (is_wp_error($valid)) { return $valid; }
                if ($metric['operation']!=='count' && ($types[$metric['field']]!=='number' || (isset($definitions[$metric['field']]) && !in_array(self::definition($definitions[$metric['field']])['type'],['number','range','true_false'],true)))) { return self::error('Numeric metrics require a numeric field.'); }
                $used[$metric['field']]=true;
            } elseif ($metric['operation']!=='count') { return self::error('Metric field is required.'); }
        }
        return $metrics;
    }

    private static function aggregate(array $items,array $metrics) {
        $result=[];
        foreach ($metrics as $metric) {
            $values=[]; $count=0;
            foreach ($items as $record) {
                if (!isset($metric['field'])) { $count++; continue; }
                $value=self::value($record,$metric['field']);
                if (self::empty_value($value)) { continue; }
                $count++;
                if ($metric['operation']!=='count') {
                    if (!is_numeric($value) || !is_finite((float)$value)) { return self::error('Metric data must be finite numeric values.'); }
                    $values[]=(float)$value;
                }
            }
            switch ($metric['operation']) {
                case 'count': $value=$count; break;
                case 'sum': $value=(float)array_sum($values); break;
                case 'average': $value=$values?array_sum($values)/count($values):null; break;
                case 'min': $value=$values?min($values):null; break;
                case 'max': $value=$values?max($values):null; break;
            }
            if (is_float($value) && !is_finite($value)) { return self::error('Metric exceeds the numeric range.'); }
            $result[$metric['id']]=$value;
        }
        return $result;
    }

    private static function resolve_filters(array $tree,array $record,array $definitions,array &$values,callable $consume) {
        foreach ($tree['conditions'] as $node) {
            $resolved=isset($node['relation'])?self::resolve_filters($node,$record,$definitions,$values,$consume):self::resolved($record,$node['field'],$node['mode'],$definitions,$values,$consume);
            if (is_wp_error($resolved)) { return $resolved->get_error_code()==='query_work_capacity'?$resolved:false; }
            if (isset($node['relation']) && !$resolved) { return false; }
        }
        return true;
    }

    private static function resolved(array $record,string $name,string $mode,array $definitions,array &$values,callable $consume) {
        $key=$name.':'.$mode;
        if (!array_key_exists($key,$values)) {
            $value=self::value($record,$name);
            if (!$consume(is_array($value)?count($value):1)) { return self::work_capacity(); }
            $values[$key]=apply_filters('acf_vf/app/query_display',self::error('Display query provider is unavailable.',409),$value,self::definition($definitions[$name]??[]),$mode);
        }
        return $values[$key];
    }

    private static function work_capacity(): \WP_Error {
        return new \WP_Error('query_work_capacity','This query exceeds the execution work limit. Narrow the query.',['status'=>413]);
    }

    private static function tree_matches(array $tree,array $values,array $definitions,array $types): bool {
        foreach ($tree['conditions'] as $node) {
            if (isset($node['relation'])) { $matches=self::tree_matches($node,$values,$definitions,$types); }
            else {
                $actual=$values[$node['field'].':'.$node['mode']]; $type=$node['mode']==='raw'?$types[$node['field']]:'text';
                if ($node['mode']==='raw' && self::is_date($definitions[$node['field']]??[]) && !self::empty_value($actual)) { $actual=self::date($actual,$definitions[$node['field']]); $type='number'; }
                $matches=self::matches($actual,$node['operator'],$node['value']??null,$type);
            }
            if ($tree['relation']==='OR' && $matches) { return true; }
            if ($tree['relation']==='AND' && !$matches) { return false; }
        }
        return $tree['relation']==='AND';
    }

    private static function comparable($value,array $field,string $type) {
        if (self::empty_value($value)) { return null; }
        if (self::is_date($field)) { return self::date($value,$field); }
        if ($type==='number') { return is_numeric($value) && is_finite((float)$value)?(float)$value:null; }
        return is_scalar($value)?(string)$value:null;
    }

    private static function date($value,array $field): ?float {
        if (!is_scalar($value)) { return null; }
        $field=self::definition($field); $value=(string)$value;
        $formats=($field['type']??'')==='date_picker'?['Ymd','Y-m-d']:(($field['type']??'')==='time_picker'?['H:i:s','H:i']:['Y-m-d H:i:s','Y-m-d H:i','Y-m-d\TH:i:sP','Y-m-d\TH:i:s']);
        foreach ($formats as $format) {
            $date=\DateTimeImmutable::createFromFormat('!'.$format,$value,wp_timezone());
            if ($date && $date->format($format)===$value) { return (float)$date->getTimestamp(); }
        }
        return null;
    }

    private static function relative($value) {
        if (!is_array($value) || array_keys($value)!==['preset'] || !is_string($value['preset'])) { return self::error('Relative dates require a preset.'); }
        $today=current_datetime()->setTime(0,0);
        switch ($value['preset']) {
            case 'today': $start=$today; $end=$today->modify('+1 day'); break;
            case 'yesterday': $start=$today->modify('-1 day'); $end=$today; break;
            case 'last_7_days': $start=$today->modify('-6 days'); $end=$today->modify('+1 day'); break;
            case 'last_30_days': $start=$today->modify('-29 days'); $end=$today->modify('+1 day'); break;
            case 'next_7_days': $start=$today; $end=$today->modify('+7 days'); break;
            case 'this_month': $start=$today->modify('first day of this month'); $end=$start->modify('+1 month'); break;
            case 'last_month': $end=$today->modify('first day of this month'); $start=$end->modify('-1 month'); break;
            default: return self::error('Unsupported relative date preset.');
        }
        return [(float)$start->getTimestamp(),(float)($end->getTimestamp()-1)];
    }

    private static function typed($value,string $type) {
        if (!is_scalar($value)) { return self::error('Filter value must be scalar.'); }
        if ($type==='number') {
            if (!is_numeric($value) || !is_finite((float)$value)) { return self::error('Filter value must be a finite number.'); }
            return (float)$value;
        }
        return (string)$value;
    }

    private static function matches($actual,string $operator,$expected,string $type): bool {
        $empty=self::empty_value($actual);
        if ($operator==='empty') { return $empty; }
        if ($operator==='not_empty') { return !$empty; }
        if ($empty) { return $operator==='ne'; }
        if ($type==='list') { return is_array($actual) && in_array((string)$expected,array_map('strval',array_filter($actual,'is_scalar')),true); }
        if (!is_scalar($actual) || ($type==='number' && (!is_numeric($actual) || !is_finite((float)$actual)))) { return false; }
        $actual=$type==='number'?(float)$actual:(string)$actual;
        $comparison=static function($a,$b)use($type){return $type==='number'?($a<=>$b):strcmp($a,$b);};
        switch ($operator) {
            case 'eq': return $actual===$expected;
            case 'ne': return $actual!==$expected;
            case 'contains': return strpos($actual,$expected)!==false;
            case 'starts_with': return strpos($actual,$expected)===0;
            case 'in': return in_array($actual,$expected,true);
            case 'gt': return $comparison($actual,$expected)>0;
            case 'gte': return $comparison($actual,$expected)>=0;
            case 'lt': return $comparison($actual,$expected)<0;
            case 'lte': return $comparison($actual,$expected)<=0;
        }
        // The validated operator remaining after the scalar cases is between.
        return $comparison($actual,$expected[0])>=0 && $comparison($actual,$expected[1])<=0;
    }

    private static function value(array $record,string $name) { return array_key_exists($name,$record)?$record[$name]:($record['fields'][$name]??null); }
    private static function definition(array $field): array { return ($field['type']??'')==='validated_field'?array_replace($field,['type'=>'text'],$field['sub_field']??[]):$field; }
    private static function is_date(array $field): bool { return in_array(self::definition($field)['type']??'',['date_picker','date_time_picker','time_picker'],true); }
    private static function is_list(array $value): bool { return $value===[] || array_keys($value)===range(0,count($value)-1); }
    private static function empty_value($value): bool { return $value===null || $value==='' || $value===false || $value===[]; }
    private static function advanced() { return Extensions::require_feature('lists.advanced_filters'); }
    private static function error(string $message,int $status=400): \WP_Error { return new \WP_Error('query_invalid',$message,['status'=>$status]); }
}
