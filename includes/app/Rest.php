<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Authenticated adapters. Business access and validation remain in shared services. */
final class Rest {
    // Nine groups each add an object and conditions array, followed by a leaf
    // and value list. Reserve eight more levels for workspace/view/job envelopes.
    public const MAX_INPUT_DEPTH = 2 * (Query::MAX_FILTER_DEPTH + 1) + 2 + 8;
    public const JSON_DEPTH = self::MAX_INPUT_DEPTH + 2;
    public static function boot(): void { add_action('rest_api_init', [__CLASS__, 'register_routes']); }

    public static function register_routes(): void {
        $object = '/objects/(?P<object>[a-z][a-z0-9_]{0,19})';
        $record = '/records/(?P<id>[1-9][0-9]*)';
        $routes = [
            ['/schema', 'GET', 'schema', true],
            ['/client-types', 'GET', 'client_types', false],
            ['/objects', 'GET', 'objects', false], ['/objects', 'POST', 'save_object', true],
            [$object, 'GET', 'object', false],
            [$object . '/form', 'GET', 'form', false],
            [$object . '/records', 'GET', 'listing', false], [$object . '/query', 'POST', 'query', false],
            [$object . '/records', 'POST', 'create', false],
            [$object . '/quick-add', 'POST', 'quick_add', false],
            [$object . '/table-preferences', 'GET', 'table_preferences', false], [$object . '/table-preferences', 'POST', 'save_table_preferences', false],
            ['/native-tables/(?P<object>[a-z][a-z0-9_]{0,19})', 'PUT', 'native_table', true], ['/native-tables/(?P<object>[a-z][a-z0-9_]{0,19})', 'DELETE', 'remove_native_table', true],
            [$record, 'GET', 'read', false], [$record, 'PATCH', 'update', false], [$record, 'DELETE', 'delete', false],
            [$object . '/import', 'POST', 'import', false], [$object . '/bulk', 'POST', 'import', false],
            [$object . '/export', 'POST', 'export', false],
            ['/configuration/export', 'GET', 'schema', true],
            ['/configuration/preflight', 'POST', 'preflight', true], ['/configuration/apply', 'POST', 'apply', true],
            [$record . '/preview', 'POST', 'preview', false], [$record . '/preview/read', 'POST', 'preview_read', false],
            [$object . '/columns', 'GET', 'columns', false], [$object . '/columns', 'PUT', 'configure_columns', true],
            [$record . '/inline', 'PATCH', 'inline', false], [$object . '/bulk-update', 'POST', 'bulk_update', false],
            [$object . '/views', 'GET', 'views', false], ['/views', 'POST', 'save_view', false],
            ['/views/(?P<view>[a-zA-Z0-9_-]{1,64})', 'GET', 'view', false], ['/views/(?P<view>[a-zA-Z0-9_-]{1,64})', 'DELETE', 'delete_view', false],
            ['/views/(?P<view>[a-zA-Z0-9_-]{1,64})/query', 'POST', 'run_view', false],
            ['/views/(?P<view>[a-zA-Z0-9_-]{1,64})/duplicate', 'POST', 'duplicate_view', false],
            [$object . '/default-view', 'GET', 'default_view', false],
            [$object . '/table-jobs/preview', 'POST', 'table_job_preview', false],
            ['/table-jobs/(?P<work>[a-f0-9-]{36})', 'GET', 'table_job_status', false],
            ['/table-jobs/(?P<work>[a-f0-9-]{36})/start', 'POST', 'table_job_start', false],
            ['/table-jobs/(?P<work>[a-f0-9-]{36})/cancel', 'POST', 'table_job_cancel', false],
            ['/table-jobs/(?P<work>[a-f0-9-]{36})/recover', 'POST', 'table_job_recover', false],
            ['/table-jobs/(?P<work>[a-f0-9-]{36})/download', 'POST', 'table_job_download', false],
            [$object . '/export-profiles', 'GET', 'export_profiles', false], ['/export-profiles', 'POST', 'save_export_profile', false],
            ['/export-profiles/(?P<profile>[a-zA-Z0-9_-]{1,64})', 'GET', 'export_profile', false], ['/export-profiles/(?P<profile>[a-zA-Z0-9_-]{1,64})', 'DELETE', 'delete_export_profile', false],
            [$record . '/audit', 'GET', 'audit', true], ['/audit/(?P<audit>[1-9][0-9]*)/restore', 'POST', 'restore', true],
            ['/jobs', 'GET', 'jobs', true], ['/jobs/(?P<job>[1-9][0-9]*)/retry', 'POST', 'retry', true],
            ['/languages', 'GET', 'languages', false], [$record . '/translation', 'POST', 'translation', false], ['/translations/link', 'POST', 'link_translations', true],
        ];
        foreach ($routes as [$path, $method, $handler, $admin]) {
            register_rest_route('acf-vf/v1', $path, [
                'methods' => $method,
                'permission_callback' => static function () use ($admin) {
                    if (!get_current_user_id()) { return self::error('unauthorized', 'Authentication required.', 401); }
                    if ($admin && !current_user_can('manage_options')) { return self::error('forbidden', 'Configuration requires manage_options.', 403); }
                    return true;
                },
                'callback' => static function ($request) use ($handler) {
                    $result = self::dispatch($handler, $request);
                    if (is_wp_error($result)) { return $result; }
                    $response = rest_ensure_response($result);
                    $response->header('Cache-Control', 'private, no-store, max-age=0');
                    $response->header('Vary', 'Cookie, Authorization');
                    return $response;
                },
            ]);
        }
    }

