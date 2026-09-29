<?php
namespace TQK_WPSEO\Analyzer;

defined('ABSPATH') || exit;

/**
 * S4 — Main analyzer. Pure-ish: analyze($post_id) returns the result array
 * and stores it as JSON in _tqk_wpseo_analysis. Severity: critical|warning.
 */
class Analyzer {

    public const META = '_tqk_wpseo_analysis';
    public const GROUPS = ['technical', 'content', 'links', 'images', 'schema', 'social'];

    public static function hooks(): void {
        add_action('tqk_wpseo_process_queue', [self::class, 'process_queue']);
    }

    public static function process_queue(): int {
        if (!\TQK_WPSEO\Core\Modules::is_active('analyzer')) {
            return 0;
        }
        $n = 0;
        foreach (\TQK_WPSEO\Core\Queue::consume() as $id) {
            if (get_post_status($id) === false) {
                continue;
            }
            self::analyze($id);
            $n++;
        }
        return $n;
    }

    /** @return array Result with score, groups, findings, keyword, stats. */
    public const RENDERED = '_tqk_wpseo_rendered';

    /**
     * Content to analyze: the page as visitors see it (saved by
     * Analyzer\Frontend when an admin views it) when that is at least as
     * new as the last edit, else the editor content. Builder pages keep
     * almost nothing in post_content, so this is what makes their score real.
     *
     * @return array{0:string,1:string} [html, source: rendered|editor]
     */
    public static function content_for($post): array {
        $r = get_post_meta((int) $post->ID, self::RENDERED, true);
        $r = is_string($r) ? json_decode($r, true) : null;
        $modified = strtotime((string) ($post->post_modified_gmt ?? $post->post_modified ?? '')) ?: 0;
        if (is_array($r) && ($r['html'] ?? '') !== '' && (int) ($r['at'] ?? 0) >= $modified) {
            return [(string) $r['html'], 'rendered'];
        }
        return [(string) apply_filters('tqk_wpseo_analyzer_content', (string) ($post->post_content ?? ''), $post), 'editor'];
    }

