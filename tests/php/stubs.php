<?php
// Minimal WordPress stubs for TQK WP SEO stage tests. No WordPress needed.
define('ABSPATH', '/tmp/wp/');
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);

$GLOBALS['wp_version'] = '6.8.0';
$GLOBALS['__actions'] = [];
$GLOBALS['__menus'] = [];
$GLOBALS['__submenus'] = [];
$GLOBALS['__options'] = ['permalink_structure' => '/%postname%/'];
$GLOBALS['__flushes'] = 0;
$GLOBALS['__enqueued'] = ['style' => [], 'script' => []];
$GLOBALS['__settings'] = [];
$GLOBALS['__postmeta'] = [];
$GLOBALS['__tables'] = [];
$GLOBALS['__dbdelta'] = [];
$GLOBALS['__rewrite_rules'] = [];
$GLOBALS['__rest_routes'] = [];
$GLOBALS['__http_queue'] = [];
$GLOBALS['__cache'] = [];
$GLOBALS['__cond'] = [];
$GLOBALS['__posts'] = [];
$GLOBALS['__postmeta_map'] = [];
$GLOBALS['__attachments'] = [];
$GLOBALS['__terms'] = [];
$GLOBALS['__users'] = [];
$GLOBALS['__urls'] = [];
$GLOBALS['__queried_object'] = null;
$GLOBALS['__queried_object_id'] = 0;
$GLOBALS['__query_vars'] = [];
$GLOBALS['__status_header'] = 0;

class WP_Test_Response extends Exception {
    public $payload;
    public function __construct($payload) {
        $this->payload = $payload;
        parent::__construct('wp_send_json');
    }
}
class WP_Error {
    private $msg;
    public function __construct($msg = '') {
        $this->msg = $msg;
    }
    public function get_error_message() {
        return $this->msg;
    }
}

