<?php
namespace TQK_WPSEO\MCP;

defined('ABSPATH') || exit;

/**
 * Model Context Protocol endpoint (Streamable HTTP transport, JSON replies).
 *
 *   POST /wp-json/tqk-wpseo/v1/mcp   JSON-RPC 2.0: initialize, tools/list,
 *                                    tools/call, prompts/list, prompts/get, ping
 *   Auth: header "X-TQK-Token: <token>" or "Authorization: Bearer <token>"
 *         (the same token as the older REST tools, Tools → MCP access).
 *
 * Flow it is built for: the person scans a page in the editor and presses
 * "Send to AI"; the agent lists the inbox, reads the report with screenshots
 * of the 4 screens, advises along the action plan, and on approval writes
 * the SEO fields back (same whitelist and sanitising as the JSON import).
 */
class Protocol {

    public const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    /** Screenshot tiles: long pages are cut so every part stays readable. */
    public const TILE_H = 2000;
    public const MAX_TILES = 3;

    public static function hooks(): void {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void {
        register_rest_route('tqk-wpseo/v1', '/mcp', [
            ['methods' => 'POST', 'callback' => [self::class, 'rest'], 'permission_callback' => [self::class, 'auth']],
            // No server-to-client stream: GET is refused as the spec allows.
            ['methods' => 'GET', 'callback' => static fn() => new \WP_REST_Response(null, 405), 'permission_callback' => '__return_true'],
        ]);
    }

    public static function auth($request): bool {
        if (!\TQK_WPSEO\Core\Modules::is_active('mcp')) {
            return false;
        }
        $sent = '';
        if (is_object($request) && method_exists($request, 'get_header')) {
            $sent = (string) $request->get_header('x-tqk-token');
            if ($sent === '' && preg_match('/^Bearer\s+(\S+)$/i', (string) $request->get_header('authorization'), $m)) {
                $sent = $m[1];
            }
        } elseif (is_array($request)) {
            $h = array_change_key_case((array) ($request['headers'] ?? []));
            $sent = (string) ($h['x-tqk-token'] ?? '');
            if ($sent === '' && preg_match('/^Bearer\s+(\S+)$/i', (string) ($h['authorization'] ?? ''), $m)) {
                $sent = $m[1];
            }
        }
        return $sent !== '' && hash_equals(Server::token(), $sent);
    }

    public static function rest($request) {
        $body = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : (array) $request;
        $out = self::handle(is_array($body) ? $body : []);
        return $out === null ? new \WP_REST_Response(null, 202) : new \WP_REST_Response($out, 200);
    }

    /**
     * One message or a batch. @return array|null Response(s); null when only
     * notifications came in (HTTP 202, no body).
     */
    public static function handle(array $msg): ?array {
        if ($msg !== [] && array_is_list($msg)) {
            $out = array_values(array_filter(array_map(static fn($m) => is_array($m) ? self::one($m) : self::error(null, -32600, 'Invalid Request'), $msg)));
            return $out === [] ? null : $out;
        }
        return self::one($msg);
    }

    private static function error($id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function ok($id, array $result): array {
        // An empty result must be {} in JSON, not [].
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result];
    }

    private static function one(array $m): ?array {
        $id = $m['id'] ?? null;
        $method = (string) ($m['method'] ?? '');
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];
        if (($m['jsonrpc'] ?? '') !== '2.0' || $method === '') {
            return self::error($id, -32600, 'Invalid Request');
        }
        if (!array_key_exists('id', $m)) {
            return null; // notification (e.g. notifications/initialized)
        }
        switch ($method) {
            case 'initialize':
                $asked = (string) ($params['protocolVersion'] ?? '');
                return self::ok($id, [
                    'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
                    'capabilities' => ['tools' => ['listChanged' => false], 'prompts' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'TQK WP SEO', 'version' => defined('TQK_WPSEO_VERSION') ? TQK_WPSEO_VERSION : '0'],
                    'instructions' => self::instructions(),
                ]);
            case 'ping':
                return self::ok($id, []);
            case 'tools/list':
                return self::ok($id, ['tools' => self::tools()]);
            case 'tools/call':
                return self::ok($id, self::call((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []));
            case 'prompts/list':
                return self::ok($id, ['prompts' => self::prompts()]);
            case 'prompts/get':
                $p = self::prompt((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []);
                return $p === null ? self::error($id, -32602, 'Unknown prompt') : self::ok($id, $p);
            default:
                return self::error($id, -32601, 'Method not found');
        }
    }

    public static function instructions(): string {
        return 'SEO data of the WordPress site ' . home_url('/') . ' (plugin TQK WP SEO). '
            . 'The owner sends pages for review from the post editor: start with list_pushed_pages, then get_page_report '
            . '(measurements at 4 screen sizes, action plan, security check, screenshots). Advise in the language of the site, '
            . 'following the plan priorities. Send your advice and proposed values with submit_suggestions: the owner reviews '
            . 'and approves them in WordPress (nothing is written without approval). Then mark_page_done.';
    }

    /** @return list<array> Tool definitions with JSON Schemas. */
    public static function tools(): array {
        $id = ['post_id' => ['type' => 'integer', 'description' => 'WordPress post / page ID']];
        $obj = static fn(array $props, array $req = []) => ['type' => 'object', 'properties' => (object) $props] + ($req ? ['required' => $req] : []);
        return [
            ['name' => 'list_pushed_pages', 'description' => 'Pages the owner sent for AI review, newest first, with the owner\'s note, the plan counts (fix now / should fix / nice to have), the security verdict and the available screenshots.', 'inputSchema' => $obj([])],
            ['name' => 'get_page_report', 'description' => 'Full scan report of a page: SEO facts (title, description, H1/H2 counts, social tags, schema), scores at desktop 1440 / laptop 1280 / tablet 768 / mobile 375, typography and layout findings with CSS fixes, section-by-section image checks, the prioritised action plan, the server checklist, the security check (scripts, cloaking), and full-page screenshots of each screen (long pages come in several parts).', 'inputSchema' => $obj($id + [
                'screenshots' => ['type' => 'boolean', 'description' => 'Include screenshots (default true)'],
                'screens' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => \TQK_WPSEO\Analyzer\LiveAudit::EDITOR_SHOT_KEYS], 'description' => 'Which screens (default all 4)'],
            ], ['post_id'])],
            ['name' => 'get_page_content', 'description' => 'The page as plain text (no tags, no shortcodes), first 1000 words, with its current SEO values. Read it before suggesting a title or description.', 'inputSchema' => $obj($id, ['post_id'])],
            ['name' => 'submit_suggestions', 'description' => 'Send your review of a page to the owner for approval: "advice" is your plan in plain words (what to fix, why, on which screen, CSS if any), "fields" the SEO values you propose (keys: focus_keyword, secondary_keywords, title, description, canonical, robots, og_title, og_description, twitter_title, twitter_description, schema_type, schema form keys like faq_items, howto_steps, ingredients). Nothing is written: the owner reviews and applies it in the WordPress editor.', 'inputSchema' => $obj($id + [
                'advice' => ['type' => 'string', 'description' => 'Your plan for this page, in the language of the site'],
                'fields' => ['type' => 'object', 'description' => 'Proposed values, key => value (optional)'],
                'from' => ['type' => 'string', 'description' => 'Your name / model, shown to the owner (optional)'],
            ], ['post_id', 'advice'])],
            ['name' => 'apply_seo_fields', 'description' => 'Write SEO fields of a page directly — only if the owner allowed it in the plugin settings; otherwise the values are kept as a suggestion for approval (same as submit_suggestions). Same keys as submit_suggestions.', 'inputSchema' => $obj($id + ['fields' => ['type' => 'object', 'description' => 'key => value']], ['post_id', 'fields'])],
            ['name' => 'mark_page_done', 'description' => 'Remove a page from the review list once handled.', 'inputSchema' => $obj($id, ['post_id'])],
            ['name' => 'get_schema', 'description' => 'The JSON-LD graph the plugin outputs for a page.', 'inputSchema' => $obj($id, ['post_id'])],
            ['name' => 'list_orphan_pages', 'description' => 'Published pages with no internal link pointing to them.', 'inputSchema' => $obj([])],
            ['name' => 'list_404', 'description' => 'Most hit 404 URLs.', 'inputSchema' => $obj(['limit' => ['type' => 'integer']])],
            ['name' => 'create_redirect', 'description' => 'Create a 301/302 redirect. Ask the owner before calling.', 'inputSchema' => $obj(['source' => ['type' => 'string'], 'target' => ['type' => 'string'], 'type' => ['type' => 'integer', 'enum' => [301, 302, 307, 410]]], ['source', 'target'])],
        ];
    }