    /**
     * @param array $overrides Unsaved values from the editor (title,
     *   description, focus_keyword, secondary_keywords): live preview only.
     * @param bool $store Save the result as the post's analysis.
     */
    public static function analyze(int $post_id, ?string $html_override = null, array $overrides = [], bool $store = true): array {
        $post = get_post($post_id);
        if (!$post) {
            return ['score' => 0, 'groups' => [], 'findings' => [], 'keyword' => ['set' => false], 'stats' => []];
        }
        $data = \TQK_WPSEO\Core\Data::for(['type' => 'singular', 'object_id' => $post_id, 'post_type' => $post->post_type ?? 'post']);
        // Editor values not saved yet: the checklist follows what is typed.
        $map = ['title' => (string) ($post->post_title ?? ''), 'excerpt' => (string) ($post->post_excerpt ?? ''), 'author' => '', 'date' => '', 'modified' => '', 'category' => ''];
        foreach (['title', 'description'] as $k) {
            if (array_key_exists($k, $overrides)) {
                $typed = trim((string) $overrides[$k]);
                $tpl = (string) \TQK_WPSEO\Admin\Settings::get($k === 'title' ? 'title_template' : 'description_template', '');
                $data[$k] = \TQK_WPSEO\Core\Data::tags($typed !== '' ? $typed : $tpl, $map);
                // Social cards follow the SEO value unless a custom OG one is set.
                if ((string) get_post_meta($post_id, '_tqk_wpseo_og_' . $k, true) === '') {
                    $data['og_' . $k] = $data[$k];
                }
            }
        }
        if (array_key_exists('focus_keyword', $overrides)) {
            $data['focus_keyword'] = trim((string) $overrides['focus_keyword']);
        }
        $source = 'override';
        if ($html_override === null) {
            [$html_override, $source] = self::content_for($post);
        }
        $raw = $html_override;
        $html = $raw;
        $plain = Text::plain($html);
        $headings = Text::headings($html);
        $images = Text::images($html);
        $links = Text::links($html);

        $findings = [];
        $add = function (string $id, string $group, string $severity, string $message) use (&$findings) {
            $findings[] = compact('id', 'group', 'severity', 'message');
        };

        // --- Technical ---
        if (($data['canonical'] ?? '') === '') {
            $add('no_canonical', 'technical', 'warning', __('No canonical URL.', 'tqk-wpseo'));
        }
        $robots = $data['robots'] ?? ['index' => true];
        if (!($robots['index'] ?? true) && in_array($post->post_status ?? '', ['publish'], true)) {
            $add('noindex_unexpected', 'technical', 'critical', __('Published content is set to noindex.', 'tqk-wpseo'));
        }

        // --- Content: title / description ---
        $title = (string) ($data['title'] ?? '');
        $desc = (string) ($data['description'] ?? '');
        if ($title === '') {
            $add('title_missing', 'content', 'critical', __('Missing SEO title.', 'tqk-wpseo'));
        } elseif (mb_strlen($title) > 60) {
            $add('title_too_long', 'content', 'warning', __('SEO title is longer than 60 characters.', 'tqk-wpseo'));
        }
        if ($desc === '') {
            $add('description_missing', 'content', 'critical', __('Missing meta description.', 'tqk-wpseo'));
        } elseif (mb_strlen($desc) > 160) {
            $add('description_too_long', 'content', 'warning', __('Meta description is longer than 160 characters.', 'tqk-wpseo'));
        }

        // --- Content: headings ---
        $h1 = array_values(array_filter($headings, fn($h) => $h[0] === 1));
        if ($h1 === []) {
            $add('missing_h1', 'content', 'critical', __('No H1 heading found.', 'tqk-wpseo'));
        } elseif (count($h1) > 1) {
            $add('multiple_h1', 'content', 'warning', __('Multiple H1 headings found.', 'tqk-wpseo'));
        }
        $prev = 0;
        foreach ($headings as [$level]) {
            if ($prev > 0 && $level > $prev + 1) {
                $add('heading_skip', 'content', 'warning', __('Heading hierarchy is broken (level skipped).', 'tqk-wpseo'));
                break;
            }
            $prev = $level;
        }

        // --- Content: length ---
        // Vietnamese counts syllables, not words ("máy lọc nước" = 3), so
        // every length rule scales: ~1.7 syllables per Vietnamese word.
        $vi = Text::is_vietnamese($plain);
        $unit = $vi ? __('syllables', 'tqk-wpseo') : __('words', 'tqk-wpseo');
        $words = count(Text::words($plain));
        $min_words = $vi ? 500 : 300;
        if ($words < $min_words) {
            $add('thin_content', 'content', 'warning', sprintf(__('Content is thin (under %1$d %2$s).', 'tqk-wpseo'), $min_words, $unit));
        }

        // --- Content: readability ---
        $sentences = Text::sentences($plain);
        $avg_sent = $sentences === [] ? 0 : round(array_sum(array_map(fn($s) => count(Text::words($s)), $sentences)) / count($sentences), 1);
        $max_sent = $vi ? 35 : 20;
        if ($avg_sent > $max_sent) {
            $add('long_sentences', 'content', 'warning', sprintf(__('Average sentence length is over %1$d %2$s.', 'tqk-wpseo'), $max_sent, $unit));
        }
        $max_para = $vi ? 250 : 150;
        $long_paras = count(array_filter(Text::paragraphs($html), fn($p) => count(Text::words($p)) > $max_para));
        if ($long_paras > 0) {
            $add('long_paragraphs', 'content', 'warning', sprintf(__('%1$d paragraph(s) over %2$d %3$s: split them for phone readers.', 'tqk-wpseo'), $long_paras, $max_para, $unit));
        }

        // --- Keyword (primary + up to 4 secondary) ---
        $kw = Keyword::analyze((string) ($data['focus_keyword'] ?? ''), $post, $data, $plain, $headings, $images);
        if (!$kw['set']) {
            $add('no_focus_keyword', 'content', 'warning', __('No focus keyword set.', 'tqk-wpseo'));
        } else {
            foreach ($kw['checks'] as $cid => $passed) {
                if (!$passed) {
                    $add($cid, 'content', 'warning', self::keyword_message($cid));
                }
            }
            if ($kw['density'] < 0.5) {
                $add('kw_low_density', 'content', 'warning', __('Keyword density is low.', 'tqk-wpseo'));
            }
        }
        $secondary = Keyword::parse_secondary(array_key_exists('secondary_keywords', $overrides)
            ? (string) $overrides['secondary_keywords']
            : (string) get_post_meta($post_id, '_tqk_wpseo_secondary_keywords', true));
        $sec = Keyword::analyze_secondary($secondary, ['title' => $title, 'description' => $desc], $plain);
        if ($sec['set'] && $sec['missing'] !== []) {
            $add('kw_secondary_missing', 'content', 'warning', sprintf(__('Secondary keywords missing in content: %s', 'tqk-wpseo'), implode(', ', $sec['missing'])));
        }

        // --- Images ---
        $missing_alt = 0;
        $missing_dim = 0;
        foreach ($images as $attrs) {
            if (trim(Text::attr($attrs, 'alt')) === '') {
                $missing_alt++;
            }
            if (Text::attr($attrs, 'width') === '' || Text::attr($attrs, 'height') === '') {
                $missing_dim++;
            }
        }
        if ($missing_alt > 0) {
            $add('missing_alt', 'images', 'warning', sprintf(__('%d image(s) missing ALT text.', 'tqk-wpseo'), $missing_alt));
        }
        if ($missing_dim > 0) {
            $add('missing_dimensions', 'images', 'warning', sprintf(__('%d image(s) missing width/height.', 'tqk-wpseo'), $missing_dim));
        }

        // --- Links ---
        $link_stats = self::link_stats($links);
        if ($link_stats['internal'] === 0 && $words >= 100) {
            $add('no_internal_links', 'links', 'warning', __('No internal links found.', 'tqk-wpseo'));
        }
        foreach (array_slice($link_stats['unresolved'], 0, 5) as $u) {
            $add('unresolved_link', 'links', 'warning', sprintf(__('Internal link target not found: %s', 'tqk-wpseo'), $u));
        }
        if (self::is_orphan($post_id)) {
            $add('orphan_page', 'links', 'warning', __('No incoming internal links (orphan).', 'tqk-wpseo'));
        }

        // --- Schema: CPT-aware (missing template = warning, fix in Site Analysis one-go) ---
        $pt = (string) ($post->post_type ?? 'post');
        $schema_map = \TQK_WPSEO\Admin\Settings::get('schema_map', []);
        $override = (string) get_post_meta($post_id, '_tqk_wpseo_schema_type', true);
        if ($override === '' && (!is_array($schema_map) || trim((string) ($schema_map[$pt] ?? '')) === '')) {
            $add('no_schema', 'schema', 'warning', sprintf(__('No Schema template for post type "%s".', 'tqk-wpseo'), $pt));
        }

        // --- Social ---
        if (($data['og_title'] ?? '') === '' || ($data['og_description'] ?? '') === '') {
            $add('social_incomplete', 'social', 'warning', __('Open Graph title or description is empty.', 'tqk-wpseo'));
        }

        $result = [
            'score' => 0,
            'groups' => self::scores($findings),
            'findings' => $findings,
            'keyword' => $kw,
            'stats' => [
                'words' => $words,
                'headings' => count($headings),
                'images' => count($images),
                'internal_links' => $link_stats['internal'],
                'external_links' => $link_stats['external'],
                'unresolved' => count($link_stats['unresolved']),
                'avg_sentence' => $avg_sent,
                // Thresholds actually used, so the checklist labels match.
                'vi' => $vi,
                'min_words' => $min_words,
                'max_sentence' => $max_sent,
                'unit' => $unit,
                'has_title' => $title !== '',
                'has_description' => $desc !== '',
                'h1' => count($h1),
            ],
            'source' => $source,
            'evaluated' => self::evaluated_slots($kw['set'], count($link_stats['unresolved'])) + ($sec['set'] ? 2 : 0),
            'analyzed_at' => gmdate('c'),
        ];
        $result['score'] = self::overall($result['groups'], $findings);
        if ($store) {
            update_post_meta($post_id, self::META, wp_json_encode($result));
        }
        return $result;
    }

