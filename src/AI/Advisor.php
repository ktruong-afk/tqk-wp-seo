<?php
namespace TQK_WPSEO\AI;

defined('ABSPATH') || exit;

use TQK_WPSEO\Analyzer\LiveAudit;
use TQK_WPSEO\Analyzer\Text;
use TQK_WPSEO\Schema\Fields;

/**
 * AI Advisor: turns Live Audit findings into fix suggestions with Gemini
 * Flash, one small task at a time, paced for the free tier.
 *
 * Why it is shaped like this:
 *   - Pages come from the last Live Audit, only those with something to fix.
 *     No findings, no request.
 *   - GROUP pages per request (3 by default): fewer requests beats waiting.
 *   - Each page's input is hashed; an unchanged page reuses its cached
 *     suggestion instead of spending quota again.
 *   - Per-page status like Live Audit (pending / done / failed / skipped),
 *     so a run that hits the daily quota just resumes the next day.
 *   - Suggestions are stored, never applied: the person ticks and applies.
 */
class Advisor {

    public const RUN = 'tqk_wpseo_ai_run';
    public const META = '_tqk_wpseo_ai_suggest';
    public const GROUP = 3;
    public const PENDING = '.';
    public const DONE = 'd';
    public const FAILED = 'f';

    /** @return int[] Post IDs of the last Live Audit that still have issues. */
    public static function candidates(): array {
        $run = LiveAudit::run();
        $ids = [];
        foreach ((array) ($run['ids'] ?? []) as $i => $id) {
            $id = (int) $id;
            if ($id <= 0 || (($run['st'] ?? '')[$i] ?? '') !== LiveAudit::DONE) {
                continue;
            }
            $rec = LiveAudit::record($id, (string) $run['id']);
            if ($rec !== null && self::has_issues($rec)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public static function has_issues(array $rec): bool {
        foreach ((array) ($rec['seo']['findings'] ?? []) as $f) {
            if (($f['sev'] ?? '') !== 'pass') {
                return true;
            }
        }
        return (array) ($rec['fields'] ?? []) !== [];
    }

    /**
     * Compact, fix-oriented context for one page. No raw HTML: headings, the
     * start of the text, images without ALT, and only the checks that failed.
     */
    public static function context(int $id): array {
        $post = get_post($id);
        if (!$post) {
            return [];
        }
        $content = (string) ($post->post_content ?? '');
        $plain = Text::plain($content);
        $words = preg_split('/\s+/u', trim($plain)) ?: [];
        $outline = [];
        foreach (array_slice(Text::headings($content), 0, 15) as $h) {
            $outline[] = 'H' . (int) $h[0] . ': ' . mb_substr(trim(strip_tags((string) $h[1])), 0, 90);
        }
        $no_alt = [];
        foreach (Text::images($content) as $attrs) {
            $src = Text::attr($attrs, 'src');
            if ($src !== '' && trim(Text::attr($attrs, 'alt')) === '' && count($no_alt) < 8) {
                $no_alt[] = $src;
            }
        }
        $run = LiveAudit::run();
        $rec = LiveAudit::record($id, (string) ($run['id'] ?? '')) ?? [];
        $problems = [];
        foreach ((array) ($rec['seo']['findings'] ?? []) as $f) {
            if (($f['sev'] ?? '') !== 'pass') {
                $problems[] = (string) $f['msg'];
            }
        }
        foreach ((array) ($rec['fields'] ?? []) as $m) {
            $problems[] = (string) $m;
        }
        $type = \TQK_WPSEO\Schema\Schema::type_for($post);
        $fields = [];
        foreach (Fields::for($type) as $name => $spec) {
            if (trim((string) get_post_meta($id, Fields::key($name), true)) === '') {
                $fields[$name] = trim((string) $spec['label'] . '. ' . (string) ($spec['help'] ?? ''), ' .');
            }
        }
        return [
            'post_id' => $id,
            'post_type' => (string) $post->post_type,
            'title' => (string) $post->post_title,
            'seo_title' => (string) get_post_meta($id, '_tqk_wpseo_title', true),
            'seo_description' => (string) get_post_meta($id, '_tqk_wpseo_description', true),
            'focus_keyword' => (string) get_post_meta($id, '_tqk_wpseo_focus_keyword', true),
            'schema_type' => $type,
            'empty_schema_fields' => $fields,
            'outline' => $outline,
            'text_start' => implode(' ', array_slice($words, 0, 300)),
            'images_without_alt' => $no_alt,
            'problems' => array_slice($problems, 0, 15),
        ];
    }

    /** Cache key of a page's input: same input → same suggestion, no new call. */
    public static function hash(array $ctx): string {
        return md5((string) wp_json_encode($ctx));
    }

    /** Gemini responseSchema: one object per page, all strings bounded by the prompt. */
    public static function schema(): array {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'pages' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'post_id' => ['type' => 'INTEGER'],
                            'seo_title' => ['type' => 'STRING'],
                            'seo_description' => ['type' => 'STRING'],
                            'focus_keyword' => ['type' => 'STRING'],
                            'image_alts' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['src' => ['type' => 'STRING'], 'alt' => ['type' => 'STRING']], 'required' => ['src', 'alt']]],
                            'schema_fields' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['field' => ['type' => 'STRING'], 'value' => ['type' => 'STRING']], 'required' => ['field', 'value']]],
                            'notes' => ['type' => 'STRING'],
                        ],
                        'required' => ['post_id', 'seo_title', 'seo_description', 'focus_keyword'],
                    ],
                ],
            ],
            'required' => ['pages'],
        ];
    }

    public static function prompt(array $contexts): string {
        return Suggester::biz_context()
            . "You are an SEO editor for a WordPress site. For EACH page below, propose fixes for its listed problems.\n"
            . "Rules:\n"
            . "- Write in the same language as the page text.\n"
            . "- seo_title: max 60 characters, contains the focus keyword naturally, no clickbait.\n"
            . "- seo_description: 120-160 characters, contains the focus keyword, describes what the page offers.\n"
            . "- focus_keyword: the main search phrase this page can realistically rank for (2-5 words). Keep the existing one if it fits.\n"
            . "- image_alts: one entry per src in images_without_alt, describing the image from its file name and the text around it; empty list if none.\n"
            . "- schema_fields: only for names in empty_schema_fields and only with facts present in the text; never invent prices, dates or addresses. Empty list if the text has no facts for it.\n"
            . "- notes: one short sentence on the most important remaining fix a human must do.\n"
            . "Return post_id exactly as given.\n\n"
            . "PAGES (JSON):\n" . wp_json_encode($contexts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rule checks on a suggestion. Code scores, the model only suggests:
     * these flags are shown next to each value before anything is applied.
     *
     * @return array<string,string> field => problem ('' when fine)
     */
    public static function checks(array $s): array {
        $kw = mb_strtolower(trim((string) ($s['focus_keyword'] ?? '')));
        $t = (string) ($s['seo_title'] ?? '');
        $d = (string) ($s['seo_description'] ?? '');
        $out = ['seo_title' => '', 'seo_description' => ''];
        if ($t === '' || mb_strlen($t) > 60) {
            $out['seo_title'] = sprintf(__('%d characters (max 60)', 'tqk-wpseo'), mb_strlen($t));
        } elseif ($kw !== '' && mb_stripos($t, $kw) === false) {
            $out['seo_title'] = __('focus keyword not in title', 'tqk-wpseo');
        }
        $dl = mb_strlen($d);
        if ($dl < 120 || $dl > 160) {
            $out['seo_description'] = sprintf(__('%d characters (120-160)', 'tqk-wpseo'), $dl);
        } elseif ($kw !== '' && mb_stripos($d, $kw) === false) {
            $out['seo_description'] = __('focus keyword not in description', 'tqk-wpseo');
        }
        return $out;
    }

    /** Keeps only known fields, bounded, and only schema fields that exist for the page. */
    public static function clean(array $s, array $ctx): array {
        $str = static fn($v, int $max) => is_scalar($v) ? mb_substr(sanitize_text_field((string) $v), 0, $max) : '';
        $allowed_src = (array) ($ctx['images_without_alt'] ?? []);
        $alts = [];
        foreach (array_slice((array) ($s['image_alts'] ?? []), 0, 8) as $a) {
            $src = is_array($a) ? (string) ($a['src'] ?? '') : '';
            if (in_array($src, $allowed_src, true)) {
                $alts[] = ['src' => $src, 'alt' => $str($a['alt'] ?? '', 150)];
            }
        }
        $fields = [];
        foreach (array_slice((array) ($s['schema_fields'] ?? []), 0, 12) as $f) {
            $name = is_array($f) ? (string) ($f['field'] ?? '') : '';
            if (isset($ctx['empty_schema_fields'][$name]) && is_scalar($f['value'] ?? null)) {
                $fields[] = ['field' => $name, 'value' => mb_substr(sanitize_textarea_field((string) $f['value']), 0, 4000)];
            }
        }
        $out = [
            'seo_title' => $str($s['seo_title'] ?? '', 120),
            'seo_description' => $str($s['seo_description'] ?? '', 300),
            'focus_keyword' => $str($s['focus_keyword'] ?? '', 80),
            'image_alts' => $alts,
            'schema_fields' => $fields,
            'notes' => $str($s['notes'] ?? '', 300),
        ];
        $out['checks'] = self::checks($out);
        return $out;
    }

    // ---------- run + per-page tracking ----------

    public static function run(): array {
        $r = get_option(self::RUN, []);
        return is_array($r) ? $r : [];
    }

    private static function save_run(array $run): void {
        $run['updated'] = time();
        update_option(self::RUN, $run, false);
    }

    public static function start(): array {
        $ids = self::candidates();
        $run = [
            'id' => strtolower(wp_generate_password(12, false, false)),
            'ids' => $ids,
            'st' => str_repeat(self::PENDING, count($ids)),
            'status' => $ids === [] ? 'done' : 'running',
            'started' => time(),
            'calls' => 0,
            'cached' => 0,
            'error' => '',
        ];
        self::save_run($run);
        return $run;
    }

    public static function state(array $run): array {
        $st = (string) ($run['st'] ?? '');
        $rate = RateLimiter::check();
        return [
            'id' => (string) ($run['id'] ?? ''),
            'total' => strlen($st),
            'done' => substr_count($st, self::DONE),
            'failed' => substr_count($st, self::FAILED),
            'pending' => substr_count($st, self::PENDING),
            'status' => (string) ($run['status'] ?? ''),
            'calls' => (int) ($run['calls'] ?? 0),
            'cached' => (int) ($run['cached'] ?? 0),
            'error' => (string) ($run['error'] ?? ''),
            'used_today' => $rate['used'],
            'limit_today' => $rate['limit'],
        ];
    }

    private static function mark(array $run, int $i, string $s): array {
        $st = (string) $run['st'];
        $st[$i] = $s;
        $run['st'] = $st;
        if (strpos($st, self::PENDING) === false) {
            $run['status'] = 'done';
        }
        return $run;
    }

    public static function retry_failed(array $run): array {
        $run['st'] = str_replace(self::FAILED, self::PENDING, (string) ($run['st'] ?? ''));
        $run['error'] = '';
        if (strpos($run['st'], self::PENDING) !== false) {
            $run['status'] = 'running';
        }
        return $run;
    }

    public static function suggestion(int $id): ?array {
        $raw = get_post_meta($id, self::META, true);
        $s = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($s) ? $s : null;
    }

    private static function store(int $id, string $hash, array $data, string $model): void {
        update_post_meta($id, self::META, wp_slash((string) wp_json_encode(
            ['hash' => $hash, 'at' => time(), 'model' => $model, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )));
    }

    /**
     * One step of the queue: at most ONE request to Gemini.
     * Cached pages are settled first without any call.
     *
     * @return array{run:array, wait:int, daily:bool, done_ids:int[], fatal:string}
     */
    public static function step(array $run, ?GeminiProvider $gemini = null, string $model = ''): array {
        $out = ['wait' => 0, 'daily' => false, 'done_ids' => [], 'fatal' => ''];
        $ids = (array) ($run['ids'] ?? []);
        $group = [];
        $i = strpos((string) ($run['st'] ?? ''), self::PENDING);
        while ($i !== false && count($group) < self::GROUP) {
            $id = (int) $ids[$i];
            $ctx = self::context($id);
            if ($ctx === []) {
                $run = self::mark($run, $i, self::FAILED);
            } else {
                $hash = self::hash($ctx);
                $cached = self::suggestion($id);
                if ($cached !== null && ($cached['hash'] ?? '') === $hash) {
                    $run = self::mark($run, $i, self::DONE);
                    $run['cached'] = (int) ($run['cached'] ?? 0) + 1;
                    $out['done_ids'][] = $id;
                } else {
                    $group[$i] = $ctx + ['_hash' => $hash];
                }
            }
            $i = strpos((string) $run['st'], self::PENDING, $i + 1);
        }
        if ($group === []) {
            self::save_run($run);
            return ['run' => $run] + $out;
        }
        if ($gemini === null) {
            $run['status'] = 'paused';
            $run['error'] = __('Choose Gemini as AI provider and paste an API key in Settings.', 'tqk-wpseo');
            self::save_run($run);
            return ['run' => $run, 'fatal' => $run['error']] + $out;
        }

        $ctxs = array_map(static function ($c) {
            unset($c['_hash']);
            return $c;
        }, array_values($group));
        $res = $gemini->call(self::prompt($ctxs), self::schema());

        if ($res['status'] === 'wait') {
            self::save_run($run);
            return ['run' => $run, 'wait' => (int) $res['wait'], 'daily' => (bool) $res['daily']] + $out;
        }
        if ($res['status'] === 'fatal') {
            $run['status'] = 'paused';
            $run['error'] = (string) $res['error'];
            self::save_run($run);
            return ['run' => $run, 'fatal' => $run['error']] + $out;
        }
        $run['calls'] = (int) ($run['calls'] ?? 0) + 1;
        $by_id = [];
        foreach ((array) ($res['data']['pages'] ?? []) as $p) {
            if (is_array($p) && isset($p['post_id'])) {
                $by_id[(int) $p['post_id']] = $p;
            }
        }
        foreach ($group as $idx => $ctx) {
            $id = (int) $ctx['post_id'];
            if ($res['status'] === 'ok' && isset($by_id[$id])) {
                self::store($id, (string) $ctx['_hash'], self::clean($by_id[$id], $ctx), $model);
                $run = self::mark($run, $idx, self::DONE);
                $out['done_ids'][] = $id;
            } else {
                $run = self::mark($run, $idx, self::FAILED);
            }
        }
        self::save_run($run);
        return ['run' => $run] + $out;
    }

    /**
     * Writes the fields the person ticked. Each one is optional; nothing
     * else on the post is touched.
     *
     * @param array $pick field => true, alts => [src...], schema => [field...]
     * @return string[] Applied field names.
     */
    public static function apply(int $id, array $pick): array {
        $s = self::suggestion($id);
        if ($s === null || !current_user_can('edit_post', $id)) {
            return [];
        }
        $d = (array) $s['data'];
        $done = [];
        foreach (['seo_title' => '_tqk_wpseo_title', 'seo_description' => '_tqk_wpseo_description', 'focus_keyword' => '_tqk_wpseo_focus_keyword'] as $f => $meta) {
            if (!empty($pick[$f]) && trim((string) ($d[$f] ?? '')) !== '') {
                update_post_meta($id, $meta, wp_slash((string) $d[$f]));
                $done[] = $f;
            }
        }
        $want_alts = array_map('strval', (array) ($pick['alts'] ?? []));
        foreach ((array) ($d['image_alts'] ?? []) as $a) {
            if (!in_array((string) $a['src'], $want_alts, true)) {
                continue;
            }
            $att = function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid((string) $a['src']) : 0;
            if ($att > 0 && current_user_can('edit_post', $att)) {
                update_post_meta($att, '_wp_attachment_image_alt', wp_slash((string) $a['alt']));
                $done[] = 'alt:' . $att;
            }
        }
        $want_fields = array_map('strval', (array) ($pick['schema'] ?? []));
        foreach ((array) ($d['schema_fields'] ?? []) as $f) {
            if (in_array((string) $f['field'], $want_fields, true)) {
                update_post_meta($id, Fields::key((string) $f['field']), wp_slash((string) $f['value']));
                $done[] = 'schema:' . $f['field'];
            }
        }
        if ($done !== []) {
            \TQK_WPSEO\Core\Data::invalidate_for_post($id);
        }
        return $done;
    }

    // ---------- report ----------

    public static function row_html(int $id): string {
        $s = self::suggestion($id);
        if ($s === null) {
            return '';
        }
        $d = (array) $s['data'];
        $post = get_post($id);
        $title = (string) ($post->post_title ?? ('#' . $id));
        $checks = (array) ($d['checks'] ?? []);
        $line = static function (string $field, string $label, string $current, string $value, string $problem) {
            if ($value === '') {
                return '';
            }
            return '<label class="tqk-ai-opt"><input type="checkbox" data-pick="' . esc_attr($field) . '"' . ($problem === '' ? ' checked' : '') . '>'
                . '<span class="tqk-ai-k">' . esc_html($label) . '</span>'
                . '<span class="tqk-ai-v">' . esc_html($value)
                . ($current !== '' ? '<small class="tqk-ai-was">' . esc_html(sprintf(__('now: %s', 'tqk-wpseo'), $current)) . '</small>' : '')
                . ($problem !== '' ? '<small class="tqk-ai-flag">' . esc_html($problem) . '</small>' : '')
                . '</span></label>';
        };
        $body = $line('seo_title', __('Title', 'tqk-wpseo'), (string) get_post_meta($id, '_tqk_wpseo_title', true), (string) ($d['seo_title'] ?? ''), (string) ($checks['seo_title'] ?? ''))
            . $line('seo_description', __('Description', 'tqk-wpseo'), (string) get_post_meta($id, '_tqk_wpseo_description', true), (string) ($d['seo_description'] ?? ''), (string) ($checks['seo_description'] ?? ''))
            . $line('focus_keyword', __('Focus keyword', 'tqk-wpseo'), (string) get_post_meta($id, '_tqk_wpseo_focus_keyword', true), (string) ($d['focus_keyword'] ?? ''), '');
        foreach ((array) ($d['image_alts'] ?? []) as $a) {
            $body .= '<label class="tqk-ai-opt"><input type="checkbox" data-pick-alt="' . esc_attr((string) $a['src']) . '" checked>'
                . '<span class="tqk-ai-k">ALT</span><span class="tqk-ai-v">' . esc_html((string) $a['alt'])
                . '<small class="tqk-ai-was">' . esc_html(basename((string) $a['src'])) . '</small></span></label>';
        }
        foreach ((array) ($d['schema_fields'] ?? []) as $f) {
            $body .= '<label class="tqk-ai-opt"><input type="checkbox" data-pick-schema="' . esc_attr((string) $f['field']) . '">'
                . '<span class="tqk-ai-k">' . esc_html((string) $f['field']) . '</span><span class="tqk-ai-v tqk-ai-pre">' . esc_html((string) $f['value']) . '</span></label>';
        }
        if (!empty($d['notes'])) {
            $body .= '<p class="tqk-ai-note">' . esc_html((string) $d['notes']) . '</p>';
        }
        return '<div class="tqk-ai-card" data-post="' . $id . '">'
            . '<div class="tqk-ai-head"><a href="' . esc_url((string) get_permalink($id)) . '" target="_blank" rel="noopener">' . esc_html($title) . '</a>'
            . '<span class="tqk-ai-actions"><a href="' . esc_url(admin_url('post.php?post=' . $id . '&action=edit')) . '" class="button button-small">' . esc_html__('Edit', 'tqk-wpseo') . '</a> '
            . '<button type="button" class="button button-small button-primary" data-ai-apply>' . esc_html__('Apply ticked', 'tqk-wpseo') . '</button></span></div>'
            . $body . '</div>';
    }

    /** AJAX entry, routed from Admin\Ajax (nonce + capability already checked). */
    public static function ajax(): void {
        $op = sanitize_key($_POST['op'] ?? '');
        if ($op === 'start') {
            $run = self::start();
            wp_send_json_success(['state' => self::state($run)]);
        }
        if ($op === 'apply') {
            $id = (int) ($_POST['post_id'] ?? 0);
            $pick = json_decode((string) wp_unslash($_POST['pick'] ?? ''), true);
            $done = self::apply($id, is_array($pick) ? $pick : []);
            if ($done === []) {
                wp_send_json_error(['message' => __('Nothing applied.', 'tqk-wpseo')]);
            }
            wp_send_json_success(['message' => sprintf(__('Applied %d change(s).', 'tqk-wpseo'), count($done))]);
        }
        $run = self::run();
        if ($run === [] || (string) ($_POST['run'] ?? '') !== (string) $run['id']) {
            wp_send_json_error(['message' => __('This AI run is no longer current. Reload the page.', 'tqk-wpseo')]);
        }
        if ($op === 'retry') {
            $run = self::retry_failed($run);
            self::save_run($run);
            wp_send_json_success(['state' => self::state($run)]);
        }
        if ($op === 'step') {
            if (($run['status'] ?? '') === 'paused') {
                $run['status'] = 'running';
                $run['error'] = '';
            }
            $provider = Factory::create();
            $gemini = $provider instanceof GeminiProvider ? $provider : null;
            $res = self::step($run, $gemini, (string) \TQK_WPSEO\Admin\Settings::get('gemini_model', ''));
            $rows = '';
            foreach ($res['done_ids'] as $id) {
                $rows .= self::row_html((int) $id);
            }
            wp_send_json_success([
                'state' => self::state($res['run']),
                'wait' => $res['wait'],
                'daily' => $res['daily'],
                'fatal' => $res['fatal'],
                'rows' => $rows,
            ]);
        }
        wp_send_json_error(['message' => __('Unknown action', 'tqk-wpseo')]);
    }
}
