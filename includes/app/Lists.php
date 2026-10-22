<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Native WordPress list enhancements and the same services used by the workspace. */
final class Lists {
    const EXPORT_LIMIT = 1000;
    const EXPORT_BYTES = 5242880;
    private static $booted = false;
    private static $hooked = [];
    private static $querying = false;
    private static $notice = '';
    private static $native_counts = [];
    private static $counting = false;

    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        foreach (Schema::objects() as $object => $_definition) { self::register_hooks($object); }
        add_action('registered_post_type', [__CLASS__, 'register_hooks']);
        add_filter('posts_pre_query', [__CLASS__, 'native_query'], 20, 2);
        add_filter('wp_count_posts', [__CLASS__, 'native_counts'], 20, 3);
        foreach (['acf_vf/app/record_saved','acf_vf/app/record_deleted'] as $event) {
            add_action($event, static function () { self::$native_counts = []; });
        }
        add_action('restrict_manage_posts', [__CLASS__, 'native_filters'], 20, 2);
        add_action('admin_head-edit.php', [__CLASS__, 'native_widths']);
        add_action('admin_notices', [__CLASS__, 'native_notice']);
        add_filter('disable_months_dropdown', static function ($disabled, $object) { return Schema::object($object) ? true : $disabled; }, 10, 2);
    }

    public static function register_hooks(string $object): void {
        if (isset(self::$hooked[$object]) || !Schema::object($object)) { return; }
        self::$hooked[$object] = true;
        add_filter('manage_' . $object . '_posts_columns', static function ($columns) use ($object) {
            $configured = self::native_columns($object);
            if (is_wp_error($configured)) { return []; }
            $result = isset($columns['cb']) ? ['cb' => $columns['cb']] : [];
            foreach ($configured as $column) { $result[$column['field'] === 'title' ? 'title' : 'vf_' . $column['field']] = $column['label']; }
            return $result;
        });
        add_filter('manage_edit-' . $object . '_sortable_columns', static function ($columns) use ($object) {
            $configured = self::native_columns($object);
            if (is_wp_error($configured)) { return []; }
            foreach ($configured as $column) {
                if ($column['sortable']) { $columns[$column['field'] === 'title' ? 'title' : 'vf_' . $column['field']] = 'vf_' . $column['field']; }
            }
            return $columns;
        });
        add_action('manage_' . $object . '_posts_custom_column', static function ($key, $id) use ($object) {
            if (strpos($key, 'vf_') !== 0) { return; }
            $configured = self::native_columns($object);
            if (is_wp_error($configured)) { return; }
            $record = Records::read((int) $id);
            if (is_wp_error($record)) { return; }
            foreach ($configured as $column) {
                if ('vf_' . $column['field'] !== $key) { continue; }
                echo '<span class="vf-list-value" data-record-id="' . esc_attr($id) . '" data-version="' . esc_attr($record['version']) . '" data-field="' . esc_attr($column['field']) . '" data-editable="' . ($column['editable'] && Permissions::can('update', $object, (int)$id, $column['field']) ? '1' : '0') . '">';
                echo self::format_value($object, $record, $column, true); // Escaped by formatter.
                echo '</span>';
            }
        }, 10, 2);
        // Core's status links otherwise disclose global counts for scoped objects.
        add_filter('views_edit-' . $object, static function ($views) use ($object) {
            $result = Query::run($object, ['per_page' => 1]);
            if (is_wp_error($result)) { return []; }
            return ['all' => '<a href="' . esc_url(admin_url('edit.php?post_type=' . $object)) . '">' . esc_html(sprintf(__('All (%s)', 'validated-field-for-acf'), number_format_i18n($result['total']))) . '</a>'];
        });
    }

    public static function columns(string $object) {
        $error = self::access($object);
        if ($error) { return $error; }
        $stored = get_option('acf_vf_list_columns', []);
        $columns = is_array($stored) && isset($stored[$object]) && is_array($stored[$object]) ? $stored[$object] : array_merge([['field' => 'title']], array_map(static function ($name) { return ['field' => $name]; }, array_slice(array_keys(Schema::fields($object)), 0, 49)));
        // A configured column does not make its field readable to a later caller.
        $columns = array_values(array_filter($columns, static function ($column) use ($object) {
            return is_array($column) && isset($column['field']) && is_string($column['field']) && (self::builtin($column['field']) || Permissions::can('read', $object, 0, $column['field']));
        }));
        return self::normalize_columns($object, $columns);
    }

    public static function configure(string $object, array $columns) {
        if (!current_user_can('manage_options') || !Permissions::can('configure', $object)) { return self::error('lists_forbidden', 'Column configuration requires manage_options.', 403); }
        $normalized = self::normalize_columns($object, $columns);
        if (is_wp_error($normalized)) { return $normalized; }
        $stored = get_option('acf_vf_list_columns', []);
        $stored = is_array($stored) ? $stored : [];
        $stored[$object] = array_map(static function ($column) { return array_intersect_key($column, array_flip(['field','label','width','formatter'])); }, $normalized);
        update_option('acf_vf_list_columns', $stored, false);
        return $normalized;
    }

    /** Strict validation for view/export columns; unlike defaults, hidden requests fail. */
    public static function normalize_columns(string $object, array $columns) {
        $error = self::access($object);
        if ($error) { return $error; }
        if (count($columns) > 50 || ($columns && array_keys($columns) !== range(0, count($columns) - 1))) { return self::error('lists_columns', 'Provide an ordered list of at most 50 columns.'); }
        $fields = Schema::fields($object); $result = []; $seen = [];
        foreach ($columns as $column) {
            if (is_string($column)) { $column = ['field' => $column]; }
            if (!is_array($column) || array_diff(array_keys($column), ['field','label','width','formatter','type','sortable','editable'])) { return self::error('lists_columns', 'Invalid column definition.'); }
            $name = $column['field'] ?? null;
            if (!is_string($name) || isset($seen[$name]) || (!self::builtin($name) && !isset($fields[$name]))) { return self::error('lists_columns', 'Column field is unknown or duplicated.'); }
            if (!self::builtin($name) && !Permissions::can('read', $object, 0, $name)) { return self::error('lists_forbidden', 'A requested column is unavailable.', 403); }
            $seen[$name] = true;
            $definition = $fields[$name] ?? ['type' => in_array($name, ['id','version'], true) ? 'number' : 'text', 'label' => ucfirst($name)];
            $native = self::native_field($definition);
            $type = $native['type'] ?? 'text';
            $label = $column['label'] ?? ($definition['label'] ?? ucfirst($name));
            $width = $column['width'] ?? '';
            $formatter = $column['formatter'] ?? 'auto';
            if (!is_string($label) || trim($label) === '' || strlen($label) > 200 || !is_string($width) || !is_string($formatter)) { return self::error('lists_columns', 'Invalid label, width or formatter.'); }
            if ($width !== '' && !preg_match('/^(?:[4-9][0-9]|[1-9][0-9]{2}|1[01][0-9]{2}|1200)px$|^(?:[5-9]|[1-9][0-9]|100)%$/D', $width)) { return self::error('lists_width', 'Width must be 40–1200px or 5–100%.'); }
            $allowed = ['auto', 'text'];
            if (in_array($type, ['number','range'], true)) { $allowed[] = 'number'; }
            if ($type === 'true_false') { $allowed[] = 'boolean'; }
            if (in_array($type, ['date_picker','date_time_picker','time_picker'], true)) { $allowed[] = 'date'; }
            if (in_array($type, ['select','radio','checkbox','button_group'], true)) { $allowed[] = 'choices'; }
            if (self::relationship($native)) { $allowed = ['auto','relationship']; }
            if (!in_array($formatter, $allowed, true)) { return self::error('lists_formatter', 'Formatter is unavailable for this field type.'); }
            $scalar = self::scalar_field($definition);
            $result[] = ['field'=>$name, 'label'=>sanitize_text_field($label), 'width'=>$width, 'formatter'=>$formatter, 'type'=>$type,
                'sortable'=>self::builtin($name) || (empty($native['multiple']) && !self::relationship($native) && in_array($type, ['text','textarea','number','range','email','url','password','true_false','select','radio','button_group','date_picker','date_time_picker','time_picker','color_picker'], true)),
                'editable'=>Extensions::has('lists.inline') && !self::builtin($name) && $scalar && Permissions::can('update', $object, 0, $name)];
        }
        return $result;
    }

    public static function export_csv(string $object, array $query = [], array $columns = []) {
        $error = self::access($object, 'export');
        if ($error) { return $error; }
        $columns = $columns ? self::normalize_columns($object, $columns) : self::columns($object);
        if (is_wp_error($columns)) { return $columns; }
        if (!$columns) { return self::error('lists_columns', 'Select at least one readable column.'); }
        // Validate supplied pagination too, before replacing it for the complete bounded export.
        $validated = Query::run($object, $query);
        if (is_wp_error($validated)) { return $validated; }
        if ($validated['total'] > self::EXPORT_LIMIT) { return self::error('lists_export_limit', 'Export exceeds 1000 records. Narrow the query.'); }
        $query['page'] = 1; $query['per_page'] = 100;
        $content = self::csv_row(array_column($columns, 'label')); $total = 0;
        do {
            $page = Query::run($object, $query);
            if (is_wp_error($page)) { return $page; }
            if ($page['total'] > self::EXPORT_LIMIT) { return self::error('lists_export_limit', 'Export exceeds 1000 records. Narrow the query.'); }
            foreach ($page['items'] as $record) {
                if (++$total > self::EXPORT_LIMIT) { return self::error('lists_export_limit', 'Export exceeds 1000 records. Narrow the query.'); }
                if (!Permissions::can('export', $object, $record['id'])) { return self::error('lists_forbidden', 'Export includes a record unavailable for export.', 403); }
                $cells = [];
                foreach ($columns as $column) {
                    if (!self::builtin($column['field']) && !Permissions::can('read', $object, $record['id'], $column['field'])) { $cells[] = ''; continue; }
                    $cells[] = self::format_value($object, $record, $column);
                }
                $content .= self::csv_row($cells);
                if (strlen($content) > self::EXPORT_BYTES) { return self::error('lists_export_bytes', 'Export exceeds 5MiB. Narrow the query.'); }
            }
            ++$query['page'];
        } while ($query['page'] <= $page['total_pages']);
        return ['filename'=>sanitize_file_name($object . '-export.csv'), 'mime_type'=>'text/csv; charset=UTF-8', 'content'=>$content, 'total'=>$total, 'columns'=>$columns];
    }

    private static function csv_row(array $cells): string {
        return implode(',', array_map(static function ($cell) {
            $cell = str_replace("\0", '', (string)$cell);
            if (preg_match('/^[\x00-\x20\x7f\x{00a0}]*[=+\-@]/u', $cell) || preg_match('/^[\t\r\n]/', $cell)) { $cell = "'" . $cell; }
            return '"' . str_replace('"', '""', $cell) . '"';
        }, $cells)) . "\r\n";
    }

    /** Caller-provided records cannot bypass current record and field authorization. */
    public static function format_value(string $object, array $record, array $column, bool $html = false): string {
        $id = (int)($record['id'] ?? 0); $name = $column['field'] ?? '';
        if (!$id || ($record['object'] ?? '') !== $object || !Permissions::can('read', $object, $id)) { return ''; }
        $definition = Schema::fields($object)[$name] ?? null;
        if (!self::builtin($name) && (!$definition || !Permissions::can('read', $object, $id, $name))) { return ''; }
        $value = self::builtin($name) ? ($record[$name] ?? '') : ($record['fields'][$name] ?? '');
        $native = self::native_field($definition ?? []); $type = $native['type'] ?? 'text';
        if (self::relationship($native)) { return self::relationship_labels($value, $html, $type); }
        $formatter = $column['formatter'] ?? 'auto';
        if ($formatter === 'auto') {
            if (in_array($type, ['select','radio','checkbox','button_group'], true)) { $formatter = 'choices'; }
            elseif ($type === 'true_false') { $formatter = 'boolean'; }
            elseif (in_array($type, ['number','range'], true)) { $formatter = 'number'; }
            elseif (in_array($type, ['date_picker','date_time_picker','time_picker'], true)) { $formatter = 'date'; }
            else { $formatter = 'text'; }
        }
        if ($formatter === 'choices') {
            $labels = [];
            foreach ((array)$value as $choice) {
                if (!is_scalar($choice)) { continue; }
                $label = $native['choices'][(string)$choice] ?? $choice;
                if (is_scalar($label)) { $labels[] = (string)$label; }
            }
            $text = implode(', ', $labels);
        } elseif ($formatter === 'boolean') { $text = $value ? __('Yes', 'validated-field-for-acf') : __('No', 'validated-field-for-acf'); }
        elseif ($formatter === 'number' && $value !== '' && $value !== null && is_numeric($value) && is_finite((float)$value)) {
            $fraction = strpos((string)$value, '.') === false ? '' : rtrim(substr(strrchr((string)$value, '.'), 1), '0');
            $text = number_format_i18n((float)$value, min(10, strlen($fraction)));
        } elseif ($formatter === 'date' && is_string($value) && $value !== '') {
            $format = $type === 'date_picker' ? 'Ymd' : ($type === 'time_picker' ? 'H:i:s' : 'Y-m-d H:i:s');
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, wp_timezone());
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
                $display = $type === 'date_picker' ? get_option('date_format') : ($type === 'time_picker' ? get_option('time_format') : get_option('date_format') . ' ' . get_option('time_format'));
                $text = wp_date($display, $date->getTimestamp(), wp_timezone());
            } else { $text = $value; }
        }
        else { $text = is_scalar($value) ? (string)$value : ($value ? __('Structured value', 'validated-field-for-acf') : ''); }
        return $html ? esc_html($text) : $text;
    }

    private static function relationship_labels($value, bool $html, string $type): string {
        $labels = [];
        foreach (array_slice(is_array($value) ? $value : [$value], 0, 100) as $target) {
            if (!is_scalar($target) || !ctype_digit((string)$target) || (int)$target < 1) { continue; }
            $target = (int)$target; $url = '';
            if ($type === 'user') {
                if ($target !== get_current_user_id() && !current_user_can('list_users')) { continue; }
                $user = get_userdata($target);
                if (!$user) { continue; }
                $label = $user->display_name;
                if ($html && current_user_can('edit_user', $target)) { $url = get_edit_user_link($target); }
            } else {
                $post = get_post($target);
                if (!$post) { continue; }
                if (Schema::object($post->post_type)) {
                    $record = Records::read($target);
                    if (is_wp_error($record)) { continue; }
                    $label = $record['title'];
                    if ($html && Permissions::can('update', $record['object'], $target)) { $url = get_edit_post_link($target, 'raw'); }
                } else {
                    // Unmanaged native posts/attachments still require WordPress record access.
                    if (!current_user_can('read_post', $target) || $post->post_status === 'trash') { continue; }
                    $label = $post->post_title;
                    if ($html && current_user_can('edit_post', $target)) { $url = get_edit_post_link($target, 'raw'); }
                }
                $label = $label ?: __('Untitled', 'validated-field-for-acf');
            }
            $labels[] = $html ? ($url ? '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>' : esc_html($label)) : $label;
        }
        return implode(', ', $labels);
    }

    private static function native_field(array $definition): array {
        return in_array($definition['type'] ?? '', ['validated_field','validated'], true) && isset($definition['sub_field']) && is_array($definition['sub_field']) ? array_replace($definition, $definition['sub_field']) : $definition;
    }
    public static function scalar_field(array $definition): bool {
        $field = self::native_field($definition);
        return empty($definition['formula']) && empty($definition['rollup']) && !self::relationship($field) && empty($field['multiple']) && in_array($field['type'] ?? '', ['text','textarea','number','range','email','url','password','true_false','select','radio','button_group','date_picker','date_time_picker','time_picker','color_picker'], true);
    }
    private static function relationship(array $field): bool { return !empty($field['relationship']) || in_array($field['type'] ?? '', ['relationship','post_object','page_link','user','image','file','gallery'], true); }
    private static function builtin(string $name): bool { return in_array($name, ['id','title','status','version'], true); }
    public static function access(string $object, string $action = 'read') {
        if (!Schema::object($object)) { return self::error('lists_object', 'Object is unavailable.', 404); }
        return Permissions::can($action, $object) ? null : self::error('lists_forbidden', 'Object access is unavailable.', 403);
    }
    public static function error(string $code, string $message, int $status = 400): \WP_Error { return new \WP_Error($code, $message, ['status'=>$status]); }

    private static function native_columns(string $object) {
        $id = self::request('vf_view');
        if ($id !== '') {
            $view = apply_filters('acf_vf/app/view', new \WP_Error('vf_provider_missing','Saved views require Pro.',['status'=>409]), $id);
            if (is_wp_error($view) || $view['object'] !== $object) { return self::error('lists_view', 'Saved view is unavailable.'); }
            if ($view['columns']) { return self::normalize_columns($object, $view['columns']); }
        }
        return self::columns($object);
    }

    /** Replace only the configured post type's main list query with the shared Query page. */
    public static function native_query($posts, \WP_Query $query) {
        if (self::$querying || !is_admin() || !$query->is_main_query() || !is_string($query->get('post_type')) || !Schema::object($query->get('post_type'))) { return $posts; }
        global $pagenow;
        if ($pagenow !== 'edit.php') { return $posts; }
        self::$querying = true;
        try {
            $object = $query->get('post_type');
            $args = ['page'=>max(1, (int)$query->get('paged')), 'per_page'=>min(100,max(1,(int)$query->get('posts_per_page')))];
            $view_id = self::request('vf_view');
            if ($view_id !== '') {
                $view = apply_filters('acf_vf/app/view', new \WP_Error('vf_provider_missing','Saved views require Pro.',['status'=>409]), $view_id);
                if (is_wp_error($view) || $view['object'] !== $object) { self::$notice = __('Saved view is unavailable.', 'validated-field-for-acf'); $query->found_posts = 0; $query->max_num_pages = 0; return []; }
                $args = array_replace($view['query'], $args);
            }
            if ($query->get('s') !== '') { $args['search'] = (string)$query->get('s'); }
            $orderby = $query->get('orderby');
            if (is_string($orderby) && strpos($orderby, 'vf_') === 0) { $args['sort'] = ['field'=>substr($orderby,3), 'direction'=>strtoupper((string)$query->get('order')) ?: 'ASC']; }
            $field = self::request('vf_filter_field');
            if ($field !== '') {
                $filter = ['field'=>$field, 'operator'=>self::request('vf_filter_operator'), 'value'=>self::request('vf_filter_value')];
                $args['filters'][] = $filter;
            }
            $status = $query->get('post_status');
            if (is_string($status) && $status !== '' && $status !== 'all' && $status !== 'any') { $args['filters'][] = ['field'=>'status','operator'=>'eq','value'=>$status]; }
            $result = Query::run($object, $args);
            if (is_wp_error($result)) { self::$notice = __('The list query is invalid or unavailable.', 'validated-field-for-acf'); $query->found_posts = 0; $query->max_num_pages = 0; return []; }
            $query->found_posts = $result['total']; $query->max_num_pages = $result['total_pages'];
            $query->set('posts_per_page', $args['per_page']);
            $query->set('no_found_rows', true);
            return array_values(array_filter(array_map(static function ($record) { return get_post($record['id']); }, $result['items'])));
        } finally { self::$querying = false; }
    }

    /** Core falls back to wp_count_posts on empty later pages; its cache is unscoped. */
    public static function native_counts($counts, string $object, string $permission = '') {
        global $pagenow;
        if (!is_admin() || $pagenow !== 'edit.php' || !Schema::object($object)) { return $counts; }
        $empty = (object)array_fill_keys(get_post_stati(), 0);
        if (self::$counting) { return $empty; }
        $key = get_current_user_id() . '|' . $object . '|' . $permission;
        if (isset(self::$native_counts[$key])) { return clone self::$native_counts[$key]; }
        self::$counting = true;
        try {
            $scoped = clone $empty;
            foreach (['publish','private','draft','pending'] as $status) {
                $result = Query::run($object, ['per_page'=>1,'filters'=>[['field'=>'status','operator'=>'eq','value'=>$status]]]);
                if (is_wp_error($result)) {
                    self::$notice = __('The list query is invalid or unavailable.', 'validated-field-for-acf');
                    return $empty;
                }
                $scoped->$status = $result['total'];
            }
            self::$native_counts[$key] = $scoped;
            return clone $scoped;
        } finally { self::$counting = false; }
    }

    public static function native_filters(string $object, string $which = 'top'): void {
        if ($which !== 'top' || !Schema::object($object)) { return; }
        $views = apply_filters('acf_vf/app/views', [], $object); $columns = self::columns($object);
        if (is_wp_error($views) || is_wp_error($columns)) { return; }
        echo '<label class="screen-reader-text" for="vf-view">' . esc_html__('Saved view', 'validated-field-for-acf') . '</label><select id="vf-view" name="vf_view"><option value="">' . esc_html__('All records', 'validated-field-for-acf') . '</option>';
        foreach ($views as $view) { echo '<option value="' . esc_attr($view['id']) . '"' . selected(self::request('vf_view'), $view['id'], false) . '>' . esc_html($view['label']) . '</option>'; }
        echo '</select><label class="screen-reader-text" for="vf-filter-field">' . esc_html__('Filter field', 'validated-field-for-acf') . '</label><select id="vf-filter-field" name="vf_filter_field"><option value="">' . esc_html__('No field filter', 'validated-field-for-acf') . '</option>';
        foreach ($columns as $column) { if ($column['sortable']) { echo '<option value="' . esc_attr($column['field']) . '"' . selected(self::request('vf_filter_field'), $column['field'], false) . '>' . esc_html($column['label']) . '</option>'; } }
        echo '</select><label class="screen-reader-text" for="vf-filter-operator">' . esc_html__('Filter comparison', 'validated-field-for-acf') . '</label><select id="vf-filter-operator" name="vf_filter_operator">';
        foreach (['eq'=>'Equals','ne'=>'Does not equal','contains'=>'Contains','starts_with'=>'Starts with','gt'=>'Greater than','gte'=>'At least','lt'=>'Less than','lte'=>'At most','empty'=>'Empty','not_empty'=>'Not empty'] as $value=>$label) { echo '<option value="' . esc_attr($value) . '"' . selected(self::request('vf_filter_operator') ?: 'eq', $value, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select><label class="screen-reader-text" for="vf-filter-value">' . esc_html__('Filter value', 'validated-field-for-acf') . '</label><input id="vf-filter-value" name="vf_filter_value" type="text" value="' . esc_attr(self::request('vf_filter_value')) . '" />';
    }

    public static function native_widths(): void {
        $screen = get_current_screen();
        if (!$screen || !Schema::object($screen->post_type)) { return; }
        $columns = self::native_columns($screen->post_type);
        if (is_wp_error($columns)) { return; }
        echo '<style>';
        foreach ($columns as $column) { if ($column['width'] !== '') { echo '.wp-list-table .column-' . esc_attr($column['field'] === 'title' ? 'title' : 'vf_' . $column['field']) . '{width:' . esc_html($column['width']) . '}'; } }
        echo '</style>';
    }
    public static function native_notice(): void { if (self::$notice !== '') { echo '<div class="notice notice-error" role="alert"><p>' . esc_html(self::$notice) . '</p></div>'; } }
    private static function request(string $key): string { return isset($_GET[$key]) && is_string($_GET[$key]) ? wp_unslash($_GET[$key]) : ''; }


}