    private static function text($data, bool $error = false): array {
        return ['content' => [['type' => 'text', 'text' => is_string($data) ? $data : (string) wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]], 'isError' => $error];
    }

    /** @return array CallToolResult */
    public static function call(string $name, array $a): array {
        $pid = (int) ($a['post_id'] ?? 0);
        if (in_array($name, ['get_page_report', 'get_page_content', 'apply_seo_fields', 'submit_suggestions', 'mark_page_done', 'get_schema'], true) && !get_post($pid)) {
            return self::text('No post with ID ' . $pid, true);
        }
        switch ($name) {
            case 'list_pushed_pages':
                $list = Reports::inbox_summary();
                return self::text($list === [] ? 'No page has been sent yet. Ask the owner to scan a page in the editor and press "Send to AI".' : $list);
            case 'get_page_report':
                return self::report($pid, ($a['screenshots'] ?? true) !== false, is_array($a['screens'] ?? null) ? $a['screens'] : \TQK_WPSEO\Analyzer\LiveAudit::EDITOR_SHOT_KEYS);
            case 'get_page_content':
                return self::text(\TQK_WPSEO\Admin\AiJson::bundle($pid, 'seo'));
            case 'submit_suggestions':
                return self::suggest($pid, (string) ($a['advice'] ?? ''), is_array($a['fields'] ?? null) ? $a['fields'] : [], (string) ($a['from'] ?? ''));
            case 'apply_seo_fields':
                // Approval first, unless the owner allowed direct writes.
                if (empty(\TQK_WPSEO\Admin\Settings::get('mcp_direct_write', false))) {
                    return self::suggest($pid, (string) ($a['advice'] ?? ''), is_array($a['fields'] ?? null) ? $a['fields'] : [], (string) ($a['from'] ?? ''));
                }
                return self::apply($pid, is_array($a['fields'] ?? null) ? $a['fields'] : []);
            case 'mark_page_done':
                return self::text(Reports::done($pid) ? 'Removed from the review list.' : 'It was not in the list.');
            case 'get_schema':
                return self::text(Server::call('seo_get_schema', ['post_id' => $pid]));
            case 'list_orphan_pages':
                return self::text(Server::call('seo_orphan_pages', []));
            case 'list_404':
                return self::text(Server::call('seo_get_404', ['limit' => (int) ($a['limit'] ?? 20)]));
            case 'create_redirect':
                $r = Server::call('seo_create_redirect', $a);
                return self::text($r, empty($r['ok']));
        }
        return self::text('Unknown tool: ' . $name, true);
    }