    public static function keyword_message(string $cid): string {
        $map = [
            'kw_title' => __('Keyword not found in SEO title.', 'tqk-wpseo'),
            'kw_url' => __('Keyword not found in URL.', 'tqk-wpseo'),
            'kw_description' => __('Keyword not found in meta description.', 'tqk-wpseo'),
            'kw_h1' => __('Keyword not found in H1.', 'tqk-wpseo'),
            'kw_first_para' => __('Keyword not found in first paragraph.', 'tqk-wpseo'),
            'kw_h2' => __('Keyword not found in any H2.', 'tqk-wpseo'),
            'kw_alt' => __('Keyword not found in image ALT text.', 'tqk-wpseo'),
        ];
        return $map[$cid] ?? $cid;
    }

    /** @return array [internal, external, unresolved[]]. */
    public static function link_stats(array $hrefs): array {
        $home_host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        $internal = 0;
        $external = 0;
        $unresolved = [];
        foreach ($hrefs as $href) {
            $href = trim($href);
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            $host = (string) parse_url($href, PHP_URL_HOST);
            $is_internal = $host === '' || strcasecmp($host, $home_host) === 0;
            if (!$is_internal) {
                $external++;
                continue;
            }
            $internal++;
            $abs = $host === '' ? home_url($href) : $href;
            if (url_to_postid($abs) === 0 && !str_contains($href, '?')) {
                $unresolved[] = $href;
            }
        }
        return ['internal' => $internal, 'external' => $external, 'unresolved' => array_values(array_unique($unresolved))];
    }