function add_action($hook, $cb, $prio = 10, $args = 1) {
    $GLOBALS['__actions'][$hook][] = $cb;
}
function remove_action($hook, $cb, $prio = 10) {
    $GLOBALS['__removed'][] = [$hook, is_string($cb) ? $cb : 'closure'];
    $GLOBALS['__actions'][$hook] = array_values(array_filter(
        $GLOBALS['__actions'][$hook] ?? [],
        fn($c) => $c !== $cb
    ));
    return true;
}
function remove_filter($hook, $cb, $prio = 10) {
    return remove_action($hook, $cb, $prio);
}
function current_theme_supports($feature) {
    return $GLOBALS['__theme_support'] ?? true;
}
function add_filter($hook, $cb, $prio = 10, $args = 1) {
    add_action($hook, $cb, $prio, $args);
}
function do_action($hook, ...$args) {
    $GLOBALS['__did'][$hook] = ($GLOBALS['__did'][$hook] ?? 0) + 1;
    foreach ($GLOBALS['__actions'][$hook] ?? [] as $cb) {
        call_user_func_array($cb, $args);
    }
}
function did_action($hook) {
    return $GLOBALS['__did'][$hook] ?? 0;
}
function apply_filters($hook, $value, ...$args) {
    foreach ($GLOBALS['__actions'][$hook] ?? [] as $cb) {
        $value = call_user_func_array($cb, array_merge([$value], $args));
    }
    return $value;
}
function register_activation_hook($file, $cb) {
    $GLOBALS['__activation'] = $cb;
}
function register_deactivation_hook($file, $cb) {
    $GLOBALS['__deactivation'] = $cb;
}
function add_meta_box($id, $title, $cb, $screen, $ctx = 'advanced', $prio = 'default') {
    $GLOBALS['__metaboxes'][] = compact('id', 'title', 'screen', 'ctx', 'prio');
}
function add_menu_page($title, $menu, $cap, $slug, $cb, $icon = '', $pos = null) {
    $GLOBALS['admin_page_hooks'][$slug] = strtolower(str_replace(' ', '-', $menu));
    $hookname = get_plugin_page_hookname($slug, '');
    if ($cb && $hookname) {
        add_action($hookname, $cb);
    }
    $GLOBALS['__menus'][] = compact('title', 'menu', 'cap', 'slug', 'icon', 'pos');
    return 'toplevel_page_' . $slug;
}
function get_plugin_page_hookname($plugin_page, $parent_page) {
    $hooks = $GLOBALS['admin_page_hooks'] ?? [];
    $parent = $parent_page;
    if (isset($GLOBALS['_parent_pages'][$plugin_page])) {
        $parent = $GLOBALS['_parent_pages'][$plugin_page];
    }
    $page_type = 'admin';
    if (empty($parent_page) || $parent_page === 'admin.php' || isset($hooks[$plugin_page])) {
        if (isset($hooks[$plugin_page])) {
            $page_type = 'toplevel';
        } elseif (isset($hooks[$parent])) {
            $page_type = $hooks[$parent];
        }
    } elseif (isset($hooks[$parent])) {
        $page_type = $hooks[$parent];
    }
    return $page_type . '_page_' . preg_replace('/\.php/', '', (string) $plugin_page);
}
function has_action($hook, $cb = false) {
    $list = $GLOBALS['__actions'][$hook] ?? [];
    if ($cb === false) {
        return !empty($list);
    }
    return in_array($cb, $list, true);
}
function add_submenu_page($parent, $title, $menu, $cap, $slug, $cb) {
    $GLOBALS['__submenus'][] = compact('parent', 'title', 'menu', 'cap', 'slug');
    $GLOBALS['_parent_pages'][$slug] = $parent;
    $hookname = get_plugin_page_hookname($slug, $parent);
    if ($cb && $hookname) {
        add_action($hookname, $cb);
    }
    return $parent . '_page_' . $slug;
}
function add_rewrite_rule($regex, $query, $pos = 'top') {
    $GLOBALS['__rewrite_rules'][] = compact('regex', 'query', 'pos');
}
function add_rewrite_tag($tag, $regex) {
    $GLOBALS['__rewrite_rules'][] = ['tag' => $tag, 'regex' => $regex];
}
function register_rest_route($ns, $route, $args) {
    $GLOBALS['__rest_routes'][] = $ns . $route;
}
function plugin_dir_path($f) {
    return rtrim(dirname($f), '/\\') . '/';
}
function plugin_dir_url($f) {
    return 'https://example.test/wp-content/plugins/tqk-wpseo/';
}
function plugin_basename($f) {
    return 'tqk-wpseo/' . basename($f);
}
function load_plugin_textdomain($d, $dep, $path) {
    $GLOBALS['__textdomain'] = [$d, $path];
    return true;
}
function get_option($k, $default = false) {
    return array_key_exists($k, $GLOBALS['__options']) ? $GLOBALS['__options'][$k] : $default;
}
function add_option($k, $v, $deprecated = '', $autoload = null) {
    // Like WordPress: never overwrites, returns false when the option exists.
    if (array_key_exists($k, $GLOBALS['__options'])) {
        return false;
    }
    $GLOBALS['__options'][$k] = $v;
    return true;
}
function update_option($k, $v) {
    $GLOBALS['__options'][$k] = $v;
    return true;
}
function delete_option($k) {
    unset($GLOBALS['__options'][$k]);
    return true;
}
function register_setting($group, $name, $args = []) {
    $GLOBALS['__settings'][] = [$group, $name];
}
function register_post_meta($type, $key, $args) {
    $GLOBALS['__postmeta'][] = $key;
    return true;
}
function flush_rewrite_rules() {
    $GLOBALS['__flushes']++;
}
function current_user_can($cap) {
    return $GLOBALS['__can'] ?? true;
}
function admin_url($path = '') {
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}
function home_url($path = '') {
    return 'https://example.test' . ($path !== '' && $path[0] !== '/' ? '/' . $path : $path);
}
function get_bloginfo($k) {
    return ['name' => 'Example Site', 'description' => 'Just another site', 'url' => 'https://example.test'][$k] ?? '';
}
function esc_textarea($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function esc_html($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function esc_attr($s) {
    return esc_html($s);
}
function esc_url($u) {
    // Mirrors wp_kses' url protocol allowlist: a javascript: value must not
    // survive sanitization, or URL tests prove nothing.
    $u = trim((string) $u);
    if ($u === '') {
        return '';
    }
    if (preg_match('#^\s*(javascript|data|vbscript)\s*:#i', $u)) {
        return '';
    }
    if (preg_match('#^\s*[a-z][a-z0-9+.-]*:#i', $u) && !preg_match('#^https?:#i', $u)) {
        return '';
    }
    return $u;
}
function __($s, $d = null) {
    return $s;
}
function _e($s, $d = null) {
    echo $s;
}
function esc_html__($s, $d = null) {
    return esc_html($s);
}
function esc_attr__($s, $d = null) {
    return esc_attr($s);
}
function esc_html_e($s, $d = null) {
    echo esc_html($s);
}
function sanitize_text_field($s) {
    return trim(strip_tags((string) $s));
}
function sanitize_textarea_field($s) {
    return trim(strip_tags((string) $s));
}
function wp_strip_all_tags($s, $remove_breaks = false) {
    $s = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s);
    $s = strip_tags((string) $s);
    return $remove_breaks ? trim(preg_replace('/[\r\n\t ]+/', ' ', $s)) : trim($s);
}
function wp_timezone_string() {
    return $GLOBALS['__timezone'] ?? 'UTC';
}
function sanitize_key($k) {
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $k));
}
function wp_unslash($v) {
    return is_string($v) ? stripslashes($v) : $v;
}
function wp_die($msg) {
    throw new Exception('wp_die: ' . $msg);
}
function wp_enqueue_style($h, $src, $deps = [], $ver = false) {
    $GLOBALS['__enqueued']['style'][] = $h;
}
function wp_enqueue_media($args = []) { $GLOBALS['__enqueued']['media'] = true; }
function wp_enqueue_script($h, $src, $deps = [], $ver = false, $footer = false) {
    $GLOBALS['__enqueued']['script'][] = $h;
}
function wp_nonce_field($action, $name = '_wpnonce', $referer = true, $display = true) {
    $f = '<input type="hidden" name="' . $name . '" value="valid" />';
    if ($display) {
        echo $f;
    }
    return $f;
}
function wp_verify_nonce($nonce, $action) {
    return $nonce === 'valid' ? 1 : false;
}
function check_ajax_referer($action, $key = false, $die = true) {
    $ok = ($GLOBALS['__bad_nonce'] ?? false) ? false : true;
    if (!$ok && $die) {
        throw new WP_Test_Response(['success' => false]);
    }
    return $ok;
}
function wp_send_json_success($data = null) {
    throw new WP_Test_Response(['success' => true, 'data' => $data]);
}
function wp_send_json_error($data = null) {
    throw new WP_Test_Response(['success' => false, 'data' => $data]);
}
function wp_json_encode($v, $options = 0) {
    return json_encode($v, $options);
}
function is_wp_error($t) {
    return $t instanceof WP_Error;
}
function wp_remote_post($url, $args = []) {
    $q = &$GLOBALS['__http_queue'];
    if (empty($q)) {
        return new WP_Error('no mocked response');
    }
    return array_shift($q);
}
function wp_remote_head($url, $args = []) {
    $q = &$GLOBALS['__http_queue'];
    if (empty($q)) {
        return new WP_Error('no mocked response');
    }
    return array_shift($q);
}
function wp_remote_get($url, $args = []) {
    $q = &$GLOBALS['__http_queue'];
    if (empty($q)) {
        return new WP_Error('no mocked response');
    }
    return array_shift($q);
}
function wp_remote_retrieve_headers($r) {
    return is_wp_error($r) ? [] : ($r['headers'] ?? []);
}
function wp_remote_retrieve_response_code($r) {
    return is_wp_error($r) ? 0 : (int) ($r['code'] ?? 0);
}
function wp_remote_retrieve_body($r) {
    return is_wp_error($r) ? '' : ($r['body'] ?? '');
}
function status_header($code) {
    $GLOBALS['__status_header'] = $code;
}
function wp_redirect($url, $status = 302) {
    throw new WP_Test_Response(['redirect' => $url, 'status' => $status]);
}
function is_admin() {
    return $GLOBALS['__is_admin'] ?? false;
}
function wp_doing_ajax() {
    return $GLOBALS['__doing_ajax'] ?? false;
}
function wp_create_nonce($action) {
    return 'valid';
}
function wp_localize_script($h, $name, $data) {
    $GLOBALS['__localized'][$h] = [$name, $data];
    return true;
}
function wp_schedule_single_event($ts, $hook, $args = []) {
    $GLOBALS['__cron'][] = compact('hook', 'args');
    return true;
}
function wp_next_scheduled($hook, $args = []) {
    return false;
}
function dbDelta($sql) {
    if (preg_match('/CREATE TABLE\s+(\S+)/i', (string) $sql, $m)) {
        $GLOBALS['__tables'][] = trim($m[1], '`');
    }
    $GLOBALS['__dbdelta'][] = $sql;
    return [];
}

