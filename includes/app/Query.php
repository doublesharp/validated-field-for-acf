<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;

/** Permission-filtered, typed queries shared by the UI and integrations. */
final class Query {
    private const OPERATORS = ['eq', 'ne', 'contains', 'starts_with', 'in', 'gt', 'gte', 'lt', 'lte', 'between', 'empty', 'not_empty'];

    public static function run(string $object, array $args = []) {
        if (!Schema::object($object)) { return self::error('Object is unavailable.', 404); }
        $current = Schema::assert_current($object);
        if (is_wp_error($current)) { return $current; }
        if (!Permissions::can('read', $object)) { return self::error('This object cannot be read.', 403); }
        if (array_diff(array_keys($args), ['page', 'per_page', 'search', 'sort', 'filters'])) { return self::error('Unknown query option.'); }
        $page = $args['page'] ?? 1; $per_page = $args['per_page'] ?? 25;
        if (filter_var($page, FILTER_VALIDATE_INT) === false || $page < 1 || filter_var($per_page, FILTER_VALIDATE_INT) === false || $per_page < 1 || $per_page > 100) { return self::error('Page must be positive; per_page must be between 1 and 100.'); }
        if (isset($args['search']) && (!is_string($args['search']) || strlen($args['search']) > 200)) { return self::error('Search must be text of at most 200 bytes.'); }
        $filters = $args['filters'] ?? [];
        if (!is_array($filters) || count($filters) > 50) { return self::error('Filters must be a list of at most 50 conditions.'); }
        $definitions = Schema::fields($object);
        $types = self::types($object);
        $used = [];
        foreach ($filters as &$filter) {
            if (!is_array($filter) || array_diff(array_keys($filter), ['field', 'operator', 'value'])) { return self::error('Malformed filter.'); }
            $name = $filter['field'] ?? ''; $operator = $filter['operator'] ?? '';
            if (!is_string($name) || !isset($types[$name]) || !is_string($operator) || !in_array($operator, self::OPERATORS, true)) { return self::error('Unsupported field or operator.'); }
            if (isset($definitions[$name]) && !Permissions::can('read', $object, 0, $name)) { return self::error('A query field is not readable.', 403); }
            if (in_array($operator, ['contains', 'starts_with'], true) && $types[$name] === 'number') { return self::error('Text operators require text or list fields.'); }
            if ($types[$name] === 'list' && !in_array($operator, ['contains', 'empty', 'not_empty'], true)) { return self::error('List fields support contains, empty and not_empty.'); }
            $used[$name] = true;
            if (in_array($operator, ['empty', 'not_empty'], true)) { continue; }
            if (!array_key_exists('value', $filter)) { return self::error('Filter value is required.'); }
            if (in_array($operator, ['in', 'between'], true)) {
                if (!is_array($filter['value']) || !$filter['value'] || count($filter['value']) > 100 || ($operator === 'between' && count($filter['value']) !== 2)) { return self::error('Invalid filter value list.'); }
                $values = [];
                foreach ($filter['value'] as $value) { $typed = self::typed($value, $types[$name]); if (is_wp_error($typed)) { return $typed; } $values[] = $typed; }
                $filter['value'] = $values;
            } else {
                $typed = self::typed($filter['value'], $types[$name]);
                if (is_wp_error($typed)) { return $typed; }
                $filter['value'] = $typed;
            }
        }
        unset($filter);
        $sort = $args['sort'] ?? ['field' => 'id', 'direction' => 'DESC'];
        if (!is_array($sort) || array_diff(array_keys($sort), ['field', 'direction']) || !is_string($sort['field'] ?? null) || !isset($types[$sort['field']]) || $types[$sort['field']] === 'list' || !is_string($sort['direction'] ?? null) || !in_array(strtoupper($sort['direction']), ['ASC', 'DESC'], true)) { return self::error('Invalid sort field or direction.'); }
        if (isset($definitions[$sort['field']]) && !Permissions::can('read', $object, 0, $sort['field'])) { return self::error('Sort field is not readable.', 403); }
        $used[$sort['field']] = true;
        $items = [];
        $examined = 0;
        $scan_limit = max(1, min(10000, (int) apply_filters('acf_vf/app/query_scan_limit', 10000, $object)));
        $candidates = class_exists(Index::class) && apply_filters('acf_vf/app/use_index', true, $object, $args) ? Index::candidates($object, $filters, $types, $scan_limit, array_keys($used)) : null;
        if (is_wp_error($candidates)) { return $candidates; }
        // WP_Query builds prepared SQL. Evaluate the allowlisted predicates after access filtering,
        // so pagination and totals cannot reveal inaccessible records or hidden metadata.
        for ($batch = 1; ; $batch++) {
            $query_args = ['post_type' => $object, 'post_status' => ['publish', 'private', 'draft', 'pending'], 'posts_per_page' => 200, 'paged' => $batch, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'suppress_filters' => false];
            if (is_array($candidates)) { $query_args['post__in'] = $candidates ?: [0]; }
            $query = new \WP_Query($query_args);
            foreach ($query->posts as $id) {
                $record = Records::read((int) $id);
                if (is_wp_error($record)) { continue; }
                $values = array_merge($record['fields'], array_intersect_key($record, array_flip(['id', 'title', 'status', 'version'])));
                foreach ($used as $name => $_) { if (isset($definitions[$name]) && !array_key_exists($name, $record['fields'])) { continue 2; } }
                if (++$examined > $scan_limit) { return new \WP_Error('query_capacity', 'This query exceeds the configured processing limit.', ['status' => 413]); }
                if (isset($args['search']) && $args['search'] !== '') {
                    $match = stripos($record['title'], $args['search']) !== false;
                    foreach ($record['fields'] as $name => $value) { if (($types[$name] ?? '') === 'text' && is_scalar($value) && stripos((string) $value, $args['search']) !== false) { $match = true; break; } }
                    if (!$match) { continue; }
                }
                foreach ($filters as $filter) {
                    if (!self::matches($values[$filter['field']] ?? null, $filter['operator'], $filter['value'] ?? null, $types[$filter['field']])) { continue 2; }
                }
                $items[] = $record;
            }
            if (count($query->posts) < 200) { break; }
        }
        $name = $sort['field']; $direction = strtoupper($sort['direction']) === 'ASC' ? 1 : -1;
        usort($items, static function ($left, $right) use ($name, $direction, $types) {
            $a = array_key_exists($name, $left) ? $left[$name] : ($left['fields'][$name] ?? null);
            $b = array_key_exists($name, $right) ? $right[$name] : ($right['fields'][$name] ?? null);
            $comparison = $types[$name] === 'number' ? ((float) $a <=> (float) $b) : strcmp((string) $a, (string) $b);
            return ($comparison ?: ($left['id'] <=> $right['id'])) * $direction;
        });
        $current = Schema::assert_current($object);
        if (is_wp_error($current)) { return $current; }
        $total = count($items);
        return ['items' => array_slice($items, ((int) $page - 1) * (int) $per_page, (int) $per_page), 'total' => $total, 'page' => (int) $page, 'per_page' => (int) $per_page, 'total_pages' => (int) ceil($total / $per_page)];
    }