    private static function report(int $id, bool $pics, array $screens): array {
        $r = Reports::get($id);
        if ($r === null) {
            return self::text('This page has not been scanned yet. Ask the owner to press "Scan the page" in the editor.', true);
        }
        $r = ['post_id' => $id, 'title' => (string) get_the_title($id)] + $r;
        $r['scanned_at'] = gmdate('c', (int) ($r['at'] ?? 0));
        unset($r['at']);
        foreach (Reports::inbox() as $e) {
            if ((int) $e['id'] === $id && (string) ($e['note'] ?? '') !== '') {
                $r['owner_note'] = (string) $e['note'];
            }
        }
        $content = [['type' => 'text', 'text' => (string) wp_json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]];
        if ($pics) {
            // Snapshots of whichever scan made the report (editor: 4 screens,
            // site audit: desktop + mobile), all stored inside uploads.
            $shots = (array) ($r['shots'] ?? []);
            foreach (array_intersect(\TQK_WPSEO\Analyzer\LiveAudit::EDITOR_SHOT_KEYS, $screens) as $key) {
                $path = isset($shots[$key]) ? \TQK_WPSEO\Core\Vault::local((string) $shots[$key]) : '';
                if ($path === '') {
                    $path = \TQK_WPSEO\Analyzer\LiveAudit::editor_shot_path($id, $key);
                }
                if ($path === '' || !preg_match('/\.jpe?g$/i', $path)) {
                    continue;
                }
                $tiles = self::tiles($path);
                foreach ($tiles as $i => $b64) {
                    $content[] = ['type' => 'text', 'text' => sprintf('Screenshot %s%s', $key, count($tiles) > 1 ? ' — part ' . ($i + 1) . '/' . count($tiles) . ' (top to bottom)' : '')];
                    $content[] = ['type' => 'image', 'data' => $b64, 'mimeType' => 'image/jpeg'];
                }
            }
        }
        return ['content' => $content, 'isError' => false];
    }