// ---- Conditionals (driven by $GLOBALS['__cond']) ----
function __cond($k) {
    return $GLOBALS['__cond'][$k] ?? false;
}
function is_front_page() {
    return __cond('front');
}
function is_home() {
    return __cond('home');
}
function is_singular($t = '') {
    if (!__cond('singular')) {
        return false;
    }
    return $t === '' || get_post_type() === $t;
}
function is_archive() {
    return __cond('archive');
}
function is_search() {
    return __cond('search');
}
function is_404() {
    return __cond('is_404');
}
function is_category() {
    return __cond('category');
}
function is_tag() {
    return __cond('tag');
}
function is_tax() {
    return __cond('tax');
}
function is_author() {
    return __cond('author');
}
function is_date() {
    return __cond('date');
}
function is_post_type_archive() {
    return __cond('pta');
}
function is_attachment() {
    return __cond('attachment');
}
function get_queried_object() {
    return $GLOBALS['__queried_object'];
}
function get_queried_object_id() {
    return $GLOBALS['__queried_object_id'];
}
function get_query_var($k, $default = '') {
    return $GLOBALS['__query_vars'][$k] ?? $default;
}

// ---- Posts / terms / users ----
function __post($id) {
    $p = $GLOBALS['__posts'][$id] ?? null;
    return is_array($p) ? (object) $p : $p;
}
function get_post($id = null) {
    if ($id === null) {
        $id = get_queried_object_id();
    }
    return __post((int) $id);
}
function get_post_type($p = null) {
    $o = is_object($p) ? $p : __post((int) ($p ?? get_queried_object_id()));
    return $o->post_type ?? 'post';
}
function get_post_status($id) {
    return __post((int) $id)->post_status ?? false;
}
function get_the_title($p = null) {
    $o = is_object($p) ? $p : __post((int) ($p ?? get_queried_object_id()));
    return $o->post_title ?? '';
}
function get_the_excerpt($p = null) {
    $o = is_object($p) ? $p : __post((int) ($p ?? get_queried_object_id()));
    return $o->post_excerpt ?? '';
}
function get_permalink($id = null) {
    $o = is_object($id) ? $id : __post((int) ($id ?? get_queried_object_id()));
    return home_url('/' . ($o->post_name ?? $o->ID ?? ''));
}
if (!function_exists('get_preview_post_link')) {
    function get_preview_post_link($id = null) {
        return home_url('/?p=' . (int) (is_object($id) ? $id->ID : $id) . '&preview=true');
    }
}
function get_post_meta($id, $key = '', $single = false) {
    $m = $GLOBALS['__postmeta_map'][(int) $id] ?? [];
    if ($key === '') {
        return $m;
    }
    if (!array_key_exists($key, $m)) {
        return $single ? '' : [];
    }
    return $single ? $m[$key] : [$m[$key]];
}
function update_post_meta($id, $key, $value) {
    // Like WordPress: meta values are unslashed before storage.
    $GLOBALS['__postmeta_map'][(int) $id][$key] = wp_unslash($value);
    return true;
}
function wp_slash($v) {
    return is_string($v) ? addslashes($v) : $v;
}
function delete_post_meta($id, $key) {
    unset($GLOBALS['__postmeta_map'][(int) $id][$key]);
    return true;
}
function get_post_thumbnail_id($id) {
    return $GLOBALS['__thumbs'][(int) $id] ?? 0;
}
function wp_get_attachment_image_src($id, $size = 'full') {
    $u = $GLOBALS['__attachments'][(int) $id] ?? '';
    return $u === '' ? false : [$u, 800, 600];
}
function get_post_types($args = []) {
    return ['post', 'page'];
}
function get_post_type_object($pt) {
    return (object) ['name' => $pt, 'labels' => (object) ['singular_name' => ucfirst((string) $pt)]];
}
function get_posts($args = []) {
    $out = [];
    foreach ($GLOBALS['__posts'] as $id => $p) {
        $p = (object) $p;
        // 'any' matches every type / status, as in WordPress.
        if (isset($args['post_type']) && $args['post_type'] !== 'any' && !in_array($p->post_type, (array) $args['post_type'], true)) {
            continue;
        }
        if (isset($args['post_status']) && $args['post_status'] !== 'any' && !in_array($p->post_status, (array) $args['post_status'], true)) {
            continue;
        }
        $out[] = ($args['fields'] ?? '') === 'ids' ? $p->ID : $p;
    }
    if (($args['orderby'] ?? '') === 'ID') {
        $key = static fn($x) => is_object($x) ? (int) $x->ID : (int) $x;
        usort($out, static fn($x, $y) => $key($x) <=> $key($y));
        if (strtoupper((string) ($args['order'] ?? 'ASC')) === 'DESC') {
            $out = array_reverse($out);
        }
    }
    // WordPress treats numberposts -1 as "no limit".
    if (isset($args['numberposts']) && (int) $args['numberposts'] >= 0) {
        $out = array_slice($out, 0, (int) $args['numberposts']);
    }
    return $out;
}
function url_to_postid($url) {
    return $GLOBALS['__urls'][(string) $url] ?? 0;
}
function attachment_url_to_postid($url) {
    foreach ($GLOBALS['__attachments'] as $id => $u) {
        if ($u === (string) $url) {
            return (int) $id;
        }
    }
    return 0;
}
function get_terms($args = []) {
    return array_values(array_map(fn($t) => (object) $t, $GLOBALS['__terms']));
}
function get_term_link($t) {
    $t = (object) $t;
    return home_url('/' . ($t->taxonomy ?? 'cat') . '/' . ($t->slug ?? $t->term_id));
}
function get_the_author_meta($field, $id = 0) {
    $u = $GLOBALS['__users'][(int) $id] ?? ['display_name' => 'Author', 'user_nicename' => 'author'];
    return $u[$field] ?? '';
}
function remove_accents($s) {
    // Full Vietnamese set, like WordPress core remove_accents().
    $map = ['à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'đ' => 'd'];
    return strtr(mb_strtolower((string) $s, 'UTF-8'), $map);
}
function wp_cache_get($k, $group = '') {
    return $GLOBALS['__cache'][$group][$k] ?? false;
}
function wp_cache_set($k, $v, $group = '') {
    $GLOBALS['__cache'][$group][$k] = $v;
    return true;
}
function wp_cache_delete($k, $group = '') {
    unset($GLOBALS['__cache'][$group][$k]);
    return true;
}
function get_transient($k) {
    return $GLOBALS['__transients'][$k] ?? false;
}
function set_transient($k, $v, $exp = 0) {
    $GLOBALS['__transients'][$k] = $v;
    return true;
}
function delete_transient($k) {
    unset($GLOBALS['__transients'][$k]);
    return true;
}
function get_term($id) {
    foreach ($GLOBALS['__terms'] as $t) {
        if ((int) ($t['term_id'] ?? 0) === (int) $id) {
            return (object) $t;
        }
    }
    return null;
}
function get_the_terms($post_id, $tax) {
    $out = [];
    foreach ($GLOBALS['__post_terms'][(int) $post_id][$tax] ?? [] as $tid) {
        $t = get_term($tid);
        if ($t) {
            $out[] = $t;
        }
    }
    return $out === [] ? false : $out;
}
function get_post_type_archive_link($pt) {
    return home_url('/' . (string) $pt);
}

class FakeWPDB {
    public $prefix = 'wp_';
    public $tables = [];
    public $auto = [];
    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }
    public function prepare($q, ...$args) {
        foreach ($args as $a) {
            $q = preg_replace('/%[sd]/', is_int($a) || is_float($a) ? (string) $a : "'" . addslashes((string) $a) . "'", $q, 1);
        }
        return $q;
    }
    private function table_of($q) {
        if (preg_match('/FROM\s+`?([a-z0-9_]+)`?/i', (string) $q, $m)) {
            return $m[1];
        }
        return '';
    }
    private function where_of($q) {
        $conds = [];
        if (!preg_match('/WHERE\s+(.+?)(?:\s+ORDER BY|\s+LIMIT|$)/is', (string) $q, $m)) {
            return $conds;
        }
        foreach (preg_split('/\s+AND\s+/i', $m[1]) as $part) {
            $part = trim($part, ' ()');
            if (preg_match('/^([a-z0-9_]+)\s*!=\s*(.+)$/i', $part, $c)) {
                $conds[] = [$c[1], '!=', trim($c[2], " '")];
            } elseif (preg_match('/^([a-z0-9_]+)\s*=\s*(.+)$/i', $part, $c)) {
                $conds[] = [$c[1], '=', trim($c[2], " '")];
            } elseif (preg_match('/^([a-z0-9_]+)\s+IS\s+NULL$/i', $part, $c)) {
                $conds[] = [$c[1], 'null', null];
            } elseif (preg_match('/^([a-z0-9_]+)\s+IS\s+NOT\s+NULL$/i', $part, $c)) {
                $conds[] = [$c[1], 'notnull', null];
            }
        }
        return $conds;
    }
    private function match_row($row, $conds) {
        foreach ($conds as [$col, $op, $val]) {
            $has = array_key_exists($col, $row) && $row[$col] !== null && $row[$col] !== '';
            $rv = $has ? (string) $row[$col] : null;
            if ($op === 'null' && $has) {
                return false;
            }
            if ($op === 'notnull' && !$has) {
                return false;
            }
            if ($op === '=' && $rv !== (string) $val) {
                return false;
            }
            if ($op === '!=' && $rv === (string) $val) {
                return false;
            }
        }
        return true;
    }
    private function select($q) {
        $t = $this->table_of($q);
        $rows = $this->tables[$t] ?? [];
        $rows = array_values(array_filter($rows, fn($r) => $this->match_row($r, $this->where_of($q))));
        if (preg_match('/ORDER BY\s+([a-z0-9_]+)\s+(ASC|DESC)/i', (string) $q, $m)) {
            usort($rows, fn($a, $b) => ($m[2] === 'DESC' ? -1 : 1) * strcmp((string) ($a[$m[1]] ?? ''), (string) ($b[$m[1]] ?? '')));
        }
        if (preg_match('/LIMIT\s+(\d+)(?:\s*,\s*(\d+))?/i', (string) $q, $m)) {
            $rows = isset($m[2]) ? array_slice($rows, (int) $m[1], (int) $m[2]) : array_slice($rows, 0, (int) $m[1]);
        }
        return [$t, $rows];
    }
    public function query($q) {
        if (preg_match('/DROP TABLE IF EXISTS\s+`?([a-z0-9_]+)`?/i', (string) $q, $m)) {
            unset($this->tables[$m[1]]);
            return 1;
        }
        return 0;
    }
    public function insert($t, $data) {
        $this->auto[$t] = ($this->auto[$t] ?? 0) + 1;
        $data['id'] = $this->auto[$t];
        $this->tables[$t][] = $data;
        return 1;
    }
    public function update($t, $data, $where) {
        $n = 0;
        if (!isset($this->tables[$t])) {
            return 0;
        }
        foreach ($this->tables[$t] as &$r) {
            $ok = true;
            foreach ($where as $c => $v) {
                if ((string) ($r[$c] ?? '') !== (string) $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $r = array_merge($r, $data);
                $n++;
            }
        }
        unset($r);
        return $n;
    }
    public function delete($t, $where) {
        $before = count($this->tables[$t] ?? []);
        $this->tables[$t] = array_values(array_filter($this->tables[$t] ?? [], function ($r) use ($where) {
            foreach ($where as $c => $v) {
                if ((string) ($r[$c] ?? '') !== (string) $v) {
                    return true;
                }
            }
            return false;
        }));
        return $before - count($this->tables[$t]);
    }
    public function get_results($q) {
        [$t, $rows] = $this->select($q);
        if (preg_match('/SELECT\s+DISTINCT\s+([a-z0-9_]+)/i', (string) $q, $m)) {
            $seen = [];
            foreach ($rows as $r) {
                $seen[(string) ($r[$m[1]] ?? '')] = [$m[1] => $r[$m[1]] ?? null];
            }
            $rows = array_values($seen);
        } elseif (preg_match('/SELECT\s+([a-z0-9_,\s]+)\s+FROM/i', (string) $q, $m) && trim($m[1]) !== '*') {
            $cols = array_map('trim', explode(',', $m[1]));
            $rows = array_map(fn($r) => array_intersect_key($r, array_flip($cols)), $rows);
        }
        return array_map(fn($r) => (object) $r, $rows);
    }
    public function get_row($q) {
        $rows = $this->get_results($q);
        return $rows[0] ?? null;
    }
    public function get_var($q) {
        $q = (string) $q;
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $q, $m)) {
            return in_array($m[1], $GLOBALS['__tables'], true) ? $m[1] : null;
        }
        [$t, $rows] = $this->select($q);
        if (stripos($q, 'COUNT(*)') !== false) {
            return count($rows);
        }
        if (preg_match('/SELECT\s+([a-z0-9_]+)\s+FROM/i', $q, $m) && isset($rows[0])) {
            return $rows[0]->{$m[1]} ?? null;
        }
        return null;
    }
    public function get_col($q) {
        $out = [];
        foreach ($this->get_results($q) as $r) {
            $out[] = reset((array) $r);
        }
        return $out;
    }
}
$GLOBALS['wpdb'] = new FakeWPDB();

