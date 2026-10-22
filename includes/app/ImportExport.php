<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Small synchronous batches; every write uses Records and every lookup uses Query. */
final class ImportExport {
    /**
     * Build an authorized synchronous export of at most 1,000 records and 5 MiB.
     *
     * @param array $input format (json/csv), query without pagination, and optional field-name list.
     * @return array|\WP_Error filename, mime_type, content and total; no HTTP output is sent here.
     */
    public static function export(string $object, array $input = []) {
        $error = Rest::keys($input, ['format', 'query', 'fields']);
        if ($error) { return $error; }
        if (!Permissions::can('export', $object)) { return Rest::error('forbidden', 'Export access denied.', 403); }
        $format = $input['format'] ?? 'json';
        if (array_key_exists('format', $input) && !is_string($input['format'])) { return Rest::error('export_format', 'Format must be json or csv.'); }
        if (!in_array($format, ['json', 'csv'], true)) { return Rest::error('export_format', 'Format must be json or csv.'); }
        $query = array_key_exists('query', $input) ? $input['query'] : [];
        if (!is_array($query) || ($error = Rest::query_args($query))) { return $error ?: Rest::error('query_input', 'Query must be an object.'); }
        if (isset($query['page']) || isset($query['per_page'])) { return Rest::error('export_query', 'Export query must omit pagination.'); }
        $fields = array_key_exists('fields', $input) ? $input['fields'] : array_keys(Permissions::readable_fields($object, 0, Schema::fields($object)));
        if (!Rest::is_list($fields) || count($fields) > 200) { return Rest::error('export_fields', 'Fields must be a bounded list.'); }
        foreach ($fields as $field) {
            if (!is_string($field) || !Permissions::can('read', $object, 0, $field)) { return Rest::error('export_fields', 'Requested export field is unavailable.', 403); }
        }
        $items = []; $bytes = 0;
        for ($page = 1; $page <= 10; $page++) {
            $result = Query::run($object, $query + ['page' => $page, 'per_page' => 100]);
            if (is_wp_error($result)) { return $result; }
            if ($result['total'] > 1000) { return Rest::error('export_limit', 'Narrow the query to at most 1000 records.'); }
            foreach ($result['items'] as $item) {
                if (!Permissions::can('export', $object, $item['id'])) { continue; }
                $item['fields'] = array_intersect_key(Permissions::readable_fields($object, $item['id'], $item['fields']), array_flip($fields));
                $bytes += strlen(wp_json_encode($item));
                if ($bytes > 5242880) { return Rest::error('export_limit', 'Narrow the query to an export of at most 5 MiB.'); }
                $items[] = $item;
            }
            if ($page >= $result['total_pages']) { break; }
        }
        if ($format === 'json') { $content = wp_json_encode(['schema_version' => 1, 'object' => $object, 'records' => $items], JSON_PRETTY_PRINT); }
        else {
            $lines = [self::csv_row(array_merge(['id', 'title', 'status', 'version'], $fields))];
            foreach ($items as $item) {
                $cells = [$item['id'], $item['title'], $item['status'], $item['version']];
                foreach ($fields as $field) { $cells[] = $item['fields'][$field] ?? ''; }
                $lines[] = self::csv_row($cells);
            }
            $content = implode("\r\n", $lines) . "\r\n";
        }
        if (strlen($content) > 5242880) { return Rest::error('export_limit', 'Narrow the query to an export of at most 5 MiB.'); }
        return ['filename' => $object . '.' . $format, 'mime_type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8', 'content' => $content, 'total' => count($items)];
    }

    private static function csv_row(array $cells): string {
        return implode(',', array_map(static function ($value) {
            $value = is_array($value) ? wp_json_encode($value) : (string) $value;
            if (preg_match('/^[\x00-\x20]*[=+@\-]/', $value) || preg_match('/^[\t\r\n]/', $value)) { $value = "'" . $value; }
            return '"' . str_replace('"', '""', $value) . '"';
        }, $cells));
    }
}
