<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Opt-in tables reuse existing ACF identities without adopting the post type. */
final class NativeTables {
    private static $hooked = [];
    private static array $observing = [];
    private static array $failed_observation = [];
    private static ?string $request_token = null;
    private const OBSERVATION = "_vf_native_observation";
    private const PENDING = "_vf_native_pending_";
    public static function boot(): void {
        foreach (array_keys(self::stored()) as $object) { self::register_hooks((string)$object); }
        add_action('registered_post_type',[self::class,'register_hooks'],50);
        add_action('add_post_meta',static function($id,$key,$value): void { self::changing_meta(null,$id,$key,$value); },PHP_INT_MIN,3);
        foreach (['update_post_meta','delete_post_meta'] as $event) { add_action($event,[self::class,'changing_meta'],PHP_INT_MIN,4); }
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $event) { add_action($event,[self::class,'changed_meta'],PHP_INT_MIN,4); }
        add_action('pre_post_update',[self::class,'changing_post'],PHP_INT_MIN,2);
        add_filter('wp_insert_post_data',[self::class,'inserting_post'],PHP_INT_MAX,4);
        add_action('clean_object_term_cache',[self::class,'cleaned_terms'],PHP_INT_MIN,2);
        add_action('clean_post_cache',[self::class,'cleaned_post'],PHP_INT_MIN,2);
        add_action('before_delete_post',[self::class,'deleting_post'],PHP_INT_MIN,2);
        add_action('post_updated',[self::class,'updated_post'],PHP_INT_MIN,3);
        add_action('attachment_updated',[self::class,'changed_post'],PHP_INT_MIN,3);
        add_action('transition_post_status',[self::class,'transitioning_post'],PHP_INT_MIN,3);
        add_action('wp_insert_post',[self::class,'inserted_post'],PHP_INT_MIN,3);
        add_action('deleted_post',[self::class,'deleted_post'],PHP_INT_MIN,2);
        add_action('admin_menu',static function (): void { add_submenu_page('validated-field-workspace','WordPress tables','WordPress tables','manage_options','validated-field-tables',[self::class,'settings']); },20);
    }
    private static function stored(): array { $value=get_option('acf_vf_native_tables',[]); return is_array($value)?$value:[]; }
    public static function enabled(string $object): bool { return isset(self::stored()[$object]) && !isset(Schema::objects()[$object]); }
    public static function objects(): array {
        $result=[];
        foreach (array_keys(self::stored()) as $object) { $definition=self::definition((string)$object); if ($definition) { $result[$object]=$definition; } }
        return $result;
    }
    public static function definition(string $object): ?array {
        if (!self::enabled($object) || !function_exists('acf_get_field')) { return null; }
        $type=get_post_type_object($object); $config=self::stored()[$object];
        if (!$type || !is_array($config) || !is_array($config['fields']??null)) { return null; }
        $fields=[];
        foreach ($config['fields'] as $key) {
            if (!is_string($key)) { return null; }
            $field=acf_get_field($key);
            if (!$field || !self::applicable($field,$object) || !is_string($field['name']??null) || !self::field_name($field['name']) || isset($fields[$field['name']])) { return null; }
            $fields[$field['name']]=$field;
        }
        $definition=['slug'=>$object,'label'=>$type->labels->name,'singular'=>$type->labels->singular_name,'fields'=>$fields,'native_table'=>true,'existing'=>true,'public'=>(bool)$type->public,'access'=>[],'rules'=>[],'relationships'=>[],'automation'=>[],'layout'=>[],'record_types'=>[]];
        $definition['_revision']=hash('sha256',(string)wp_json_encode(['observed-state-v1',$config,$fields]));
        return $definition;
    }
    private static function applicable(array $field,string $object): bool {
        $parent=$field['parent']??'';
        if (!is_string($parent) && !is_int($parent)) { return false; }
        $parent=(string)$parent;
        foreach (acf_get_field_groups(['post_type'=>$object]) as $group) {
            if (($group['key']??'')!==$parent && (string)($group['ID']??'')!==$parent) { continue; }
            // A table field must apply to the entire post type, not a conditional subset.
            foreach ($group['location']??[] as $or) {
                if (count($or)===1 && ($or[0]['param']??'')==='post_type' && ($or[0]['operator']??'')==='==' && ($or[0]['value']??'')===$object) { return true; }
            }
        }
        return false;
    }
    public static function configure(string $object,array $keys) {
        if (!current_user_can('manage_options')) { return Rest::error('native_tables_forbidden','Table configuration requires administrator access.',403); }
        $type=get_post_type_object($object);
        if (!$type || !$type->show_ui || in_array($object,['attachment','revision','nav_menu_item'],true) || isset(Schema::objects()[$object]) || !Rest::is_list($keys) || !$keys || count($keys)>49 || count(array_unique($keys,SORT_REGULAR))!==count($keys)) { return Rest::error('native_tables_definition','Select an existing post type and 1–49 distinct ACF fields.'); }
        $names=[];
        foreach ($keys as $key) {
            $field=is_string($key)?acf_get_field($key):false;
            if (!$field || !self::applicable($field,$object) || !self::field_name((string)($field['name']??''))) { return Rest::error('native_tables_field','Choose top-level ACF fields that apply to this entire post type.'); }
            if (isset($names[$field['name']])) { return Rest::error('native_tables_field','Table field names must be unique.'); }
            $names[$field['name']]=true;
        }
        $written=self::persist($object,['fields'=>$keys]);
        if (is_wp_error($written)) { return $written; }
        $definition=self::definition($object);
        if (!$definition) { return Rest::error('native_tables_definition','Field names must be unique.'); }
        self::register_hooks($object);
        return $definition;
    }
    public static function remove(string $object) {
        if (!current_user_can('manage_options')) { return Rest::error('native_tables_forbidden','Table configuration requires administrator access.',403); }
        return self::persist($object,null);
    }
    private static function persist(string $object,?array $definition) {
        $begin=Storage::begin(); if (is_wp_error($begin)) { return $begin; }
        try {
            $lock=Storage::lock('native-table-configuration'); if (is_wp_error($lock)) { Storage::rollback(); return $lock; }
            global $wpdb;
            $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'acf_vf_native_tables'));
            if ($wpdb->last_error) { throw new \RuntimeException('Native table configuration could not be read.'); }
            $stored=maybe_unserialize($raw);
            $stored=is_array($stored)?$stored:[]; $next=$stored;
            if ($definition===null) { unset($next[$object]); } else { $next[$object]=$definition; }
            if ($next!==$stored) {
                $lock=Storage::lock('index:'.$object); if (is_wp_error($lock)) { Storage::rollback(); return $lock; }
                // Readiness belongs to this enablement lifecycle, even when A -> B
                // -> A restores the same field hash. Rebuild from authoritative data.
                if ($wpdb->delete(Storage::table('index'),['object_key'=>$object,'record_id'=>0])===false) { throw new \RuntimeException('Native table index readiness could not be reset.'); }
            }
            // Read under the shared lock so separate post types do not overwrite each other.
            wp_cache_delete('acf_vf_native_tables','options'); wp_cache_delete('alloptions','options'); wp_cache_delete('notoptions','options');
            if ($next!==$stored && !update_option('acf_vf_native_tables',$next,false)) { Storage::rollback(); return Rest::error('native_tables_storage','Table settings could not be saved.',503); }
            if (!Storage::commit()) {
                wp_cache_delete('acf_vf_native_tables','options'); wp_cache_delete('alloptions','options'); wp_cache_delete('notoptions','options');
                return Rest::error('native_tables_storage','Table settings could not be committed.',503);
            }
            return true;
        } catch (\Throwable $error) {
            Storage::rollback(); wp_cache_delete('acf_vf_native_tables','options'); wp_cache_delete('alloptions','options'); wp_cache_delete('notoptions','options');
            return Rest::error('native_tables_storage','Table settings could not be saved.',503);
        }
    }
    public static function assert_current(string $object) {
        global $wpdb;
        $cached=self::stored(); $raw=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'acf_vf_native_tables')));
        return is_array($raw) && isset($raw[$object],$cached[$object]) && $raw===$cached && self::definition($object) ? true : Rest::error('schema_conflict','Table configuration changed. Reload and retry.',409);
    }
    /** Retain the native list's author, taxonomy, search and date constraints. */
    public static function query(string $object,array $args,\WP_Query $native) {
        $vars=$native->query_vars;
        $vars=array_replace($vars,['post_type'=>$object,'fields'=>'ids','posts_per_page'=>10001,'paged'=>1,'offset'=>0,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,'nopaging'=>false]);
        // Lists calls this while its own recursion guard is active.
        $candidates=new \WP_Query($vars);
        if (count($candidates->posts)>10000) { return Rest::error('query_capacity','Native table selection exceeds 10,000 records. Narrow the query.',413); }
        $ids=array_map('intval',$candidates->posts);
        $restrict=static function (array $query_args,string $type) use($object,$ids): array {
            if ($type!==$object) { return $query_args; }
            $query_args['post__in']=isset($query_args['post__in'])?array_values(array_intersect($query_args['post__in'],$ids)):$ids;
            $query_args['post__in']=$query_args['post__in']?:[0];
            return $query_args;
        };
        add_filter('acf_vf/app/query_post_args',$restrict,10,2);
        try {
            // Native search already applied its own WordPress content semantics.
            unset($args['search']);
            return Query::run($object,$args,self::statuses(true));
        } finally { remove_filter('acf_vf/app/query_post_args',$restrict,10); }
    }
    /** Core chooses native list IDs; internal statuses are only eligible in that trusted context. */
    public static function statuses(bool $native_list=false): array {
        return array_values(get_post_stati($native_list?[]:['internal'=>false]));
    }
    /** A record-local journal; never enumerate records to make a query eligible. */
    private static function core(array $post): array {
        return array_map(static function($key) use($post): string { return (string)($post[$key]??''); },['post_type','post_title','post_status','post_content']);
    }
    private static function raw_post(int $id): ?array {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT ID,post_type,post_title,post_status,post_content FROM {$wpdb->posts} WHERE ID=%d",$id),ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException('Native observation could not be read.'); }
        return $row?:null;
    }
    private static function raw_meta(int $id,string $key): ?array {
        global $wpdb;
        $value=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id LIMIT 1",$id,$key));
        if ($wpdb->last_error) { throw new \RuntimeException('Native observation could not be read.'); }
        return $value===null?null:json_decode($value,true);
    }
    private static function internal_key(string $key): bool {
        return $key==='_vf_app_version' || $key===self::OBSERVATION || strpos($key,self::PENDING)===0;
    }
    private static function field_name(string $name): bool {
        return (bool)preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$name) && !in_array($name,['id','object','title','status','version'],true) && !self::internal_key('_'.$name);
    }
    private static function selected(string $object,string $key): bool {
        if (self::internal_key($key)) { return false; }
        foreach (Schema::fields($object) as $name=>$field) {
            if ($key===$name || $key==='_'.$name || strpos($key,$name.'_')===0 || strpos($key,'_'.$name.'_')===0) { return true; }
        }
        return false;
    }
    private static function signature(int $id,array $post,string $scope): string {
        global $wpdb;
        $clauses=[]; $args=[$id,'_vf_app_version',self::OBSERVATION,$wpdb->esc_like(self::PENDING).'%'];
        foreach (Schema::fields($scope) as $name=>$field) {
            $clauses[]='(meta_key=%s OR meta_key=%s OR meta_key LIKE %s OR meta_key LIKE %s)';
            array_push($args,$name,'_'.$name,$wpdb->esc_like($name.'_').'%',$wpdb->esc_like('_'.$name.'_').'%');
        }
        $rows=$clauses?$wpdb->get_results($wpdb->prepare("SELECT meta_key,SHA2(meta_value,256) AS digest FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key NOT IN (%s,%s) AND meta_key NOT LIKE %s AND (".implode(' OR ',$clauses).') ORDER BY meta_key,meta_id LIMIT 10001',$args),ARRAY_A):[];
        if ($wpdb->last_error || count($rows)>10000) { throw new \RuntimeException('Native observation exceeds its storage capacity.'); }
        return hash('sha256',(string)wp_json_encode([self::core($post),$rows]));
    }
    private static function checked($result): void { if ($result===false) { throw new \RuntimeException('Native observation could not be persisted.'); } }
    private static function commit(bool &$active): void {
        $committed=Storage::commit(); $active=false;
        // Storage already rolls back this savepoint when COMMIT/RELEASE fails.
        if (!$committed) { throw new \RuntimeException('Native observation commit failed.'); }
    }
    private static function journal(int $id,array $state): void {
        global $wpdb;
        self::checked($wpdb->delete($wpdb->postmeta,['post_id'=>$id,'meta_key'=>self::OBSERVATION]));
        self::checked($wpdb->insert($wpdb->postmeta,['post_id'=>$id,'meta_key'=>self::OBSERVATION,'meta_value'=>wp_json_encode($state)]));
        wp_cache_delete($id,'post_meta');
    }
    private static function invalidate(string $object): void {
        global $wpdb;
        $lock=Storage::lock('index:'.$object); if (is_wp_error($lock)) { throw new \RuntimeException('Native index lock unavailable.'); }
        self::checked($wpdb->delete(Storage::table('index'),['object_key'=>$object,'record_id'=>0]));
        $name='acf_vf_native_generation_'.$object;
        self::checked($wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",$name,bin2hex(random_bytes(16)))));
        wp_cache_delete($name,'options');
    }
    private static function token(): string {
        global $wpdb;
        if (self::$request_token===null) {
            $token='vfn_'.substr(hash('sha256',$wpdb->prefix.random_bytes(32)),0,56);
            if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$token))!==1) { throw new \RuntimeException('Native observation lease unavailable.'); }
            // Deliberately connection-scoped, unlike Storage transaction locks.
            self::$request_token=$token;
        }
        return self::$request_token;
    }
    private static function affected(array &$objects,$object): void {
        if (is_string($object) && self::enabled($object)) { $objects[$object]=true; }
    }
    /** Reconcile durable state before a Records write acquires its exact-ID guard. */
    public static function observe(int $id,?array $before=null,?string $scope=null,bool $creation=false,?array $context=null): void {
        if ($id<1 || isset(self::$observing[$id])) { return; }
        global $wpdb;
        self::$observing[$id]=true; $begun=false; $post=null; $affected=[];
        try {
            // Notification identities are recovery context, not a persisted-state
            // baseline. Cache notifications can carry a pre-write post snapshot.
            foreach ([$scope,$before['post_type']??null,$context['post_type']??null] as $object) { self::affected($affected,$object); }
            $post=self::raw_post($id); if (!$post) { return; }
            self::affected($affected,$post['post_type']);
            $scope=$scope??(isset($affected[$post['post_type']])?$post['post_type']:null);
            $known=self::raw_meta($id,self::OBSERVATION);
            self::affected($affected,$known['scope']??null);
            $scope=$scope??($known['scope']??'');
            if (!$scope || !self::definition($scope)) { return; }
            if (!self::enabled($post['post_type']) && !$before && (!$known || ($known['core'][0]??'')===$post['post_type'])) { return; }
            $begin=Storage::begin(); if (is_wp_error($begin)) { throw new \RuntimeException('Native observation transaction unavailable.'); } $begun=true;
            $lock=Storage::lock('record:'.$id); if (is_wp_error($lock)) { throw new \RuntimeException('Native observation lock unavailable.'); }
            // Read again under the platform lock. No PHP memo survives rollback.
            $post=self::raw_post($id);
            if ($post) { self::affected($affected,$post['post_type']); }
            $known=self::raw_meta($id,self::OBSERVATION);
            self::affected($affected,$known['scope']??null);
            if (!$post) { self::commit($begun); return; }
            $revision=self::definition($scope)['_revision'];
            $signature=self::signature($id,$post,$scope); $core=self::core($post);
            $baseline=$known;
            if (!$baseline && $before) { $baseline=['signature'=>self::signature($id,$before,$scope),'core'=>self::core($before),'revision'=>$revision]; }
            $changed=$baseline && (($baseline['core'][0]??'')!==$core[0] || (($baseline['revision']??'')===$revision && $baseline['signature']!==$signature));
            $owned=Records::is_writing($id);
            if ($changed && !$owned && !$creation) {
                if ($wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_vf_app_version' LIMIT 1",$id))===null) { self::checked($wpdb->insert($wpdb->postmeta,['post_id'=>$id,'meta_key'=>'_vf_app_version','meta_value'=>'0'])); }
                self::checked($wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=CAST(meta_value AS UNSIGNED)+1 WHERE post_id=%d AND meta_key='_vf_app_version'",$id)));
                wp_cache_delete($id,'post_meta');
                foreach (array_unique([$baseline['core'][0]??'', $core[0]]) as $object) { if (self::enabled($object)) { self::invalidate($object); } }
            } elseif ($creation && !$known && !$owned) { self::invalidate($scope); }
            if (!$known || $known['signature']!==$signature || ($known['revision']??'')!==$revision || ($known['scope']??'')!==$scope) { self::journal($id,['scope'=>$scope,'revision'=>$revision,'signature'=>$signature,'core'=>$core]); }
            // A core intent is settled only by its own expected persisted core state.
            $pending=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s LIMIT 101",$id,$wpdb->esc_like(self::PENDING).'%'),ARRAY_A);
            if ($wpdb->last_error || count($pending)>100) { throw new \RuntimeException('Native pending capacity exceeded.'); }
            foreach ($pending as $row) { $intent=json_decode($row['meta_value'],true); if (($intent['kind']??'')==='core' && ($intent['expected']??'')===hash('sha256',(string)wp_json_encode($core))) { self::checked($wpdb->delete($wpdb->postmeta,['meta_id'=>$row['meta_id']])); } }
            self::commit($begun);
        } catch (\Throwable $error) {
            if ($begun) { try { Storage::rollback(); } catch (\Throwable $rollback_error) {} }
            // Core may already have committed (notably wp_publish_post). A failed
            // journal transaction must not restore old completed readiness.
            if (!Records::is_writing($id) && $affected) {
                // Only a successful raw read supplies an expected persisted state.
                // Context-only recovery stays pending until explicit acknowledgement.
                $intent=$post?['kind'=>'core','expected'=>hash('sha256',(string)wp_json_encode(self::core($post)))]:['kind'=>'observation'];
                try { self::fence($id,array_keys($affected),$intent); }
                catch (\Throwable $fence_error) {
                    // A failed INSERT must not undo a successful readiness DELETE
                    // merely because both shared the failed fence's transaction.
                    // This remains part of an enclosing caller transaction, if any.
                    foreach (array_keys($affected) as $object) {
                        self::$failed_observation[$object]=true;
                        try { $wpdb->delete(Storage::table('index'),['object_key'=>$object,'record_id'=>0]); }
                        catch (\Throwable $delete_error) {} // Preserve the original observation failure.
                    }
                }
            }
            throw $error;
        }
        finally { unset(self::$observing[$id]); }
    }
    private static function fence(int $id,array $objects,array $intent): void {
        global $wpdb;
        $begin=Storage::begin(); if (is_wp_error($begin)) { throw new \RuntimeException('Native barrier unavailable.'); } $active=true;
        try {
            $lock=Storage::lock('record:'.$id); if (is_wp_error($lock)) { throw new \RuntimeException('Native barrier lock unavailable.'); }
            $intent['token']=self::token();
            $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s",$id,$wpdb->esc_like(self::PENDING).'%'));
            if ($wpdb->last_error || $count+count($objects)>100) { throw new \RuntimeException('Native pending capacity exceeded.'); }
            foreach (array_unique($objects) as $object) {
                if (!self::enabled($object)) { continue; }
                self::invalidate($object);
                self::checked($wpdb->insert($wpdb->postmeta,['post_id'=>$id,'meta_key'=>self::PENDING.$object,'meta_value'=>wp_json_encode($intent)]));
            }
            wp_cache_delete($id,'post_meta');
            self::commit($active);
        } catch (\Throwable $error) { if ($active) { Storage::rollback(); } throw $error; }
    }
    public static function changing_post(int $id,array $data): void {
        $before=self::raw_post($id); if (!$before) { return; }
        $after=array_replace($before,$data); $objects=array_values(array_filter(array_unique([$before['post_type'],$after['post_type']]),[self::class,'enabled']));
        if (!$objects) { return; }
        self::observe($id,$before,$objects[0]);
        if (Records::is_writing($id) || self::core($before)===self::core($after)) { return; }
        self::fence($id,$objects,['kind'=>'core','expected'=>hash('sha256',(string)wp_json_encode(self::core($after)))]);
    }
    public static function inserting_post(array $data,array $postarr,array $original,bool $update): array {
        if ($update || !self::enabled((string)$data['post_type'])) { return $data; }
        global $wpdb;
        $object=(string)$data['post_type'];
        $name='acf_vf_native_insert_'.$object.'_'.bin2hex(random_bytes(12));
        $begin=Storage::begin(); if (is_wp_error($begin)) { throw new \RuntimeException('Native insert barrier unavailable.'); } $active=true;
        try {
            $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('acf_vf_native_insert_'.$object.'_').'%'));
            if ($wpdb->last_error || $count>=100) { throw new \RuntimeException('Native insert capacity exceeded.'); }
            self::invalidate($object);
            self::checked($wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>wp_json_encode(['token'=>self::token(),'expected'=>hash('sha256',(string)wp_json_encode(self::core(wp_unslash($data))))]),'autoload'=>'off']));
            self::commit($active);
        } catch (\Throwable $error) { if ($active) { Storage::rollback(); } throw $error; }
        return $data;
    }
    public static function changing_meta($meta_id,$id,$key,$value=null): void {
        $id=(int)$id; $post=self::raw_post($id);
        if (!$post || !self::enabled($post['post_type']) || !self::selected($post['post_type'],(string)$key)) { return; }
        self::observe($id);
        if (!Records::is_writing($id)) { self::fence($id,[$post['post_type']],['kind'=>'meta','event'=>current_filter(),'key'=>$key,'value'=>hash('sha256',serialize($value))]); }
    }
    public static function changed_meta($meta_id,$id,$key,$value=null): void {
        $id=(int)$id; $post=self::raw_post($id);
        if (!$post || !self::enabled($post['post_type']) || !self::selected($post['post_type'],(string)$key)) { return; }
        self::observe($id);
        global $wpdb;
        $event=['added_post_meta'=>'add_post_meta','updated_post_meta'=>'update_post_meta','deleted_post_meta'=>'delete_post_meta'][current_filter()]??'';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 101",$id,self::PENDING.$post['post_type']),ARRAY_A);
        if ($wpdb->last_error || count($rows)>100) { throw new \RuntimeException('Native pending capacity exceeded.'); }
        // The post-action acknowledges completion, including a genuine SQL no-op.
        foreach (array_reverse($rows) as $row) { $intent=json_decode($row['meta_value'],true); if (($intent['token']??'')===self::$request_token && ($intent['event']??'')===$event && ($intent['key']??'')===$key && ($intent['value']??'')===hash('sha256',serialize($value))) { self::checked($wpdb->delete($wpdb->postmeta,['meta_id'=>$row['meta_id']])); break; } }
        wp_cache_delete($id,'post_meta');
    }
    public static function cleaned_terms($ids,string $object): void {
        $ids=(array)$ids;
        if (count($ids)>50) { return; } // Public single-record core mutations use one ID.
        foreach ($ids as $id) { self::observe((int)$id,null,null,false,['post_type'=>$object]); }
    }
    public static function cleaned_post(int $id,$post): void { self::observe($id,null,null,false,(array)$post); }
    public static function transitioning_post(string $new,string $old,$post): void {
        $before=(array)$post; $before['post_status']=$old;
        self::observe((int)$post->ID,$old==='new'?null:$before,null,$old==='new',(array)$post);
    }
    public static function updated_post(int $id,$after,$before): void { self::changed_post($id,$after,$before); }
    /** Bare set_post_type callers must explicitly forward their before/after snapshots. */
    public static function changed_post(int $id,$after,$before): void {
        $objects=array_values(array_filter([(string)$before->post_type,(string)$after->post_type],[self::class,'enabled']));
        if ($objects) { self::observe($id,(array)$before,self::enabled((string)$after->post_type)?(string)$after->post_type:$objects[0],false,(array)$after); }
    }
    public static function inserted_post(int $id,$post,bool $update): void {
        self::observe($id,null,null,!$update,(array)$post);
        if ($update || !self::enabled((string)$post->post_type)) { return; }
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 101",$wpdb->esc_like('acf_vf_native_insert_'.$post->post_type.'_').'%'),ARRAY_A);
        if ($wpdb->last_error || count($rows)>100) { throw new \RuntimeException('Native insert capacity exceeded.'); }
        foreach ($rows as $row) { $intent=json_decode($row['option_value'],true); if (($intent['token']??'')===self::$request_token && ($intent['expected']??'')===hash('sha256',(string)wp_json_encode(self::core((array)$post)))) { self::checked($wpdb->delete($wpdb->options,['option_name'=>$row['option_name']])); break; } }
    }
    public static function deleting_post(int $id,$post): void {
        if (!Records::is_writing($id) && self::enabled((string)$post->post_type)) { self::observe($id,null,null,false,(array)$post); self::fence($id,[(string)$post->post_type],['kind'=>'delete']); }
    }
    public static function deleted_post(int $id,$post): void {
        if (self::enabled((string)$post->post_type)) {
            global $wpdb;
            $begin=Storage::begin(); if (is_wp_error($begin)) { throw new \RuntimeException('Native deletion barrier unavailable.'); } $active=true;
            try {
                // Core snapshots metadata IDs before deleting them. Observing a
                // selected-meta deletion can replace the journal with a newer ID.
                self::checked($wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND (meta_key=%s OR meta_key LIKE %s)",$id,self::OBSERVATION,$wpdb->esc_like(self::PENDING).'%')));
                wp_cache_delete($id,'post_meta');
                if (!Records::is_writing($id)) { self::invalidate((string)$post->post_type); }
                self::commit($active);
            }
            catch (\Throwable $error) { if ($active) { Storage::rollback(); } throw $error; }
        }
    }
    /** O(1) object eligibility checks; pending records are reconciled only by backfill. */
    public static function generation(string $object): string {
        global $wpdb;
        $value=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'acf_vf_native_generation_'.$object));
        if ($wpdb->last_error) { throw new \RuntimeException('Native generation could not be read.'); }
        return (string)$value;
    }
    public static function pending(string $object): bool {
        global $wpdb;
        if (isset(self::$failed_observation[$object])) { return true; }
        $meta=$wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key=%s LIMIT 1",self::PENDING.$object));
        if ($wpdb->last_error) { return true; }
        $insert=$wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",$wpdb->esc_like('acf_vf_native_insert_'.$object.'_').'%'));
        return $wpdb->last_error || $meta!==null || $insert!==null;
    }
    /** Explicit, bounded acknowledgement of abandoned public-API operations. */
    public static function prepare_backfill(string $object) {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT meta_id,post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s ORDER BY meta_id LIMIT 500",self::PENDING.$object),ARRAY_A);
        $inserts=$wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 101",$wpdb->esc_like('acf_vf_native_insert_'.$object.'_').'%'),ARRAY_A);
        if ($wpdb->last_error || count($inserts)>100) { return Rest::error('native_pending_capacity','Too many pending native writes. Retry smaller batches.',503); }
        foreach ($rows as $row) {
            self::observe((int)$row['post_id']);
            if ($wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_id=%d",$row['meta_id']))===null) { continue; }
            $intent=json_decode($row['meta_value'],true); $token=$intent['token']??'';
            if (!$token || $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$token))!==null) { return Rest::error('native_write_pending','A native write has not completed. Retry after its request ends.',409); }
            self::checked($wpdb->delete($wpdb->postmeta,['meta_id'=>$row['meta_id']])); self::invalidate($object);
        }
        foreach ($inserts as $row) {
            $intent=json_decode($row['option_value'],true); $token=$intent['token']??'';
            if (!$token || $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$token))!==null) { return Rest::error('native_write_pending','A native insertion has not completed. Retry after its request ends.',409); }
            self::checked($wpdb->delete($wpdb->options,['option_name'=>$row['option_name']])); self::invalidate($object);
        }
        return true;
    }
    public static function register_hooks(string $object): void {
        if (isset(self::$hooked[$object]) || !self::definition($object)) { return; }
        self::$hooked[$object]=true;
        add_filter('manage_'.$object.'_posts_columns',static function(array $columns) use($object): array {
            $configured=Lists::columns($object);
            if (is_wp_error($configured)) { return $columns; }
            foreach ($configured as $column) { if (!in_array($column['field'],['id','title','status','version'],true)) { $columns['vf_'.$column['field']]=$column['label']; } }
            return $columns;
        });
        add_filter('manage_edit-'.$object.'_sortable_columns',static function(array $columns) use($object): array {
            $configured=Lists::columns($object);
            if (!is_wp_error($configured)) { foreach ($configured as $column) { if ($column['sortable']) { $columns['vf_'.$column['field']]='vf_'.$column['field']; } } }
            return $columns;
        });
        add_action('manage_'.$object.'_posts_custom_column',static function($key,$id) use($object): void {
            if (strpos((string)$key,'vf_')!==0) { return; }
            $columns=Lists::columns($object); $record=Records::read((int)$id);
            if (is_wp_error($columns) || is_wp_error($record)) { return; }
            foreach ($columns as $column) { if ($key==='vf_'.$column['field']) { echo Lists::format_value($object,$record,$column,true); } }
        },10,2);
        add_action('restrict_manage_posts',static function($type,$which) use($object): void {
            if ($type!==$object || $which!=='top' || !Permissions::can('read',$object)) { return; }
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=validated-field-workspace&object='.$object)).'">'.esc_html__('Open table workspace','validated-field-for-acf').'</a> ';
        },30,2);
    }
    private static function eligible_fields(string $object): array {
        $fields=[];
        foreach (acf_get_field_groups(['post_type'=>$object]) as $group) {
            foreach (acf_get_fields($group) ?: [] as $field) {
                $name=$field['name']??''; $key=$field['key']??'';
                if (!is_string($key) || !is_string($name) || !self::field_name($name) || !self::applicable($field,$object)) { continue; }
                $fields[$key]=$field;
            }
        }
        return $fields;
    }
    public static function settings(): void {
        if (!current_user_can('manage_options')) { return; }
        $notice=''; $types=[];
        foreach (get_post_types(['show_ui'=>true],'objects') as $slug=>$type) {
            if (!isset(Schema::objects()[$slug]) && !in_array($slug,['attachment','revision','nav_menu_item','acf-field-group','acf-field','acf-post-type','acf-taxonomy'],true)) { $types[$slug]=$type; }
        }
        $requested=isset($_POST['vf_native_table_nonce'])?($_POST['object']??''):($_GET['object']??'');
        $object=is_string($requested)?sanitize_key(wp_unslash($requested)):'';
        if (isset($_POST['vf_native_table_nonce'])) {
            check_admin_referer('vf_native_tables','vf_native_table_nonce');
            $keys=isset($_POST['fields']) && is_array($_POST['fields'])?wp_unslash($_POST['fields']):[];
            $result=!isset($types[$object])?Rest::error('native_tables_definition','Choose an available post type.'):(isset($_POST['remove'])?self::remove($object):self::configure($object,$keys));
            $notice=is_wp_error($result)?$result->get_error_message():__('Table settings saved.','validated-field-for-acf');
        }
        if (!isset($types[$object])) { $object=(string)array_key_first($types); }
        echo '<div class="wrap"><h1>'.esc_html__('WordPress tables','validated-field-for-acf').'</h1><p>'.esc_html__('Add existing ACF fields to posts, pages and custom post types. Native editors, visibility and field storage stay in place. Choose a post type to see fields that apply to all its records.','validated-field-for-acf').'</p>';
        if ($notice) { echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>'; }
        echo '<form method="get"><input type="hidden" name="page" value="validated-field-tables"><p><label for="vf-native-object">'.esc_html__('Post type','validated-field-for-acf').' </label><select id="vf-native-object" name="object">';
        foreach ($types as $slug=>$type) { echo '<option value="'.esc_attr($slug).'"'.selected($object,$slug,false).'>'.esc_html($type->labels->name).'</option>'; }
        echo '</select> '; submit_button(__('Choose post type','validated-field-for-acf'),'secondary','choose',false); echo '</p></form>';
        if ($object!=='') {
            $fields=self::eligible_fields($object); $selected=self::stored()[$object]['fields']??[];
            echo '<form method="post"><input type="hidden" name="object" value="'.esc_attr($object).'">'; wp_nonce_field('vf_native_tables','vf_native_table_nonce');
            echo '<fieldset><legend><strong>'.esc_html__('Fields for this table','validated-field-for-acf').'</strong></legend>';
            foreach ($fields as $key=>$field) {
                echo '<p><label><input type="checkbox" name="fields[]" value="'.esc_attr($key).'"'.checked(in_array($key,$selected,true),true,false).'> '.esc_html($field['label']?:$field['name']).' <code>'.esc_html($field['name']).'</code></label></p>';
            }
            if (!$fields) { echo '<p>'.esc_html__('No eligible ACF fields. Add a field group whose location applies to this entire post type.','validated-field-for-acf').'</p>'; }
            echo '</fieldset>';
            if ($fields) { submit_button(__('Save table','validated-field-for-acf'),'primary','save',false); echo ' '; }
            if (self::enabled($object)) { submit_button(__('Remove table settings','validated-field-for-acf'),'secondary','remove',false); }
            echo '</form>';
        }
        echo '<h2>'.esc_html__('Configured tables','validated-field-for-acf').'</h2><ul>';
        foreach (self::stored() as $slug=>$config) {
            $names=[];
            foreach ((array)($config['fields']??[]) as $key) { $field=is_string($key)?acf_get_field($key):false; $names[]=$field?($field['label']?:$field['name']):__('Unavailable field','validated-field-for-acf'); }
            echo '<li><a href="'.esc_url(add_query_arg(['page'=>'validated-field-tables','object'=>$slug],admin_url('admin.php'))).'">'.esc_html($types[$slug]->labels->name??$slug).'</a>: '.esc_html(implode(', ',$names)).'</li>';
        }
        echo '</ul></div>';
    }
}