    private static function dispatch(string $handler, \WP_REST_Request $r) {
        $data = self::input($r);
        if (is_wp_error($data)) { return $data; }
        $object = $r->get_url_params()['object'] ?? '';
        foreach (['id', 'job', 'audit'] as $key) {
            $raw_id = $r->get_url_params()[$key] ?? null;
            if ($raw_id !== null && filter_var($raw_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) { return self::error('invalid_id', 'ID is outside the supported integer range.'); }
        }
        $id = (int) ($r->get_url_params()['id'] ?? 0);
        switch ($handler) {
            case 'schema': return self::keys($data, []) ?: Migrations::export();
            case 'objects':
                $error = self::keys($data, []); if ($error) { return $error; }
                $items = [];
                foreach (Schema::table_objects() as $slug => $definition) {
                    if (Permissions::can('read', $slug)) { $items[] = self::descriptor($slug); }
                }
                return ['items' => $items];
            case 'object': return self::keys($data, []) ?: self::descriptor($object);
            case 'form':
                $error = self::keys($data, ['record_id']);
                if ($error) { return $error; }
                $record_id = $data['record_id'] ?? 0;
                if (filter_var($record_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) { return self::error('invalid_id', 'record_id must be a nonnegative integer.'); }
                return Admin::form_metadata($object, (int) $record_id);
            case 'save_object':
                $error = Migrations::definition($data);
                return $error ?: Schema::save_object($data);
            case 'listing': case 'query':
                $error = self::query_args($data);
                return $error ?: Query::run($object, $data);
            case 'read': return self::keys($data, []) ?: Records::read($id);
            case 'quick_add': return self::record_payload($data) ?: ListEditing::quick_add($object,$data);
            case 'inline':
                $error=self::keys($data,['field','value','version']);
                if ($error) { return $error; }
                if (!is_string($data['field']??null) || !is_int($data['version']??null) || !array_key_exists('value',$data)) { return self::error('inline_input','Supply field, value and an integer version.'); }
                return ListEditing::inline_update($id,$data['field'],$data['value'],$data['version']);
            case 'table_preferences': return self::keys($data,[]) ?: TablePreferences::get($object);
            case 'save_table_preferences': return self::keys($data,['columns','sticky_header']) ?: TablePreferences::save($object,$data);
            case 'native_table':
                $error=self::keys($data,['fields']);
                if ($error) { return $error; }
                return self::is_list($data['fields']??null)?NativeTables::configure($object,$data['fields']):self::error('native_table_input','Supply an ordered field-key list.');
            case 'remove_native_table': return self::keys($data,[]) ?: NativeTables::remove($object);
            case 'create': case 'update':
                $error = self::record_payload($data);
                if ($error) { return $error; }
                if ($handler === 'update') {
                    $record = Records::read($id);
                    if (is_wp_error($record)) { return $record; }
                    $object = $record['object'];
                }
                return Records::save($object, $data, $id, ['source' => 'rest']);
            case 'delete':
                $error = self::keys($data, []);
                if ($error) { return $error; }
                $deleted = Records::delete($id, ['source' => 'rest']);
                return is_wp_error($deleted) ? $deleted : ['deleted' => true, 'id' => $id];
            case 'export': return ImportExport::export($object, $data);
            case 'columns': return self::keys($data, []) ?: Lists::columns($object);
            case 'configure_columns':
                $error = self::keys($data, ['columns']);
                if ($error) { return $error; }
                return self::is_list($data['columns'] ?? null) ? Lists::configure($object, $data['columns']) : self::error('columns_input', 'Supply an ordered columns list.');
            case 'audit': case 'jobs':
                $error = self::keys($data, ['page', 'per_page']) ?: self::query_args($data);
                if ($error) { return $error; }
                return $handler === 'audit' ? Audit::history($id, $data) : Jobs::listing($data);
            case 'languages': return self::keys($data, []) ?: ['items' => Localization::languages()];
            case 'translation':
                $error = self::keys($data, ['language']);
                if ($error) { return $error; }
                return is_string($data['language'] ?? null) ? Localization::translate_record($id, $data['language']) : self::error('translation_input', 'Supply a language code.');
            case 'link_translations':
                $error = self::keys($data, ['translations']);
                if ($error) { return $error; }
                return is_array($data['translations'] ?? null) ? Localization::link_records($data['translations']) : self::error('translation_input', 'Supply translations keyed by language.');
        }
        return apply_filters('acf_vf/app/rest_dispatch',self::error('vf_provider_missing','This operation requires a compatible Pro add-on.',409),$handler,$r,$data);
    }

    private static function descriptor(string $object) {
        $definition = Schema::object($object);
        if (!$definition || !Permissions::can('read', $object)) { return self::error('forbidden', 'Object is unavailable.', 403); }
        $fields = [];
        foreach (Schema::fields($object) as $name => $field) {
            if (!Permissions::can('read', $object, 0, $name)) { continue; }
            $fields[$name] = array_intersect_key($field, array_flip(['name', 'label', 'type', 'required', 'choices']));
        }
        return ['slug' => $object, 'label' => $definition['label'], 'singular' => $definition['singular'], 'fields' => $fields, 'can_create' => Permissions::can('create', $object), 'can_export' => Permissions::can('export', $object)];
    }

    private static function input(\WP_REST_Request $r) {
        $body = (string) $r->get_body();
        if (strlen($body) > 1048576) { return self::error('input_limit', 'Request exceeds 1 MiB.', 413); }
        $query = $r->get_query_params();
        unset($query['_locale'], $query['_wpnonce']);
        if ($r->get_method() === 'GET') {
            foreach (['page', 'per_page'] as $key) {
                if (isset($query[$key]) && is_string($query[$key]) && preg_match('/^[1-9][0-9]{0,4}$/D', $query[$key])) { $query[$key] = (int) $query[$key]; }
            }
            $data = $query;
        } else {
            if ($query) { return self::error('input_query', 'Supply operation data in the JSON body.'); }
            if ($body === '') { $data = []; }
            else {
                if (!$r->is_json_content_type()) { return self::error('input_type', 'Use application/json.', 415); }
                $raw = json_decode($body, false, self::JSON_DEPTH);
                if (json_last_error() !== JSON_ERROR_NONE || !is_object($raw)) { return self::error('input_json', 'Body must be a valid bounded JSON object.'); }
                $data = json_decode($body, true, self::JSON_DEPTH);
            }
        }
        return self::bounded($data) ? $data : self::error('input_limit', 'Input nesting or size exceeds limits.');
    }

    public static function bounded($value, int $depth = 0): bool {
        if ($depth > self::MAX_INPUT_DEPTH) { return false; }
        if (is_array($value)) {
            if (count($value) > 1000) { return false; }
            foreach ($value as $key => $item) {
                if (strlen((string) $key) > 191 || !self::bounded($item, $depth + 1)) { return false; }
            }
            return true;
        }
        return is_null($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value)) || (is_string($value) && strlen($value) <= 65536);
    }

    public static function is_list($value): bool { return is_array($value) && (!$value || array_keys($value) === range(0, count($value) - 1)); }

    public static function keys(array $input, array $allowed) {
        if (array_diff(array_keys($input), $allowed)) { return self::error('unknown_input', 'Unknown input properties.'); }
        if (!self::bounded($input)) { return self::error('input_limit', 'Input nesting or size exceeds limits.'); }
        return null;
    }

    public static function record_payload(array $input) {
        $error = self::keys($input, ['title', 'status', 'version', 'fields']);
        if ($error) { return $error; }
        if (array_key_exists('title', $input) && (!is_string($input['title']) || strlen($input['title']) > 1000)) { return self::error('record_title', 'Title must be a string of at most 1000 bytes.'); }
        // Records owns allowed creations/transitions and preservation of native statuses.
        if (array_key_exists('status', $input) && (!is_string($input['status']) || !get_post_status_object($input['status']))) { return self::error('record_status', 'Unsupported record status.'); }
        if (array_key_exists('version', $input) && (!is_int($input['version']) || $input['version'] < 0)) { return self::error('record_version', 'Version must be a nonnegative integer.'); }
        if (array_key_exists('fields', $input) && (!is_array($input['fields']) || ($input['fields'] && self::is_list($input['fields'])))) { return self::error('record_fields', 'Fields must be an object keyed by field name.'); }
        return null;
    }

    public static function query_args(array $args) {
        $error = self::keys($args, ['page', 'per_page', 'search', 'sort', 'filters', 'metrics']);
        if ($error) { return $error; }
        foreach (['page', 'per_page'] as $key) {
            if (array_key_exists($key, $args) && (!is_int($args[$key]) || $args[$key] < 1 || $args[$key] > ($key === 'per_page' ? 100 : 10000))) { return self::error('query_page', 'Invalid pagination.'); }
        }
        if (array_key_exists('search', $args) && (!is_string($args['search']) || strlen($args['search']) > 200)) { return self::error('query_search', 'Search must be at most 200 bytes.'); }
        if (array_key_exists('sort', $args)) {
            if (!is_array($args['sort']) || !$args['sort']) { return self::error('query_sort','Supply sort columns.'); }
            $sort=array_key_exists('field',$args['sort'])?[$args['sort']]:$args['sort'];
            if (!self::is_list($sort) || count($sort)>10) { return self::error('query_sort','Supply at most 10 sort columns.'); }
            foreach ($sort as $column) {
                if (!is_array($column) || self::keys($column,['field','direction','mode']) || !is_string($column['field']??null) || !in_array($column['direction']??null,['ASC','DESC','asc','desc'],true) || (isset($column['mode']) && !in_array($column['mode'],['raw','label','display'],true))) { return self::error('query_sort','Supply sort field and ASC or DESC direction.'); }
            }
        }
        if (array_key_exists('filters', $args)) {
            $leaves=0; $error=self::filter_shape($args['filters'],$leaves);
            if ($error) { return $error; }
        }
        if (array_key_exists('metrics',$args)) {
            if (!self::is_list($args['metrics']) || count($args['metrics'])>20) { return self::error('query_metrics','Supply at most 20 metrics.'); }
            foreach ($args['metrics'] as $metric) { if (!is_array($metric) || self::keys($metric,['id','operation','field']) || !is_string($metric['id']??null) || !in_array($metric['operation']??null,['sum','average','min','max','count'],true) || (isset($metric['field']) && !is_string($metric['field']))) { return self::error('query_metrics','Malformed metric.'); } }
        }
        return null;
    }

    private static function filter_shape($filters,int &$leaves,int $depth=0) {
        if (!is_array($filters) || $depth>Query::MAX_FILTER_DEPTH) { return self::error('query_filters','Supply bounded filters.'); }
        if (array_key_exists('relation',$filters)) {
            if (self::keys($filters,['relation','conditions']) || !in_array($filters['relation'],['AND','OR'],true) || !self::is_list($filters['conditions']??null) || !$filters['conditions']) { return self::error('query_filters','Malformed filter group.'); }
            $filters=$filters['conditions'];
        } elseif (!self::is_list($filters)) { return self::error('query_filters','Supply an ordered filters list.'); }
        foreach ($filters as $filter) {
            if (!is_array($filter)) { return self::error('query_filter','Malformed filter.'); }
            if (array_key_exists('relation',$filter)) { $error=self::filter_shape($filter,$leaves,$depth+1); if ($error) { return $error; } continue; }
            if (++$leaves>50 || self::keys($filter,['field','operator','value','mode']) || !is_string($filter['field']??null) || !in_array($filter['operator']??null,['eq','ne','contains','starts_with','in','gt','gte','lt','lte','between','empty','not_empty','relative_date'],true) || (isset($filter['mode']) && !in_array($filter['mode'],['raw','label','display'],true))) { return self::error('query_filter','Malformed filter.'); }
        }
        return null;
    }

    public static function error(string $code, string $message, int $status = 400): \WP_Error { return new \WP_Error($code, $message, ['status' => $status]); }
}
