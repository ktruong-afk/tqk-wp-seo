<?php
namespace TQK_WPSEO\MCP;

defined('ABSPATH') || exit;

/**
 * Scan reports kept for AI agents, and the "sent to AI" inbox.
 *
 * The editor's Scan stores what it measured (4 screen sizes, SEO facts,
 * typography, sections, the action plan, the security check, snapshots).
 * "Send to AI" puts the page in a small inbox; an MCP client (Claude,
 * Cursor…) lists the inbox, reads the report with its screenshots, advises,
 * and may write the SEO fields back through the same tools.
 */
class Reports {

    public const META = '_tqk_wpseo_scan_report';
    public const INBOX = 'tqk_wpseo_mcp_inbox';
    public const MAX_BYTES = 300000;
    public const MAX_INBOX = 50;

    /**
     * Keeps only data (scalars, lists, maps), capped in depth and size.
     * The report is only ever returned as JSON, never printed as HTML.
     */
    public static function clean($v, int $depth = 0) {
        if ($depth > 8) {
            return null;
        }
        if (is_array($v)) {
            $out = [];
            foreach (array_slice($v, 0, 200, true) as $k => $x) {
                $c = self::clean($x, $depth + 1);
                if ($c === null) {
                    continue;
                }
                $key = is_int($k) ? $k : substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $k), 0, 40);
                $out[$key === '' ? 'k' : $key] = $c;
            }
            return $out;
        }
        if (is_bool($v) || is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v)) {
            return mb_substr(wp_strip_all_tags($v), 0, 2000);
        }
        return null;
    }

    public const HISTORY = 10;

    public static function save(int $id, array $report): bool {
        $report = (array) self::clean($report);
        $report['at'] = time();
        // Re-scan replaces the report; only a small score trail is kept
        // (last 10 checks) to see whether the page got better or worse.
        $old = self::get($id);
        $history = (array) ($old['history'] ?? []);
        if ($old !== null) {
            $history[] = self::point($old);
        }
        $report['history'] = array_slice($history, -self::HISTORY);
        $json = (string) wp_json_encode($report);
        if (strlen($json) > self::MAX_BYTES) {
            // Drop the bulkiest part first; the essentials still fit.
            unset($report['sections']);
            $json = (string) wp_json_encode($report);
            if (strlen($json) > self::MAX_BYTES) {
                return false;
            }
        }
        update_post_meta($id, self::META, wp_slash($json));
        return true;
    }

    /** One line of the score trail. */
    public static function point(array $r): array {
        $screens = array_filter(array_map(static fn($s) => is_array($s) && empty($s['error']) ? (int) ($s['score'] ?? 0) : null, (array) ($r['screens'] ?? [])), static fn($v) => $v !== null);
        $plan = (array) ($r['plan'] ?? []);
        return [
            'at' => (int) ($r['at'] ?? 0),
            'source' => (string) ($r['source'] ?? ''),
            'seo' => (int) ($r['score'] ?? 0),
            'responsive' => $screens === [] ? 0 : min($screens),
            'fix_now' => count(array_filter($plan, static fn($p) => (int) ($p['prio'] ?? 3) === 1)),
            'should_fix' => count(array_filter($plan, static fn($p) => (int) ($p['prio'] ?? 3) === 2)),
        ];
    }

    public static function get(int $id): ?array {
        $r = json_decode((string) get_post_meta($id, self::META, true), true);
        return is_array($r) ? $r : null;
    }

    /** Merges one part (e.g. the security check that finishes later). */
    public static function set_part(int $id, string $key, array $value): void {
        $r = self::get($id);
        if ($r === null) {
            return;
        }
        $r[$key] = self::clean($value);
        update_post_meta($id, self::META, wp_slash((string) wp_json_encode($r)));
    }

    // ---------- site overview (dashboard) ----------

    /**
     * Everything the page reports say, across the site: how many pages were
     * checked, average SEO score, weakest display, security, and the plan
     * items grouped by kind with the number of pages each one hits.
     *
     * @return array{pages:int,avg_seo:int,worst:?array,last:int,security:array,issues:array}
     */
    /** @return int[] Posts that have a page report. */
    public static function ids(int $limit = 500): array {
        return array_map('intval', (array) get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => $limit,
            'fields' => 'ids',
            'meta_key' => self::META,
            'suppress_filters' => true,
        ]));
    }

    public static function overview(int $limit = 500): array {
        $ids = self::ids($limit);
        $out = ['pages' => 0, 'avg_seo' => 0, 'worst' => null, 'last' => 0, 'security' => ['clean' => 0, 'review' => 0, 'infected' => 0], 'issues' => []];
        $sum = 0;
        foreach (array_map('intval', (array) $ids) as $id) {
            $r = self::get($id);
            if ($r === null) {
                continue;
            }
            $out['pages']++;
            $sum += (int) ($r['score'] ?? 0);
            $out['last'] = max($out['last'], (int) ($r['at'] ?? 0));
            $p = self::point($r);
            if ($p['responsive'] > 0 && ($out['worst'] === null || $p['responsive'] < $out['worst']['score'])) {
                $out['worst'] = ['id' => $id, 'title' => (string) get_the_title($id), 'score' => $p['responsive']];
            }
            $v = (string) ($r['security']['verdict'] ?? '');
            if (isset($out['security'][$v])) {
                $out['security'][$v]++;
            }
            $seen = [];
            foreach ((array) ($r['plan'] ?? []) as $it) {
                $prio = (int) ($it['prio'] ?? 3);
                // Reports made before items carried a code: group by wording
                // (numbers removed), never by area (that would mix problems).
                $code = (string) ($it['code'] ?? '');
                if ($code === '' || $code === (string) ($it['area'] ?? '')) {
                    $code = 't:' . md5((string) preg_replace('/\d+/', '#', (string) ($it['title'] ?? '')));
                }
                if ($prio > 2 || $code === '' || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                if (!isset($out['issues'][$code])) {
                    $out['issues'][$code] = ['code' => $code, 'prio' => $prio, 'pages' => 0, 'title' => (string) ($it['title'] ?? ''), 'examples' => []];
                }
                $out['issues'][$code]['pages']++;
                $out['issues'][$code]['prio'] = min($out['issues'][$code]['prio'], $prio);
                if (count($out['issues'][$code]['examples']) < 3) {
                    $out['issues'][$code]['examples'][] = ['id' => $id, 'title' => (string) get_the_title($id)];
                }
            }
        }
        $out['avg_seo'] = $out['pages'] > 0 ? (int) round($sum / $out['pages']) : 0;
        uasort($out['issues'], static fn($a, $b) => [$a['prio'], -$a['pages']] <=> [$b['prio'], -$b['pages']]);
        return $out;
    }

    // ---------- AI suggestions awaiting the owner's approval ----------

    public const SUGGESTION = '_tqk_wpseo_ai_suggestion';
    public const PENDING = 'tqk_wpseo_ai_pending';

    /**
     * Stores what an AI app proposes for a page: its advice (the plan, in
     * words) and field values, cleaned through the same whitelist as the
     * JSON import. Nothing is written to the page itself.
     */
    public static function suggest(int $id, string $advice, array $fields, string $from = ''): array {
        $parsed = $fields === []
            ? ['fields' => [], 'labels' => [], 'dropped' => [], 'notes' => []]
            : \TQK_WPSEO\Admin\AiJson::parse((string) wp_json_encode(['tqk_seo' => 1, 'post_id' => $id, 'fields' => $fields]), $id);
        if (isset($parsed['error'])) {
            $parsed = ['fields' => [], 'labels' => [], 'dropped' => [], 'notes' => [$parsed['error']]];
        }
        $s = [
            'at' => time(),
            'from' => mb_substr(sanitize_text_field($from !== '' ? $from : 'AI (MCP)'), 0, 60),
            'advice' => mb_substr(sanitize_textarea_field($advice), 0, 20000),
            'fields' => (array) $parsed['fields'],
            'labels' => (array) $parsed['labels'],
            'refused' => array_values(array_merge((array) $parsed['dropped'], (array) $parsed['notes'])),
            'status' => 'pending',
        ];
        update_post_meta($id, self::SUGGESTION, wp_slash((string) wp_json_encode($s, JSON_UNESCAPED_UNICODE)));
        $pending = array_values(array_unique(array_merge([$id], array_map('intval', (array) get_option(self::PENDING, [])))));
        update_option(self::PENDING, array_slice($pending, 0, 200), false);
        return $s;
    }

    public static function suggestion(int $id): ?array {
        $s = json_decode((string) get_post_meta($id, self::SUGGESTION, true), true);
        return is_array($s) ? $s : null;
    }

    /** applied (fields used) or dismissed: leaves the pending list. */
    public static function close_suggestion(int $id, string $status): bool {
        $s = self::suggestion($id);
        if ($s === null || !in_array($status, ['applied', 'dismissed'], true)) {
            return false;
        }
        $s['status'] = $status;
        $s['closed'] = time();
        update_post_meta($id, self::SUGGESTION, wp_slash((string) wp_json_encode($s, JSON_UNESCAPED_UNICODE)));
        update_option(self::PENDING, array_values(array_diff(array_map('intval', (array) get_option(self::PENDING, [])), [$id])), false);
        return true;
    }

    /** @return array<int,array> post ID => pending suggestion, newest first. */
    public static function pending(): array {
        $out = [];
        foreach (array_map('intval', (array) get_option(self::PENDING, [])) as $id) {
            $s = self::suggestion($id);
            if ($s !== null && ($s['status'] ?? '') === 'pending' && get_post($id)) {
                $out[$id] = $s;
            }
        }
        return $out;
    }

    // ---------- inbox ----------

    public static function inbox(): array {
        $i = get_option(self::INBOX, []);
        return is_array($i) ? $i : [];
    }

    public static function push(int $id, string $note = ''): array {
        $list = array_values(array_filter(self::inbox(), static fn($e) => (int) ($e['id'] ?? 0) !== $id));
        array_unshift($list, ['id' => $id, 'at' => time(), 'note' => mb_substr(sanitize_textarea_field($note), 0, 1000), 'by' => (int) get_current_user_id()]);
        update_option(self::INBOX, array_slice($list, 0, self::MAX_INBOX), false);
        return $list[0];
    }

    public static function done(int $id): bool {
        $list = self::inbox();
        $left = array_values(array_filter($list, static fn($e) => (int) ($e['id'] ?? 0) !== $id));
        update_option(self::INBOX, $left, false);
        return count($left) < count($list);
    }

    public static function in_inbox(int $id): bool {
        foreach (self::inbox() as $e) {
            if ((int) ($e['id'] ?? 0) === $id) {
                return true;
            }
        }
        return false;
    }

    /** Short line per pushed page, for the agent's first look. */
    public static function inbox_summary(): array {
        $out = [];
        foreach (self::inbox() as $e) {
            $id = (int) $e['id'];
            $r = self::get($id);
            $n = [1 => 0, 2 => 0, 3 => 0];
            foreach ((array) ($r['plan'] ?? []) as $it) {
                $p = max(1, min(3, (int) ($it['prio'] ?? 3)));
                $n[$p]++;
            }
            $out[] = [
                'post_id' => $id,
                'title' => (string) get_the_title($id),
                'url' => (string) get_permalink($id),
                'sent_at' => gmdate('c', (int) $e['at']),
                'note' => (string) ($e['note'] ?? ''),
                'scanned_at' => $r ? gmdate('c', (int) ($r['at'] ?? 0)) : null,
                'plan' => ['fix_now' => $n[1], 'should_fix' => $n[2], 'nice_to_have' => $n[3]],
                'security' => (string) ($r['security']['verdict'] ?? 'not checked'),
                'screenshots' => array_keys((array) ($r['shots'] ?? [])),
                'your_suggestion' => (string) (self::suggestion($id)['status'] ?? 'none yet'),
            ];
        }
        return $out;
    }
}
