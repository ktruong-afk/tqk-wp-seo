<?php
namespace TQK_WPSEO\Tools;

defined('ABSPATH') || exit;

use TQK_WPSEO\Redirect\Redirects;

/**
 * One-click move from Yoast SEO (incl. Premium) or Rank Math (incl. Pro).
 *
 * Why it matters here: many sites run cracked "Pro" builds of those plugins,
 * which is exactly where injected malware comes from. Leaving them must cost
 * nothing, or nobody leaves. So:
 *   - reads their post meta + redirects directly (their plugin does not even
 *     need to be active, a deactivated or deleted one still left its data);
 *   - converts their title variables to ours, robots flags to our values;
 *   - by default only FILLS empty TQK fields, never overwrites;
 *   - batched (200 posts per request) and preview-first.
 */
class Importer {

    public const BATCH = 200;

    /** Meta key maps: TQK key => source key. */
    public static function map(string $source): array {
        if ($source === 'yoast') {
            return [
                '_tqk_wpseo_title' => '_yoast_wpseo_title',
                '_tqk_wpseo_description' => '_yoast_wpseo_metadesc',
                '_tqk_wpseo_focus_keyword' => '_yoast_wpseo_focuskw',
                '_tqk_wpseo_canonical' => '_yoast_wpseo_canonical',
                '_tqk_wpseo_og_title' => '_yoast_wpseo_opengraph-title',
                '_tqk_wpseo_og_description' => '_yoast_wpseo_opengraph-description',
                '_tqk_wpseo_og_image' => '_yoast_wpseo_opengraph-image',
                '_tqk_wpseo_twitter_title' => '_yoast_wpseo_twitter-title',
                '_tqk_wpseo_twitter_description' => '_yoast_wpseo_twitter-description',
            ];
        }
        return [
            '_tqk_wpseo_title' => 'rank_math_title',
            '_tqk_wpseo_description' => 'rank_math_description',
            '_tqk_wpseo_canonical' => 'rank_math_canonical_url',
            '_tqk_wpseo_og_title' => 'rank_math_facebook_title',
            '_tqk_wpseo_og_description' => 'rank_math_facebook_description',
            '_tqk_wpseo_og_image' => 'rank_math_facebook_image',
            '_tqk_wpseo_twitter_title' => 'rank_math_twitter_title',
            '_tqk_wpseo_twitter_description' => 'rank_math_twitter_description',
        ];
    }

    /** Meta keys whose presence marks a post as having data from a source. */
    public static function marker_keys(string $source): array {
        $keys = array_values(self::map($source));
        $keys[] = $source === 'yoast' ? '_yoast_wpseo_meta-robots-noindex' : 'rank_math_robots';
        $keys[] = $source === 'yoast' ? '_yoast_wpseo_schema_article_type' : 'rank_math_focus_keyword';
        return $keys;
    }