    private static function is_orphan(int $post_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tqk_wpseo_links';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return false;
        }
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($total === 0) {
            return false; // No link data yet (S5 not run) — don't punish.
        }
        $incoming = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE target_id = %d", $post_id));
        return $incoming === 0;
    }

    /** @return array group => score 0-100. */
    public static function scores(array $findings): array {
        $out = [];
        foreach (self::GROUPS as $g) {
            $out[$g] = 100;
        }
        foreach ($findings as $f) {
            $penalty = ($f['severity'] ?? '') === 'critical' ? 34 : 12;
            $out[$f['group']] = max(0, $out[$f['group']] - $penalty);
        }
        return $out;
    }

    /** Content decides most of the ranking; technical/schema are usually fine. */
    public const WEIGHTS = ['content' => 40, 'technical' => 15, 'social' => 15, 'links' => 10, 'images' => 10, 'schema' => 10];

    /**
     * Weighted score, capped by critical problems: a page with no meta
     * description or no H1 is not an 80/100 page, however clean the rest is.
     */
    public static function overall(array $groups, array $findings = []): int {
        if ($groups === []) {
            return 0;
        }
        $sum = 0;
        $weight = 0;
        foreach ($groups as $g => $score) {
            $w = self::WEIGHTS[$g] ?? 10;
            $sum += $w * (int) $score;
            $weight += $w;
        }
        $score = (int) round($sum / max(1, $weight));
        $critical = count(array_filter($findings, static fn($f) => ($f['severity'] ?? '') === 'critical'));
        if ($critical > 0) {
            $score = min($score, max(30, 70 - 15 * ($critical - 1)));
        }
        return $score;
    }

    /** Slots evaluated = fixed 14 + keyword (1|8) + unresolved links. */
    public static function evaluated_slots(bool $kw_set, int $unresolved): int {
        return 14 + ($kw_set ? 8 : 1) + max(1, min(5, $unresolved));
    }

    /** @return array|null Decoded stored result. */
    public static function stored(int $post_id): ?array {
        $raw = get_post_meta($post_id, self::META, true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
