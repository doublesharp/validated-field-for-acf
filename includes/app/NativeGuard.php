<?php
/** Managed application records always pass through Records. */
namespace ACF_VF\App;
defined('ABSPATH') || exit;
final class NativeGuard {
    private static $booted = false;
    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        add_filter('map_meta_cap', static function ($caps, $cap, $user_id, $args) {
            if (Records::is_writing((int) ($args[0] ?? 0)) || Permissions::checking_native()) { return $caps; }
            if (in_array($cap, ['read_post', 'edit_post', 'delete_post', 'read_page', 'edit_page', 'delete_page'], true) && self::managed_id((int) ($args[0] ?? 0))) { return ['do_not_allow']; }
            return $caps;
        }, PHP_INT_MAX, 4);
        add_filter('wp_insert_post_empty_content', [__CLASS__, 'block_post'], PHP_INT_MAX, 2);
        add_filter('pre_delete_post', [__CLASS__, 'block_delete'], PHP_INT_MAX, 3);
        add_filter('pre_trash_post', [__CLASS__, 'block_delete'], PHP_INT_MAX, 3);
        foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $hook) {
            add_filter($hook, [__CLASS__, 'block_meta'], PHP_INT_MAX, 5);
        }
        add_filter('update_post_metadata_by_mid', [__CLASS__, 'block_meta_id'], PHP_INT_MAX, 4);
        add_filter('delete_post_metadata_by_mid', [__CLASS__, 'block_meta_id'], PHP_INT_MAX, 2);
        add_filter('xmlrpc_prepare_post', static function ($data, $post) {
            $id = is_array($post) ? (int) ($post['ID'] ?? 0) : (int) ($post->ID ?? 0);
            return self::managed_id($id) ? [] : $data;
        }, PHP_INT_MAX, 2);
        add_filter('xmlrpc_methods', [__CLASS__, 'xmlrpc_methods'], PHP_INT_MAX);
        foreach (['post_object', 'relationship', 'page_link'] as $type) {
            add_filter('acf/fields/' . $type . '/query', [__CLASS__, 'native_query'], PHP_INT_MAX, 3);
        }
        add_action('pre_get_posts', static function ($query) {
            if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || !did_action('acf/init')) { $query->query_vars = self::native_query($query->query_vars); }
        }, PHP_INT_MAX);
        add_filter('wp_count_posts', static function ($counts, $type) {
            if (!did_action('acf/init') && Schema::object((string) $type)) {
                $counts = clone $counts;
                foreach ($counts as $status => $_) { $counts->$status = 0; }
            }
            return $counts;
        }, PHP_INT_MAX, 2);
        add_filter('comments_clauses', static function ($clauses) {
            if (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST || !Schema::objects()) { return $clauses; }
            global $wpdb;
            $types = array_keys(Schema::objects());
            $placeholders = implode(',', array_fill(0, count($types), '%s'));
            $exclude = $wpdb->prepare("NOT EXISTS (SELECT 1 FROM {$wpdb->posts} vf_guard_post WHERE vf_guard_post.ID={$wpdb->comments}.comment_post_ID AND vf_guard_post.post_type IN ($placeholders))", $types);
            $clauses['where'] = '(' . ($clauses['where'] ?: '1=1') . ') AND ' . $exclude;
            return $clauses;
        }, PHP_INT_MAX);
        add_filter('get_edit_post_link', static function ($url, $id) {
            return Schema::object((string) get_post_type($id)) ? admin_url('admin.php?page=validated-field-workspace&record=' . (int) $id) : $url;
        }, 20, 2);
        add_action('load-post-new.php', [__CLASS__, 'redirect_editor']);
        add_action('load-post.php', [__CLASS__, 'redirect_editor']);
    }
    public static function redirect_editor(): void {
        $id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        $type = $id ? get_post_type($id) : sanitize_key($_GET['post_type'] ?? 'post');
        if (Schema::object((string) $type)) {
            wp_safe_redirect(admin_url('admin.php?page=validated-field-workspace' . ($id ? '&record=' . $id : '&object=' . rawurlencode($type)))); exit;
        }
    }
    public static function block_post($empty, array $post) {
        return Schema::object((string) ($post['post_type'] ?? get_post_type($post['ID'] ?? 0))) && !Records::permits_post_write($post) ? true : $empty;
    }
    public static function block_delete($delete, $post, $force = false) {
        return $post instanceof \WP_Post && Schema::object($post->post_type) && !Records::is_writing((int) $post->ID) ? false : $delete;
    }
    public static function block_meta($check, $id, $key, $value = null, $extra = null) {
        if (current_filter() === 'delete_post_metadata' && $extra) {
            // A global deletion can affect both managed and ordinary posts. Reject it
            // as one operation rather than reporting partial success.
            global $wpdb;
            $types = array_keys(Schema::objects());
            if ($types) {
                $placeholders = implode(',', array_fill(0, count($types), '%s'));
                $sql = "SELECT 1 FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key=%s AND p.post_type IN ($placeholders) LIMIT 1";
                if ($wpdb->get_var($wpdb->prepare($sql, array_merge([$key], $types)))) { return false; }
            }
        }
        if (Records::is_writing((int) $id) || !Schema::object((string) get_post_type($id))) { return $check; }
        if (in_array($key, ['_edit_lock', '_edit_last'], true)) { return $check; }
        return false;
    }
    public static function block_meta_id($check, $mid, $value = null, $key = null) {
        $metadata = get_metadata_by_mid('post', (int) $mid);
        return $metadata && Schema::object((string) get_post_type($metadata->post_id)) && !Records::is_writing((int) $metadata->post_id) ? false : $check;
    }
    /** Native selectors have no shared field/scope policy. Use VF Query for managed data. */
    public static function native_query(array $args, $field = null, $post_id = null): array {
        $types = $args['post_type'] ?? 'post';
        if ($types === 'any' || !$types) { $types = get_post_types(); }
        $types = array_values(array_diff((array) $types, array_keys(Schema::objects())));
        if (!$types) { $args['post__in'] = [0]; $args['post_type'] = 'post'; }
        else { $args['post_type'] = $types; }
        return $args;
    }

    private static function managed_id(int $id): bool {
        if ($id < 1) { return false; }
        $post = get_post($id);
        if ($post && $post->post_type === 'revision') { $post = get_post($post->post_parent); }
        return $post && Schema::object($post->post_type) !== null;
    }

    /** Legacy handlers serialize directly and never invoke xmlrpc_prepare_post. */
    public static function xmlrpc_methods(array $methods): array {
        // This anonymous legacy method reads comments with raw SQL. Neither
        // capability mapping nor comments_clauses can protect its output.
        if (isset($methods['mt.getTrackbackPings'])) {
            $original = $methods['mt.getTrackbackPings'];
            $handler = static function ($id) use ($original) {
                if (self::managed_id((int) $id)) { return new \IXR_Error(404, 'Record unavailable.'); }
                $server = $GLOBALS['wp_xmlrpc_server'] ?? null;
                if (is_string($original) && strpos($original, 'this:') === 0) {
                    return $server ? $server->{substr($original, 5)}($id) : new \IXR_Error(400, 'Invalid request.');
                }
                return call_user_func($original, $id);
            };
            $methods['mt.getTrackbackPings'] = [$handler, '__invoke'];
        }
        $positions = ['wp.getPost' => [3, 1, 2], 'wp.getPage' => [1, 2, 3], 'wp.getRevisions' => [3, 1, 2], 'wp.getCommentCount' => [3, 1, 2], 'metaWeblog.getPost' => [0, 1, 2], 'blogger.getPost' => [1, 2, 3], 'mt.getPostCategories' => [0, 1, 2], 'mt.setPostCategories' => [0, 1, 2]];
        foreach ($positions as $name => $position) {
            if (!isset($methods[$name])) { continue; }
            $original = $methods[$name];
            $handler = static function ($args) use ($original, $position) {
                $server = $GLOBALS['wp_xmlrpc_server'] ?? null;
                if (!$server || !is_array($args)) { return new \IXR_Error(400, 'Invalid request.'); }
                if (self::managed_id((int) ($args[$position[0]] ?? 0))) {
                    // Authenticate before denying so invalid credentials cannot probe IDs.
                    if (!$server->login((string) ($args[$position[1]] ?? ''), (string) ($args[$position[2]] ?? ''))) { return $server->error; }
                    return new \IXR_Error(404, 'Record unavailable.');
                }
                if (is_string($original) && strpos($original, 'this:') === 0) { return $server->{substr($original, 5)}($args); }
                return call_user_func($original, $args);
            };
            // IXR supports method arrays, but calls function_exists on bare closures.
            $methods[$name] = [$handler, '__invoke'];
        }
        return $methods;
    }
}