    /** Title separator the source plugin was configured with. */
    public static function separator(string $source): string {
        if ($source === 'yoast') {
            $opt = (array) get_option('wpseo_titles', []);
            $map = ['sc-dash' => '-', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-middot' => '·', 'sc-bull' => '•', 'sc-star' => '*', 'sc-smstar' => '⋆', 'sc-pipe' => '|', 'sc-tilde' => '~', 'sc-laquo' => '«', 'sc-raquo' => '»', 'sc-lt' => '<', 'sc-gt' => '>'];
            return $map[(string) ($opt['separator'] ?? 'sc-dash')] ?? '-';
        }
        $opt = (array) get_option('rank-math-options-titles', []);
        $sep = trim((string) ($opt['title_separator'] ?? '-'));
        return $sep !== '' ? html_entity_decode($sep, ENT_QUOTES, 'UTF-8') : '-';
    }

    /**
     * Their variables → ours. Unknown variables are dropped rather than
     * printed raw into a title Google will show.
     */
    public static function convert_vars(string $text, string $source, string $sep = '-'): string {
        if ($text === '') {
            return '';
        }
        $ours = ['title' => '%title%', 'sitename' => '%site_name%', 'sitedesc' => '%site_description%', 'excerpt' => '%excerpt%',
            'excerpt_only' => '%excerpt%', 'category' => '%category%', 'primary_category' => '%category%', 'date' => '%date%',
            'modified' => '%modified%', 'name' => '%author%', 'author' => '%author%', 'post_author' => '%author%',
            'sep' => $sep, 'page' => '', 'pagenumber' => '', 'pagetotal' => '', 'currentyear' => gmdate('Y'), 'currentmonth' => date_i18n('F'),
            'seo_title' => '%title%', 'seo_description' => '%excerpt%'];
        $re = $source === 'yoast' ? '/%%([a-z0-9_]+)%%/i' : '/%([a-z0-9_]+)(?:\([^)]*\))?%/i';
        $out = (string) preg_replace_callback($re, static function ($m) use ($ours) {
            $k = strtolower($m[1]);
            return $ours[$k] ?? '';
        }, $text);
        // Tidy separators left dangling by dropped variables ("Title |  | ").
        $q = preg_quote($sep, '/');
        $out = (string) preg_replace('/(\s*' . $q . '\s*){2,}/u', ' ' . $sep . ' ', $out);
        $out = (string) preg_replace('/^\s*' . $q . '\s*|\s*' . $q . '\s*$/u', '', $out);
        return trim((string) preg_replace('/\s{2,}/u', ' ', $out));
    }

    /** Robots value in our metabox vocabulary, or '' (default) / null (not representable). */
    public static function robots(int $id, string $source): ?string {
        if ($source === 'yoast') {
            $ni = (string) get_post_meta($id, '_yoast_wpseo_meta-robots-noindex', true);
            $nf = (string) get_post_meta($id, '_yoast_wpseo_meta-robots-nofollow', true);
            $noindex = $ni === '1';
            $nofollow = $nf === '1';
        } else {
            $r = get_post_meta($id, 'rank_math_robots', true);
            $r = is_array($r) ? array_map('strval', $r) : [];
            $noindex = in_array('noindex', $r, true);
            $nofollow = in_array('nofollow', $r, true);
        }
        if ($noindex) {
            return $nofollow ? 'noindex,nofollow' : 'noindex,follow';
        }
        return $nofollow ? null : '';
    }

    /** Their schema choice → our allowed types ('' = automatic). */
    public static function schema_type(int $id, string $source): string {
        $allowed = ['Article', 'BlogPosting', 'WebPage', 'Product', 'Course', 'FAQPage', 'HowTo', 'Recipe', 'Event'];
        if ($source === 'yoast') {
            foreach (['_yoast_wpseo_schema_page_type', '_yoast_wpseo_schema_article_type'] as $k) {
                $v = (string) get_post_meta($id, $k, true);
                if ($v === 'NewsArticle') {
                    $v = 'Article';
                }
                if (in_array($v, $allowed, true) && $v !== 'WebPage') {
                    return $v;
                }
            }
            return '';
        }
        $v = strtolower((string) get_post_meta($id, 'rank_math_rich_snippet', true));
        $map = ['article' => 'Article', 'product' => 'Product', 'course' => 'Course', 'event' => 'Event', 'recipe' => 'Recipe'];
        return $map[$v] ?? '';
    }

    /** @return array{0:string,1:string} [focus keyword, secondary keywords (comma list)] */
    public static function keywords(int $id, string $source): array {
        if ($source === 'yoast') {
            $focus = trim((string) get_post_meta($id, '_yoast_wpseo_focuskw', true));
            $extra = json_decode((string) get_post_meta($id, '_yoast_wpseo_focuskeywords', true), true);
            $sec = [];
            foreach (is_array($extra) ? $extra : [] as $e) {
                $k = trim((string) ($e['keyword'] ?? ''));
                if ($k !== '') {
                    $sec[] = $k;
                }
            }
            return [$focus, implode(', ', array_slice($sec, 0, 4))];
        }
        $all = array_values(array_filter(array_map('trim', explode(',', (string) get_post_meta($id, 'rank_math_focus_keyword', true)))));
        return [(string) ($all[0] ?? ''), implode(', ', array_slice($all, 1, 4))];
    }

    /**
     * Values this post would get. Pure read: used by preview and by import.
     *
     * @return array<string,string> TQK meta key => value (only non-empty)
     */
    public static function values_for(int $id, string $source): array {
        $sep = self::separator($source);
        $out = [];
        foreach (self::map($source) as $ours => $theirs) {
            $v = trim((string) get_post_meta($id, $theirs, true));
            if ($v === '') {
                continue;
            }
            $out[$ours] = in_array($ours, ['_tqk_wpseo_title', '_tqk_wpseo_description', '_tqk_wpseo_og_title', '_tqk_wpseo_og_description', '_tqk_wpseo_twitter_title', '_tqk_wpseo_twitter_description'], true)
                ? self::convert_vars($v, $source, $sep)
                : $v;
        }
        [$focus, $sec] = self::keywords($id, $source);
        if ($focus !== '') {
            $out['_tqk_wpseo_focus_keyword'] = $focus;
        }
        if ($sec !== '') {
            $out['_tqk_wpseo_secondary_keywords'] = $sec;
        }
        $robots = self::robots($id, $source);
        if ($robots !== null && $robots !== '') {
            $out['_tqk_wpseo_robots'] = $robots;
        }
        $schema = self::schema_type($id, $source);
        if ($schema !== '') {
            $out['_tqk_wpseo_schema_type'] = $schema;
        }
        return array_filter($out, static fn($v) => $v !== '');
    }

    /**
     * Imports one post. Fills empty TQK fields; overwrites only when asked.
     *
     * @return array{written:int, kept:int, lossy:bool}
     */
    public static function import_post(int $id, string $source, bool $overwrite = false): array {
        $res = ['written' => 0, 'kept' => 0, 'lossy' => self::robots($id, $source) === null];
        foreach (self::values_for($id, $source) as $key => $val) {
            if (!$overwrite && trim((string) get_post_meta($id, $key, true)) !== '') {
                $res['kept']++;
                continue;
            }
            update_post_meta($id, $key, wp_slash($val));
            $res['written']++;
        }
        if ($res['written'] > 0) {
            \TQK_WPSEO\Core\Data::invalidate_for_post($id);
        }
        return $res;
    }

    /** @return int[] Post IDs that carry data from a source, one page of them. */
    public static function post_ids(string $source, int $offset, int $limit = self::BATCH): array {
        global $wpdb;
        $keys = self::marker_keys($source);
        $in = implode(',', array_fill(0, count($keys), '%s'));
        $sql = $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($in) AND meta_value <> '' ORDER BY post_id ASC LIMIT %d OFFSET %d",
            ...array_merge($keys, [$limit, $offset])
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    public static function count(string $source): int {
        global $wpdb;
        $keys = self::marker_keys($source);
        $in = implode(',', array_fill(0, count($keys), '%s'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ($in) AND meta_value <> ''",
            ...$keys
        ));
    }

    /**
     * Their redirects: Yoast Premium (option) or Rank Math (own table).
     * Only plain/exact sources: regex rules are listed for manual review.
     *
     * @return array{rules: list<array{source:string,target:string,type:int}>, skipped:int}
     */
    public static function redirects(string $source): array {
        global $wpdb;
        $rules = [];
        $skipped = 0;
        if ($source === 'yoast') {
            foreach ((array) get_option('wpseo-premium-redirects-base', []) as $r) {
                $r = (array) $r;
                if (($r['format'] ?? 'plain') !== 'plain') {
                    $skipped++;
                    continue;
                }
                $rules[] = ['source' => '/' . ltrim((string) ($r['origin'] ?? ''), '/'), 'target' => (string) ($r['url'] ?? ''), 'type' => (int) ($r['type'] ?? 301)];
            }
            return compact('rules', 'skipped');
        }
        $table = $wpdb->prefix . 'rank_math_redirections';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return compact('rules', 'skipped');
        }
        foreach ((array) $wpdb->get_results("SELECT sources, url_to, header_code FROM {$table} WHERE status = 'active'") as $row) {
            $sources = maybe_unserialize((string) $row->sources);
            foreach (is_array($sources) ? $sources : [] as $s) {
                if (($s['comparison'] ?? '') !== 'exact') {
                    $skipped++;
                    continue;
                }
                $rules[] = ['source' => '/' . ltrim((string) ($s['pattern'] ?? ''), '/'), 'target' => (string) $row->url_to, 'type' => (int) $row->header_code];
            }
        }
        return compact('rules', 'skipped');
    }

    /** @return array{imported:int, skipped:int} */
    public static function import_redirects(string $source): array {
        $r = self::redirects($source);
        $ok = 0;
        foreach ($r['rules'] as $rule) {
            if (Redirects::add($rule['source'], $rule['target'], in_array($rule['type'], [301, 302, 307, 410], true) ? $rule['type'] : 301) > 0) {
                $ok++;
            }
        }
        return ['imported' => $ok, 'skipped' => $r['skipped'] + (count($r['rules']) - $ok)];
    }

    /**
     * Category / tag / product-category SEO data of a source.
     *
     * @return array<int,array<string,string>> term_id => [TQK meta key => value]
     */
    public static function term_values(string $source): array {
        global $wpdb;
        $sep = self::separator($source);
        $out = [];
        if ($source === 'yoast') {
            // Yoast keeps term SEO in one option: [taxonomy][term_id][wpseo_*].
            foreach ((array) get_option('wpseo_taxonomy_meta', []) as $terms) {
                foreach ((array) $terms as $tid => $m) {
                    $m = (array) $m;
                    $v = [
                        '_tqk_wpseo_title' => self::convert_vars(trim((string) ($m['wpseo_title'] ?? '')), 'yoast', $sep),
                        '_tqk_wpseo_description' => self::convert_vars(trim((string) ($m['wpseo_desc'] ?? '')), 'yoast', $sep),
                        '_tqk_wpseo_focus_keyword' => trim((string) ($m['wpseo_focuskw'] ?? '')),
                        '_tqk_wpseo_canonical' => trim((string) ($m['wpseo_canonical'] ?? '')),
                        '_tqk_wpseo_robots' => ($m['wpseo_noindex'] ?? '') === 'noindex' ? 'noindex,follow' : '',
                    ];
                    $v = array_filter($v, static fn($x) => $x !== '');
                    if ($v !== []) {
                        $out[(int) $tid] = $v;
                    }
                }
            }
            return $out;
        }
        $keys = ['rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_canonical_url', 'rank_math_robots'];
        $in = implode(',', array_fill(0, count($keys), '%s'));
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key IN ($in) AND meta_value <> ''", ...$keys)));
        foreach ($ids as $tid) {
            $robots = get_term_meta($tid, 'rank_math_robots', true);
            $robots = is_array($robots) ? $robots : [];
            $kws = array_values(array_filter(array_map('trim', explode(',', (string) get_term_meta($tid, 'rank_math_focus_keyword', true)))));
            $v = array_filter([
                '_tqk_wpseo_title' => self::convert_vars(trim((string) get_term_meta($tid, 'rank_math_title', true)), 'rankmath', $sep),
                '_tqk_wpseo_description' => self::convert_vars(trim((string) get_term_meta($tid, 'rank_math_description', true)), 'rankmath', $sep),
                '_tqk_wpseo_focus_keyword' => (string) ($kws[0] ?? ''),
                '_tqk_wpseo_canonical' => trim((string) get_term_meta($tid, 'rank_math_canonical_url', true)),
                '_tqk_wpseo_robots' => in_array('noindex', $robots, true) ? (in_array('nofollow', $robots, true) ? 'noindex,nofollow' : 'noindex,follow') : '',
            ], static fn($x) => $x !== '');
            if ($v !== []) {
                $out[$tid] = $v;
            }
        }
        return $out;
    }

    /** @return array{terms:int, written:int, kept:int} */
    public static function import_terms(string $source, bool $overwrite = false): array {
        $res = ['terms' => 0, 'written' => 0, 'kept' => 0];
        foreach (self::term_values($source) as $tid => $vals) {
            $res['terms']++;
            foreach ($vals as $key => $val) {
                if (!$overwrite && trim((string) get_term_meta($tid, $key, true)) !== '') {
                    $res['kept']++;
                    continue;
                }
                update_term_meta($tid, $key, wp_slash($val));
                $res['written']++;
            }
            \TQK_WPSEO\Core\Data::invalidate_for_term($tid);
        }
        return $res;
    }

    public static function source_name(string $s): string {
        return $s === 'yoast' ? 'Yoast SEO' : 'Rank Math';
    }

    public static function valid_source(string $s): string {
        return in_array($s, ['yoast', 'rankmath'], true) ? $s : '';
    }

    /** AJAX entry, routed from Admin\Ajax (nonce + capability already checked). */
    public static function ajax(): void {
        $source = self::valid_source(sanitize_key($_POST['source'] ?? ''));
        if ($source === '') {
            wp_send_json_error(['message' => __('Choose Yoast SEO or Rank Math.', 'tqk-wp-seo')]);
        }
        $op = sanitize_key($_POST['op'] ?? '');
        if ($op === 'preview') {
            $rows = [];
            foreach (self::post_ids($source, 0, 8) as $id) {
                $rows[] = ['id' => $id, 'title' => (string) (get_post($id)->post_title ?? ''), 'values' => self::values_for($id, $source)];
            }
            $red = self::redirects($source);
            wp_send_json_success(['count' => self::count($source), 'terms' => count(self::term_values($source)), 'rows' => $rows, 'redirects' => count($red['rules']), 'redirects_skipped' => $red['skipped']]);
        }
        if ($op === 'batch') {
            $offset = max(0, (int) ($_POST['offset'] ?? 0));
            $overwrite = !empty($_POST['overwrite']);
            $ids = self::post_ids($source, $offset);
            $sum = ['posts' => 0, 'written' => 0, 'kept' => 0, 'lossy' => 0];
            foreach ($ids as $id) {
                $r = self::import_post($id, $source, $overwrite);
                $sum['posts']++;
                $sum['written'] += $r['written'];
                $sum['kept'] += $r['kept'];
                $sum['lossy'] += $r['lossy'] ? 1 : 0;
            }
            $done = count($ids) < self::BATCH;
            if ($done) {
                $sum['terms'] = self::import_terms($source, $overwrite);
            }
            if ($done && !empty($_POST['redirects'])) {
                $sum['redirects'] = self::import_redirects($source);
            }
            wp_send_json_success($sum + ['next' => $offset + count($ids), 'done' => $done]);
        }
        wp_send_json_error(['message' => __('Unknown action', 'tqk-wp-seo')]);
    }
}