    public static function types(string $object): array {
        $definitions = Schema::fields($object);
        $types = ['id' => 'number', 'title' => 'text', 'status' => 'text', 'version' => 'number'];
        foreach ($definitions as $name => $field) {
            if ($field['type'] === 'validated_field') { $field = array_replace($field, ['type' => 'text'], $field['sub_field'] ?? []); }
            $type = $field['type'];
            if (in_array($type, ['number', 'range', 'true_false', 'user', 'post_object', 'image', 'file'], true) && empty($field['multiple'])) { $types[$name] = 'number'; }
            elseif (in_array($type, ['text', 'textarea', 'email', 'url', 'password', 'select', 'radio', 'button_group', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker'], true) && empty($field['multiple'])) { $types[$name] = 'text'; }
            elseif (in_array($type, ['checkbox', 'relationship', 'gallery'], true) || !empty($field['multiple'])) { $types[$name] = 'list'; }
        }
        return $types;
    }

    private static function typed($value, string $type) {
        if (!is_scalar($value)) { return self::error('Filter value must be scalar.'); }
        if ($type === 'number') {
            if (!is_numeric($value) || !is_finite((float) $value)) { return self::error('Filter value must be a finite number.'); }
            return (float) $value;
        }
        return (string) $value;
    }

    private static function matches($actual, string $operator, $expected, string $type): bool {
        $empty = $actual === null || $actual === '' || $actual === false || $actual === [];
        if ($operator === 'empty') { return $empty; }
        if ($operator === 'not_empty') { return !$empty; }
        if ($empty) { return $operator === 'ne'; }
        if ($type === 'list') { return is_array($actual) && in_array((string) $expected, array_map('strval', $actual), true); }
        if (!is_scalar($actual)) { return false; }
        if ($type === 'number' && !is_numeric($actual)) { return false; }
        $actual = $type === 'number' ? (float) $actual : (string) $actual;
        $comparison = static function ($a, $b) use ($type) { return $type === 'number' ? ($a <=> $b) : strcmp($a, $b); };
        switch ($operator) {
            case 'eq': return $actual === $expected;
            case 'ne': return $actual !== $expected;
            case 'contains': return strpos($actual, $expected) !== false;
            case 'starts_with': return strpos($actual, $expected) === 0;
            case 'in': return in_array($actual, $expected, true);
            case 'gt': return $comparison($actual, $expected) > 0;
            case 'gte': return $comparison($actual, $expected) >= 0;
            case 'lt': return $comparison($actual, $expected) < 0;
            case 'lte': return $comparison($actual, $expected) <= 0;
            case 'between': return $comparison($actual, $expected[0]) >= 0 && $comparison($actual, $expected[1]) <= 0;
        }
        return false;
    }

    private static function error(string $message, int $status = 400): \WP_Error { return new \WP_Error('query_invalid', $message, ['status' => $status]); }
}
