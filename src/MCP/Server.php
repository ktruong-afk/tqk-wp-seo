<?php
namespace TQK_WPSEO\MCP;

defined('ABSPATH') || exit;

/**
 * S6 — MCP-style tool endpoint for local AI agents.
 * REST: tqk-wpseo/v1/tools (GET) + tqk-wpseo/v1/call (POST {tool, params}).
 * Auth: X-TQK-Token header must match the stored token. Writes are logged.
 */
class Server {

    public const TOKEN_OPTION = 'tqk_wpseo_mcp_token';
    public const LOG_OPTION = 'tqk_wpseo_mcp_log';

    public static function hooks(): void {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void {
        register_rest_route('tqk-wpseo/v1', '/tools', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_tools'],
            'permission_callback' => [self::class, 'check'],
        ]);
        register_rest_route('tqk-wpseo/v1', '/call', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_call'],
            'permission_callback' => [self::class, 'check'],
        ]);
    }

    public static function token(): string {
        $t = get_option(self::TOKEN_OPTION, '');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(24));
            update_option(self::TOKEN_OPTION, $t);
        }
        return $t;
    }

    public static function check($request): bool {
        if (!\TQK_WPSEO\Core\Modules::is_active('mcp')) {
            return false;
        }
        $sent = '';
        if (is_array($request) && isset($request['headers'])) {
            $sent = (string) ($request['headers']['x-tqk-token'] ?? '');
        } elseif (is_object($request) && method_exists($request, 'get_header')) {
            $sent = (string) $request->get_header('x-tqk-token');
        }
        return $sent !== '' && hash_equals(self::token(), $sent);
    }

    /** @return string[] Tool names. */
    public static function tools(): array {
        return [
            'seo_get_page', 'seo_analyze_page', 'seo_get_schema', 'seo_missing_alt',
            'seo_orphan_pages', 'seo_get_404', 'seo_scan_status',
            'seo_update_meta', 'seo_create_redirect',
        ];
    }

    public static function rest_tools($request) {
        return ['tools' => self::tools()];
    }

    public static function rest_call($request) {
        $params = is_array($request) ? ($request['params'] ?? []) : [];
        $tool = is_array($request) ? ($request['tool'] ?? '') : '';
        if (is_object($request) && method_exists($request, 'get_params')) {
            $all = $request->get_params();
            $tool = $all['tool'] ?? '';
            $params = $all['params'] ?? [];
        }
        return self::call((string) $tool, is_array($params) ? $params : []);
    }

    /** @return array Result envelope. */
    public static function call(string $tool, array $params): array {
        return match ($tool) {
            'seo_get_page' => self::t_get_page((int) ($params['post_id'] ?? 0)),
            'seo_analyze_page' => self::t_analyze((int) ($params['post_id'] ?? 0)),
            'seo_get_schema' => self::t_schema((int) ($params['post_id'] ?? 0)),
            'seo_missing_alt' => self::t_missing_alt((int) ($params['limit'] ?? 20)),
            'seo_orphan_pages' => self::t_orphans(),
            'seo_get_404' => self::t_404((int) ($params['limit'] ?? 20)),
            'seo_scan_status' => self::t_scan_status(),
            'seo_update_meta' => self::t_update_meta($params),
            'seo_create_redirect' => self::t_create_redirect($params),
            default => ['ok' => false, 'error' => 'unknown tool'],
        };
    }

    private static function t_get_page(int $id): array {
        $post = get_post($id);
        if (!$post) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $data = \TQK_WPSEO\Core\Data::for(['type' => 'singular', 'object_id' => $id, 'post_type' => $post->post_type ?? 'post']);
        return ['ok' => true, 'post' => ['id' => $id, 'title' => $post->post_title, 'type' => $post->post_type, 'status' => $post->post_status], 'seo' => $data];
    }

    private static function t_analyze(int $id): array {
        if (!get_post($id)) {
            return ['ok' => false, 'error' => 'not found'];
        }
        return ['ok' => true, 'analysis' => \TQK_WPSEO\Analyzer\Analyzer::analyze($id)];
    }

    private static function t_schema(int $id): array {
        $post = get_post($id);
        if (!$post) {
            return ['ok' => false, 'error' => 'not found'];
        }
        $ctx = ['type' => 'singular', 'object_id' => $id, 'post_type' => $post->post_type ?? 'post'];
        $graph = \TQK_WPSEO\Schema\Schema::build($ctx, \TQK_WPSEO\Core\Data::for($ctx));
        return ['ok' => true, 'graph' => $graph->nodes()];
    }

    private static function t_missing_alt(int $limit): array {
        $out = [];
        foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => ['publish'], 'numberposts' => 50]) as $p) {
            $stored = \TQK_WPSEO\Analyzer\Analyzer::stored((int) $p->ID);
            $issues = $stored ? array_column($stored['findings'] ?? [], 'id') : [];
            if (in_array('missing_alt', $issues, true)) {
                $out[] = ['id' => (int) $p->ID, 'title' => $p->post_title];
            }
            if (count($out) >= max(1, $limit)) {
                break;
            }
        }
        return ['ok' => true, 'pages' => $out];
    }

    private static function t_orphans(): array {
        $out = [];
        foreach (\TQK_WPSEO\Links\Engine::orphan_ids() as $id) {
            $out[] = ['id' => $id, 'title' => get_the_title($id)];
        }
        return ['ok' => true, 'orphans' => $out];
    }

    private static function t_404(int $limit): array {
        $out = [];
        foreach (\TQK_WPSEO\Redirect\Monitor404::all(max(1, $limit)) as $r) {
            $out[] = ['url' => $r->url, 'hits' => (int) $r->hits, 'last_hit' => $r->last_hit ?? ''];
        }
        return ['ok' => true, 'urls' => $out];
    }

    private static function t_scan_status(): array {
        return ['ok' => true, 'job' => get_option(\TQK_WPSEO\Scanner\Scanner::JOB_OPTION, []), 'summary' => \TQK_WPSEO\Scanner\Scanner::summary()];
    }

    private static function t_update_meta(array $params): array {
        $id = (int) ($params['post_id'] ?? 0);
        $fields = is_array($params['fields'] ?? null) ? $params['fields'] : [];
        if (!$id || !get_post($id)) {
            return ['ok' => false, 'error' => 'bad post'];
        }
        $allowed = \TQK_WPSEO\Core\Activator::meta_keys();
        $saved = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $v = sanitize_text_field((string) $v);
            $v === '' ? delete_post_meta($id, $k) : update_post_meta($id, $k, $v);
            $saved[] = $k;
        }
        \TQK_WPSEO\Core\Data::invalidate_for_post($id);
        \TQK_WPSEO\Core\Queue::push($id);
        self::log_write('seo_update_meta', ['post_id' => $id, 'fields' => $saved]);
        return ['ok' => true, 'saved' => $saved];
    }

    private static function t_create_redirect(array $params): array {
        $id = \TQK_WPSEO\Redirect\Redirects::add(
            (string) ($params['source'] ?? ''),
            (string) ($params['target'] ?? ''),
            (int) ($params['type'] ?? 301)
        );
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'invalid redirect'];
        }
        self::log_write('seo_create_redirect', ['id' => $id]);
        return ['ok' => true, 'id' => $id];
    }

    private static function log_write(string $tool, array $detail): void {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, ['tool' => $tool, 'at' => gmdate('c')] + $detail);
        update_option(self::LOG_OPTION, array_slice($log, 0, 50));
    }
}
