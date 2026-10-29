<?php
namespace TQK_WPSEO\Analyzer;

defined('ABSPATH') || exit;

/**
 * Score + checklist shown at the top of the SEO tab of the post metabox.
 *
 * Three states per check, not two:
 *   ✓ pass, ✗/⚠ problem, – not applicable (no focus keyword yet, no
 *   description to measure…). A check that could not run is never shown as
 *   passed: "✓ Keyword in title" with no keyword set was a lie.
 *
 * html() is also returned over AJAX while the editor types (live preview).
 */
class AnalysisBox {

    public static function hooks(): void {
        add_action('add_meta_boxes', [self::class, 'register']);
    }

    /** Embedded in Metabox::render(); kept so the markup stays owned here. */
    public static function register(): void {
    }

    public static function render($post): void {
        $id = (int) (is_object($post) ? $post->ID : $post);
        echo self::html(Analyzer::stored($id), $id); // phpcs:ignore -- escaped inside
    }

    /**
     * @param array|null $result Analyzer result (stored or live).
     * @param bool $live True when built from unsaved editor values.
     */
    public static function html(?array $result, int $id, bool $live = false): string {
        if ($result === null) {
            return '<div class="tqk-analysis"><p>' . esc_html__('No analysis yet. Type a focus keyword, or save the post, to see the checklist.', 'tqk-wp-seo') . '</p></div>';
        }
        $score = (int) ($result['score'] ?? 0);
        $tone = \TQK_WPSEO\Admin\View::score_tone($score);
        $tone_color = $tone === 'is-good' ? '#059669' : ($tone === 'is-ok' ? '#d97706' : '#e11d48');
        $stats = (array) ($result['stats'] ?? []);
        $unit = (string) ($stats['unit'] ?? __('words', 'tqk-wp-seo'));
        $kw_set = !empty($result['keyword']['set']);
        $by_id = [];
        foreach ((array) ($result['findings'] ?? []) as $f) {
            $by_id[(string) ($f['id'] ?? '')] = $f;
        }
        // Check id => [label, applicable?]
        $na_kw = __('Set a focus keyword to check this.', 'tqk-wp-seo');
        $checks = [
            'technical' => [
                'no_canonical' => [__('Canonical URL set', 'tqk-wp-seo'), true],
                'noindex_unexpected' => [__('Indexable (not unexpected noindex)', 'tqk-wp-seo'), true],
            ],
            'content' => [
                'title_missing' => [__('SEO title present', 'tqk-wp-seo'), true],
                'title_too_long' => [__('Title ≤ 60 chars', 'tqk-wp-seo'), $stats['has_title'] ?? true],
                'description_missing' => [__('Meta description present', 'tqk-wp-seo'), true],
                'description_too_long' => [__('Description ≤ 160 chars', 'tqk-wp-seo'), $stats['has_description'] ?? true],
                'missing_h1' => [__('H1 present', 'tqk-wp-seo'), true],
                'multiple_h1' => [__('Single H1', 'tqk-wp-seo'), ($stats['h1'] ?? 1) > 0],
                'heading_skip' => [__('Heading hierarchy OK', 'tqk-wp-seo'), true],
                'thin_content' => [sprintf(__('Content ≥ %1$d %2$s', 'tqk-wp-seo'), (int) ($stats['min_words'] ?? 300), $unit), true],
                'long_sentences' => [sprintf(__('Sentences ≤ %1$d %2$s on average', 'tqk-wp-seo'), (int) ($stats['max_sentence'] ?? 20), $unit), true],
                'long_paragraphs' => [__('No over-long paragraphs', 'tqk-wp-seo'), true],
                'no_focus_keyword' => [__('Focus keyword set', 'tqk-wp-seo'), true],
                'kw_title' => [__('Keyword in title', 'tqk-wp-seo'), $kw_set],
                'kw_url' => [__('Keyword in URL', 'tqk-wp-seo'), $kw_set],
                'kw_description' => [__('Keyword in description', 'tqk-wp-seo'), $kw_set],
                'kw_h1' => [__('Keyword in H1', 'tqk-wp-seo'), $kw_set],
                'kw_first_para' => [__('Keyword in first paragraph', 'tqk-wp-seo'), $kw_set],
                'kw_h2' => [__('Keyword in H2', 'tqk-wp-seo'), $kw_set],
                'kw_alt' => [__('Keyword in image ALT', 'tqk-wp-seo'), $kw_set],
                'kw_low_density' => [__('Keyword density OK', 'tqk-wp-seo'), $kw_set],
            ],
            'links' => [
                'no_internal_links' => [__('Internal links present', 'tqk-wp-seo'), true],
                'orphan_page' => [__('Not orphan (has incoming links)', 'tqk-wp-seo'), true],
            ],
            'images' => [
                'missing_alt' => [__('All images have ALT', 'tqk-wp-seo'), (int) ($stats['images'] ?? 1) > 0],
                'missing_dimensions' => [__('All images have dimensions', 'tqk-wp-seo'), (int) ($stats['images'] ?? 1) > 0],
            ],
            'schema' => [
                'no_schema' => [__('Schema template set', 'tqk-wp-seo'), true],
            ],
            'social' => [
                'social_incomplete' => [__('OG title + description set', 'tqk-wp-seo'), true],
            ],
        ];
        if (isset($by_id['kw_secondary_missing']) || trim((string) get_post_meta($id, '_tqk_wpseo_secondary_keywords', true)) !== '') {
            $checks['content']['kw_secondary_missing'] = [__('Secondary keywords in content', 'tqk-wp-seo'), true];
        }
        $group_labels = ['technical' => __('Technical', 'tqk-wp-seo'), 'content' => __('Content', 'tqk-wp-seo'), 'links' => __('Links', 'tqk-wp-seo'), 'images' => __('Images', 'tqk-wp-seo'), 'schema' => __('Schema', 'tqk-wp-seo'), 'social' => __('Social', 'tqk-wp-seo')];

        $sections = '';
        $applicable_total = 0;
        foreach ($checks as $group => $list) {
            $items = '';
            $pass = 0;
            $applicable = 0;
            foreach ($list as $cid => [$label, $on]) {
                if (isset($by_id[$cid])) {
                    $applicable++;
                    $crit = ($by_id[$cid]['severity'] ?? '') === 'critical';
                    $items .= sprintf('<li class="is-%s"><span>%s</span> %s</li>', $crit ? 'crit' : 'warn', $crit ? '✗' : '⚠', esc_html((string) ($by_id[$cid]['message'] ?? $label)));
                } elseif (!$on) {
                    $items .= sprintf('<li class="is-na" title="%s"><span>–</span> %s</li>', esc_attr($cid === 'multiple_h1' || str_starts_with($cid, 'kw_') ? $na_kw : ''), esc_html($label));
                } else {
                    $applicable++;
                    $pass++;
                    $items .= sprintf('<li class="is-pass"><span>✓</span> %s</li>', esc_html($label));
                }
            }
            $applicable_total += $applicable;
            $sections .= sprintf(
                '<details class="tqk-analysis-sec"%s><summary><strong>%s</strong><span class="tqk-analysis-count">%d/%d</span></summary><ul>%s</ul></details>',
                $pass < $applicable ? ' open' : '',
                esc_html($group_labels[$group] ?? ucfirst($group)),
                $pass,
                $applicable,
                $items
            );
        }
        $extra = '';
        foreach ((array) ($result['findings'] ?? []) as $f) {
            if (($f['id'] ?? '') === 'unresolved_link') {
                $extra .= sprintf('<li class="is-warn"><span>⚠</span> %s</li>', esc_html($f['message'] ?? ''));
            }
        }
        if ($extra !== '') {
            $sections .= sprintf('<details class="tqk-analysis-sec" open><summary><strong>%s</strong></summary><ul>%s</ul></details>', esc_html__('Link targets', 'tqk-wp-seo'), $extra);
        }

        $bars = '';
        foreach ((array) ($result['groups'] ?? []) as $group => $gscore) {
            $gtone = \TQK_WPSEO\Admin\View::score_tone((int) $gscore);
            $bar_color = $gtone === 'is-good' ? '#10b981' : ($gtone === 'is-ok' ? '#f59e0b' : '#f43f5e');
            $bars .= sprintf(
                '<div class="tqk-analysis-group"><span>%s</span><strong class="%s">%d</strong></div><div class="tqk-analysis-bar"><div style="width:%d%%;background:%s"></div></div>',
                esc_html($group_labels[$group] ?? ucfirst((string) $group)),
                esc_attr($gtone),
                (int) $gscore,
                max(0, min(100, (int) $gscore)),
                esc_attr($bar_color)
            );
        }

        $source = (string) ($result['source'] ?? '');
        $meta = $live
            ? '<span class="tqk-analysis-live">' . esc_html__('Live — not saved yet', 'tqk-wp-seo') . '</span>'
            : esc_html(sprintf(__('%d checks', 'tqk-wp-seo'), $applicable_total));
        $meta .= $source === 'rendered'
            ? ' · ' . esc_html__('content read from the page as visitors see it', 'tqk-wp-seo')
            : ($source === 'editor' ? ' · ' . esc_html__('content read from the editor (view the page once to analyse builder content)', 'tqk-wp-seo') : '');

        return '<div class="tqk-analysis' . ($live ? ' is-live' : '') . '">'
            . sprintf(
                '<div class="tqk-analysis-head"><span class="tqk-analysis-ring" style="--tqk-ring:%s">%d</span><span><strong class="tqk-wpseo-score-num %s">%s: %d/100</strong><br><small>%s</small></span></div>',
                esc_attr($tone_color),
                $score,
                esc_attr($tone),
                esc_html__('SEO Score', 'tqk-wp-seo'),
                $score,
                $meta
            )
            . $bars . $sections . '</div>';
    }

    /**
     * AJAX: analysis of the values currently typed in the editor, without
     * saving anything. Routed from Admin\Ajax.
     */
    public static function ajax_live(): void {
        $id = (int) ($_POST['post_id'] ?? 0);
        if ($id <= 0 || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wp-seo')]);
        }
        $over = [];
        foreach (['title', 'description', 'focus_keyword', 'secondary_keywords'] as $k) {
            if (isset($_POST[$k])) {
                $over[$k] = sanitize_text_field(wp_unslash((string) $_POST[$k]));
            }
        }
        $r = Analyzer::analyze($id, null, $over, false);
        wp_send_json_success(['html' => self::html($r, $id, true), 'score' => (int) $r['score']]);
    }
}