function sanitize_hex_color($c) {
    return preg_match("/^#([0-9a-f]{3}){1,2}$/i", (string) $c) ? $c : null;
}

function _n($single, $plural, $n, $d = null) {
    return $n == 1 ? $single : $plural;
}

// ---- term meta / uploads / misc (S20) ----
$GLOBALS['__termmeta'] = [];
function get_term_meta($id, $key = '', $single = false) {
    $m = $GLOBALS['__termmeta'][(int) $id] ?? [];
    if ($key === '') {
        return $m;
    }
    return array_key_exists($key, $m) ? ($single ? $m[$key] : [$m[$key]]) : ($single ? '' : []);
}
function update_term_meta($id, $key, $value) {
    $GLOBALS['__termmeta'][(int) $id][$key] = wp_unslash($value);
    return true;
}
function delete_term_meta($id, $key) {
    unset($GLOBALS['__termmeta'][(int) $id][$key]);
    return true;
}
function wp_remote_request($url, $args = []) {
    $GLOBALS['__requests'][] = [($args['method'] ?? 'GET'), $url];
    $q = &$GLOBALS['__http_queue'];
    return empty($q) ? new WP_Error('no mocked response') : array_shift($q);
}
function user_trailingslashit($s, $type = '') {
    // Like WordPress: follows the permalink structure's trailing slash.
    $slash = str_ends_with((string) get_option('permalink_structure', ''), '/');
    return $slash ? trailingslashit($s) : rtrim((string) $s, '/');
}
function unload_textdomain($domain, $reloadable = false) {
    return true;
}
function get_taxonomies($args = []) {
    return ['category' => 'category', 'post_tag' => 'post_tag'];
}
function esc_url_raw($u) {
    return filter_var((string) $u, FILTER_SANITIZE_URL);
}
function trailingslashit($s) {
    return rtrim((string) $s, '/\\') . '/';
}
function wp_mkdir_p($p) {
    return is_dir($p) || @mkdir($p, 0777, true);
}
function wp_upload_dir($time = null, $create = true) {
    $base = $GLOBALS['__uploads'] ?? sys_get_temp_dir() . '/tqk-uploads';
    return ['basedir' => $base, 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false];
}
if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($id = 0, $context = 'display') {
        return admin_url('post.php?post=' . (int) $id . '&action=edit');
    }
}
if (!function_exists('wp_date')) {
    function wp_date($format, $ts = null) {
        return date($format, $ts ?? time());
    }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12, $special = true, $extra = false) {
        return substr(bin2hex(random_bytes($len)), 0, $len);
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '') {
        return $url . (str_contains((string) $url, '?') ? '&' : '?') . http_build_query((array) $args);
    }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return home_url('/wp-json/' . ltrim((string) $path, '/'));
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}
if (!function_exists('__return_false')) {
    function __return_false() {
        return false;
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}