    /**
     * A full-page screenshot as up to MAX_TILES readable parts (base64 JPEG).
     * Without GD the file is sent as it is.
     *
     * @return string[]
     */
    public static function tiles(string $path): array {
        $bin = (string) @file_get_contents($path);
        if ($bin === '') {
            return [];
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecrop')) {
            return [base64_encode($bin)];
        }
        $im = @imagecreatefromstring($bin);
        if (!$im) {
            return [base64_encode($bin)];
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($h <= self::TILE_H) {
            imagedestroy($im);
            return [base64_encode($bin)];
        }
        // Too long even for MAX_TILES parts: scale down first.
        if ($h > self::TILE_H * self::MAX_TILES) {
            $scale = (self::TILE_H * self::MAX_TILES) / $h;
            $small = imagescale($im, max(1, (int) round($w * $scale)), -1);
            imagedestroy($im);
            if (!$small) {
                return [base64_encode($bin)];
            }
            $im = $small;
            $w = imagesx($im);
            $h = imagesy($im);
        }
        $out = [];
        for ($y = 0; $y < $h && count($out) < self::MAX_TILES; $y += self::TILE_H) {
            $part = imagecrop($im, ['x' => 0, 'y' => $y, 'width' => $w, 'height' => min(self::TILE_H, $h - $y)]);
            if (!$part) {
                continue;
            }
            ob_start();
            imagejpeg($part, null, 72);
            $out[] = base64_encode((string) ob_get_clean());
            imagedestroy($part);
        }
        imagedestroy($im);
        return $out;
    }

    /** Keeps a review + proposed values for the owner's approval. */
    private static function suggest(int $id, string $advice, array $fields, string $from): array {
        if (trim($advice) === '' && $fields === []) {
            return self::text('Nothing to send: give "advice" and/or "fields".', true);
        }
        $s = Reports::suggest($id, $advice, $fields, $from);
        return self::text([
            'status' => 'waiting for the owner\'s approval — nothing was written to the page',
            'fields_proposed' => array_values((array) $s['labels']),
            'refused' => $s['refused'],
            'owner_reviews_it_here' => (string) admin_url('post.php?post=' . $id . '&action=edit'),
        ]);
    }

    /** Writes fields through the same whitelist as the editor's JSON import. */
    private static function apply(int $id, array $fields): array {
        $parsed = \TQK_WPSEO\Admin\AiJson::parse((string) wp_json_encode(['tqk_seo' => 1, 'post_id' => $id, 'fields' => $fields]), $id);
        if (isset($parsed['error'])) {
            return self::text($parsed['error'], true);
        }
        $saved = [];
        foreach ((array) $parsed['fields'] as $meta => $val) {
            $val === '' ? delete_post_meta($id, $meta) : update_post_meta($id, $meta, wp_slash((string) $val));
            $saved[] = (string) ($parsed['labels'][$meta] ?? $meta);
        }
        if ($saved !== []) {
            \TQK_WPSEO\Core\Data::invalidate_for_post($id);
            \TQK_WPSEO\Core\Queue::push($id);
            $log = get_option(Server::LOG_OPTION, []);
            $log = is_array($log) ? $log : [];
            array_unshift($log, ['tool' => 'mcp:apply_seo_fields', 'at' => gmdate('c'), 'post_id' => $id, 'fields' => array_keys((array) $parsed['fields'])]);
            update_option(Server::LOG_OPTION, array_slice($log, 0, 50));
        }
        return self::text([
            'saved' => $saved,
            'refused' => array_merge((array) $parsed['dropped'], (array) $parsed['notes']),
            'edit_link' => (string) admin_url('post.php?post=' . $id . '&action=edit'),
        ], $saved === [] && $fields !== []);
    }

    public static function prompts(): array {
        return [[
            'name' => 'review_page',
            'description' => 'Review a page sent from TQK SEO: read its report and screenshots, then give a prioritised plan to make it better.',
            'arguments' => [['name' => 'post_id', 'description' => 'Page ID (see list_pushed_pages)', 'required' => true]],
        ]];
    }

    public static function prompt(string $name, array $args): ?array {
        if ($name !== 'review_page') {
            return null;
        }
        $id = (int) ($args['post_id'] ?? 0);
        $text = "Review page {$id} of " . home_url('/') . " for SEO and for visitors.\n"
            . "1. Call get_page_report with post_id {$id} and look at every screenshot (desktop, laptop, tablet, mobile).\n"
            . "2. Follow the action plan order: fix now → should fix → nice to have. For each point say what is wrong, on which screen, and exactly how to fix it (field value, CSS, or where in the builder).\n"
            . "3. Look at the screenshots for what numbers cannot show: hierarchy, spacing, readability, what catches the eye first, whether the main call to action is visible on mobile without scrolling.\n"
            . "4. Images: use the per-screen findings; say which files are too large or blurry and what size each screen needs.\n"
            . "5. Security: if the verdict is not clean, explain the risk first.\n"
            . "6. Propose new title, description and keywords if needed (read get_page_content first).\n"
            . "7. Send everything with submit_suggestions: \"advice\" = your plan in plain words, \"fields\" = the proposed values. The owner approves it in WordPress; you do not write to the page. Then call mark_page_done.\n"
            . "Answer in the language of the site, short and concrete. Respect the owner's note if there is one.";
        return ['description' => 'Review of page ' . $id, 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];
    }
}
