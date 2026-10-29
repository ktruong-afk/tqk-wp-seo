<?php
namespace TQK_WPSEO\Analyzer;

defined('ABSPATH') || exit;

/**
 * Page Audit bot: fetches a same-site URL like an outside viewer (shortcodes
 * and builders fully rendered) and audits head tags, keyword, headings,
 * social tags and every single image (ALT, declared vs intrinsic size, weight).
 */
class PageAudit {

    public const UA = 'TQK-SEO-Audit/0.1 (+local site audit)';

    /**
     * @return array Result envelope (ok + report) or error. Reuses snapshot if
     * nothing changed since last audit (minimalist: no refetch until save).
     */
    public static function audit_url(string $url): array {
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $parts = wp_parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host']) || strcasecmp((string) $parts['host'], $home_host) !== 0) {
            return ['ok' => false, 'error' => __('Only URLs on this site can be audited.', 'tqk-wp-seo')];
        }
        $post_id = (int) url_to_postid($url);
        if ($post_id > 0) {
            $hash = self::source_hash($post_id);
            $cached = get_post_meta($post_id, '_tqk_wpseo_audit_cache', true);
            if (is_array($cached) && ($cached['hash'] ?? '') === $hash && isset($cached['result'])) {
                $cached['result']['cached'] = true;
                $cached['result']['ok'] = true;
                return $cached['result'];
            }
        }
        $res = wp_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'headers' => ['User-Agent' => self::UA]]);
        if (is_wp_error($res)) {
            return ['ok' => false, 'error' => __('Could not fetch the URL.', 'tqk-wp-seo')];
        }
        $html = (string) wp_remote_retrieve_body($res);
        if ($html === '') {
            return ['ok' => false, 'error' => __('Empty response.', 'tqk-wp-seo')];
        }
        $report = self::audit_html($url, $html);
        if ($post_id > 0) {
            update_post_meta($post_id, '_tqk_wpseo_audit_cache', ['hash' => self::source_hash($post_id), 'result' => $report, 'at' => gmdate('c')]);
        }
        return $report;
    }

    private static function source_hash(int $post_id): string {
        $p = get_post($post_id);
        $keys = ['_tqk_wpseo_title', '_tqk_wpseo_description', '_tqk_wpseo_canonical', '_tqk_wpseo_robots', '_tqk_wpseo_focus_keyword', '_tqk_wpseo_secondary_keywords', '_tqk_wpseo_og_title', '_tqk_wpseo_og_description', '_tqk_wpseo_og_image', '_tqk_wpseo_schema_type'];
        $parts = [(string) ($p->post_content ?? ''), (string) ($p->post_modified ?? '')];
        foreach ($keys as $k) {
            $parts[] = (string) get_post_meta($post_id, $k, true);
        }
        return md5(implode('|', $parts));
    }

    /** @return array Full report array. */
    public static function audit_html(string $url, string $html): array {
        $head = self::parse_head($html);
        $body = self::body_html($html);
        $plain = Text::plain($body);
        $headings = Text::headings($body);
        $images = Text::images($body);
        $hrefs = Text::links($body);
        $stats = Analyzer::link_stats($hrefs);

        $post_id = (int) url_to_postid($url);
        $keyword = $post_id > 0 ? (string) get_post_meta($post_id, '_tqk_wpseo_focus_keyword', true) : '';
        $kw = Keyword::analyze($keyword, get_post($post_id) ?: (object) [], [
            'title' => $head['title'],
            'description' => $head['description'],
        ], $plain, $headings, $images);

        $findings = [];
        $add = function (string $id, string $group, string $severity, string $message) use (&$findings) {
            $findings[] = compact('id', 'group', 'severity', 'message');
        };

        // Head tags.
        if ($head['title'] === '') {
            $add('title_tag_missing', 'technical', 'critical', __('No <title> tag found.', 'tqk-wp-seo'));
        } elseif (mb_strlen($head['title']) > 60) {
            $add('title_too_long', 'content', 'warning', __('Rendered title is longer than 60 characters.', 'tqk-wp-seo'));
        }
        if ($head['description'] === '') {
            $add('meta_desc_missing', 'technical', 'critical', __('No meta description found.', 'tqk-wp-seo'));
        }
        if ($head['canonical'] === '') {
            $add('no_canonical', 'technical', 'warning', __('No canonical URL.', 'tqk-wp-seo'));
        }
        if ($head['robots'] === '') {
            $add('robots_missing', 'technical', 'warning', __('No meta robots found.', 'tqk-wp-seo'));
        }

        // Social tags.
        if ($head['og_title'] === '' || $head['og_description'] === '') {
            $add('social_incomplete', 'social', 'warning', __('Open Graph title or description is empty.', 'tqk-wp-seo'));
        }
        if ($head['og_image'] === '') {
            $add('og_image_missing', 'social', 'warning', __('No Open Graph image found.', 'tqk-wp-seo'));
        }

        // Headings.
        $h1 = array_values(array_filter($headings, fn($h) => $h[0] === 1));
        if ($h1 === []) {
            $add('missing_h1', 'content', 'critical', __('No H1 heading found.', 'tqk-wp-seo'));
        } elseif (count($h1) > 1) {
            $add('multiple_h1', 'content', 'warning', __('Multiple H1 headings found.', 'tqk-wp-seo'));
        }

        // Keyword on rendered text.
        if (!$kw['set']) {
            $add('no_focus_keyword', 'content', 'warning', __('No focus keyword set.', 'tqk-wp-seo'));
        } else {
            foreach ($kw['checks'] as $cid => $passed) {
                if (!$passed) {
                    $add($cid, 'content', 'warning', Analyzer::keyword_message($cid));
                }
            }
        }
        if ($post_id > 0) {
            $sec = Keyword::analyze_secondary(
                Keyword::parse_secondary((string) get_post_meta($post_id, '_tqk_wpseo_secondary_keywords', true)),
                ['title' => $head['title'], 'description' => $head['description']],
                $plain
            );
            if ($sec['set'] && $sec['missing'] !== []) {
                $add('kw_secondary_missing', 'content', 'warning', sprintf(__('Secondary keywords missing in content: %s', 'tqk-wp-seo'), implode(', ', $sec['missing'])));
            }
        }

        // Images: aggregate + per-image detail.
        $detail = self::image_details($images);
        if ($detail['missing_alt'] > 0) {
            $add('missing_alt', 'images', 'warning', sprintf(__('%d image(s) missing ALT text.', 'tqk-wp-seo'), $detail['missing_alt']));
        }
        if ($detail['missing_dim'] > 0) {
            $add('missing_dimensions', 'images', 'warning', sprintf(__('%d image(s) missing width/height.', 'tqk-wp-seo'), $detail['missing_dim']));
        }
        if ($detail['heavy'] > 0) {
            $add('heavy_images', 'images', 'warning', sprintf(__('%d image(s) heavier than 500 KB.', 'tqk-wp-seo'), $detail['heavy']));
        }

        $groups = Analyzer::scores($findings);
        return [
            'ok' => true,
            'url' => $url,
            'post_id' => $post_id,
            'score' => Analyzer::overall($groups),
            'groups' => $groups,
            'findings' => $findings,
            'keyword' => $kw,
            'head' => $head,
            'stats' => [
                'words' => count(Text::words($plain)),
                'headings' => count($headings),
                'images' => count($images),
                'internal_links' => $stats['internal'],
                'external_links' => $stats['external'],
            ],
            'images_detail' => $detail['rows'],
        ];
    }

    /** @return array Parsed head fields (all keys always present). */
    public static function parse_head(string $html): array {
        $out = ['title' => '', 'description' => '', 'canonical' => '', 'robots' => '', 'og_title' => '', 'og_description' => '', 'og_image' => '', 'twitter_title' => '', 'twitter_description' => '', 'twitter_image' => ''];
        if (!preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $m)) {
            return $out;
        }
        $head = $m[1];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $t)) {
            $out['title'] = trim(html_entity_decode(wp_strip_all_tags($t[1]), ENT_QUOTES, 'UTF-8'));
        }
        if (preg_match_all('/<meta\b([^>]*)>/i', $head, $mm)) {
            foreach ($mm[1] as $attrs) {
                $name = strtolower(Text::attr($attrs, 'name'));
                $prop = strtolower(Text::attr($attrs, 'property'));
                $content = trim(html_entity_decode(Text::attr($attrs, 'content'), ENT_QUOTES, 'UTF-8'));
                if ($name === 'description') {
                    $out['description'] = $content;
                } elseif ($name === 'robots') {
                    $out['robots'] = $content;
                } elseif ($prop === 'og:title') {
                    $out['og_title'] = $content;
                } elseif ($prop === 'og:description') {
                    $out['og_description'] = $content;
                } elseif ($prop === 'og:image') {
                    $out['og_image'] = $content;
                } elseif ($name === 'twitter:title') {
                    $out['twitter_title'] = $content;
                } elseif ($name === 'twitter:description') {
                    $out['twitter_description'] = $content;
                } elseif ($name === 'twitter:image') {
                    $out['twitter_image'] = $content;
                }
            }
        }
        if (preg_match('/<link\b[^>]*rel\s*=\s*["\']canonical["\'][^>]*>/i', $head, $cm)) {
            $out['canonical'] = Text::attr($cm[0], 'href');
        }
        return $out;
    }

    public static function body_html(string $html): string {
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
            return $m[1];
        }
        return $html;
    }

    /**
     * Per-image audit rows + aggregates.
     *
     * @return array [rows, missing_alt, missing_dim, heavy].
     */
    public static function image_details(array $images): array {
        $rows = [];
        $missing_alt = 0;
        $missing_dim = 0;
        $heavy = 0;
        foreach ($images as $attrs) {
            $src = Text::attr($attrs, 'src');
            $alt = Text::attr($attrs, 'alt');
            $w = Text::attr($attrs, 'width');
            $h = Text::attr($attrs, 'height');
            if (trim($alt) === '') {
                $missing_alt++;
            }
            if ($w === '' || $h === '') {
                $missing_dim++;
            }
            $info = self::image_info($src);
            if (($info['bytes'] ?? 0) > 500 * 1024) {
                $heavy++;
            }
            $rows[] = [
                'src' => $src,
                'alt' => $alt,
                'declared' => ($w !== '' && $h !== '') ? "{$w}×{$h}" : '—',
                'intrinsic' => $info['dims'],
                'loading' => Text::attr($attrs, 'loading') ?: '—',
                'weight' => $info['weight'],
            ];
        }
        return compact('rows', 'missing_alt', 'missing_dim', 'heavy');
    }

    /** @return array [bytes, dims ('WxH' or '—'), weight ('12 KB' or '—')]. */
    public static function image_info(string $src): array {
        $out = ['bytes' => 0, 'dims' => '—', 'weight' => '—'];
        if ($src === '') {
            return $out;
        }
        $key = 'tqk_wpseo_imgsize_' . md5($src);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $head = wp_remote_head($src, ['timeout' => 10, 'redirection' => 2]);
        if (!is_wp_error($head)) {
            $headers = array_change_key_case((array) wp_remote_retrieve_headers($head), CASE_LOWER);
            $bytes = (int) ($headers['content-length'] ?? 0);
            if ($bytes > 0) {
                $out['bytes'] = $bytes;
                $out['weight'] = $bytes >= 1048576
                    ? round($bytes / 1048576, 1) . ' MB'
                    : max(1, (int) round($bytes / 1024)) . ' KB';
            }
        }
        $size = @getimagesize($src);
        if (is_array($size) && ($size[0] ?? 0) > 0) {
            $out['dims'] = $size[0] . '×' . $size[1];
        }
        set_transient($key, $out, 24 * HOUR_IN_SECONDS);
        return $out;
    }

    /** @return string Report HTML fragment for AJAX inject. */
    public static function report_html(array $r): string {
        $tone = \TQK_WPSEO\Admin\View::score_tone((int) ($r['score'] ?? 0));
        $cached = !empty($r['cached']) ? ' <span class="tqk-wpseo-ptype">' . esc_html__('cached — fix first, no rescan yet', 'tqk-wp-seo') . '</span>' : '';
        $html = '<div class="tqk-wpseo-card"><h3>' . esc_html($r['url'] ?? '') . $cached . '</h3>'
            . '<p><strong class="tqk-wpseo-score-num ' . esc_attr($tone) . '">'
            . esc_html(sprintf(__('Score: %d/100', 'tqk-wp-seo'), (int) ($r['score'] ?? 0))) . '</strong></p>';
        $heads = [
            'title' => __('Title', 'tqk-wp-seo'), 'description' => __('Description', 'tqk-wp-seo'),
            'canonical' => __('Canonical', 'tqk-wp-seo'), 'robots' => __('Robots', 'tqk-wp-seo'),
            'og_title' => 'OG title', 'og_description' => 'OG description', 'og_image' => 'OG image',
        ];
        // Head key => [bulk field, meta key]. Editable inline when post is known.
        $editable = [
            'title' => ['title', '_tqk_wpseo_title'],
            'description' => ['desc', '_tqk_wpseo_description'],
            'canonical' => ['canonical', '_tqk_wpseo_canonical'],
            'robots' => ['robots', '_tqk_wpseo_robots'],
            'og_title' => ['og_title', '_tqk_wpseo_og_title'],
            'og_description' => ['og_description', '_tqk_wpseo_og_description'],
            'og_image' => ['og_image', '_tqk_wpseo_og_image'],
        ];
        $post_id = (int) ($r['post_id'] ?? 0);
        $rows = [];
        foreach ($heads as $k => $label) {
            $v = (string) ($r['head'][$k] ?? '');
            $edit = '';
            if ($post_id > 0 && isset($editable[$k])) {
                [$bulk_field, $meta_key] = $editable[$k];
                $stored = (string) get_post_meta($post_id, $meta_key, true);
                if ($k === 'robots') {
                    $opts = '';
                    foreach (\TQK_WPSEO\Admin\Metabox::robot_options() as $rv => $rl) {
                        $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($rv), $rv === $stored ? ' selected' : '', esc_html($rl));
                    }
                    $edit = sprintf(
                        '<span class="tqk-head-edit" data-tqk-post="%d" data-tqk-field="robots"><select>%s</select><button type="button" class="button button-small" data-tqk-save-head>%s</button></span>',
                        $post_id,
                        $opts,
                        esc_html__('Save', 'tqk-wp-seo')
                    );
                } else {
                    $edit = sprintf(
                        '<span class="tqk-head-edit" data-tqk-post="%d" data-tqk-field="%s"><input type="text" value="%s" placeholder="%s"><button type="button" class="button button-small" data-tqk-save-head>%s</button></span>',
                        $post_id,
                        esc_attr($bulk_field),
                        esc_attr($stored),
                        esc_attr(mb_strimwidth($v, 0, 60, '…')),
                        esc_html__('Save', 'tqk-wp-seo')
                    );
                }
            }
            $rows[] = [$label, $v === '' ? '✗' : mb_strimwidth($v, 0, 80, '…'), $edit];
        }
        // Schema row: resolved type + one-go override (missing schema fixed here).
        $schema_label = __('Schema', 'tqk-wp-seo');
        if ($post_id > 0) {
            $post_obj = get_post($post_id);
            $resolved = $post_obj ? \TQK_WPSEO\Schema\Schema::type_for($post_obj) : '';
            $stored_schema = (string) get_post_meta($post_id, '_tqk_wpseo_schema_type', true);
            $opts = '';
            foreach (\TQK_WPSEO\Admin\Metabox::schema_options() as $sv => $sl) {
                $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($sv), $sv === $stored_schema ? ' selected' : '', esc_html($sl));
            }
            $rows[] = [
                $schema_label,
                $resolved === '' ? '✗' : $resolved,
                sprintf(
                    '<span class="tqk-head-edit" data-tqk-post="%d" data-tqk-field="schema"><select>%s</select><button type="button" class="button button-small" data-tqk-save-head>%s</button></span>',
                    $post_id,
                    $opts,
                    esc_html__('Save', 'tqk-wp-seo')
                ),
            ];
        } else {
            // Homepage / archive: points to global template (one place).
            $rows[] = [$schema_label, __('See Schema templates above', 'tqk-wp-seo'), ''];
        }
        $html .= '<h4>' . esc_html__('Head tags', 'tqk-wp-seo') . '</h4>'
            . \TQK_WPSEO\Admin\View::issue_table([__('Tag', 'tqk-wp-seo'), __('Value', 'tqk-wp-seo'), ''], $rows);
        $findings = (array) ($r['findings'] ?? []);
        if ($findings !== []) {
            $html .= '<h4>' . esc_html__('Findings', 'tqk-wp-seo') . '</h4><ul class="tqk-wpseo-findings">';
            foreach ($findings as $f) {
                $mark = ($f['severity'] ?? '') === 'critical' ? '✗' : '⚠';
                $html .= '<li>' . esc_html($mark . ' ' . ($f['message'] ?? '')) . '</li>';
            }
            $html .= '</ul>';
        }
        $img_rows = [];
        foreach ((array) ($r['images_detail'] ?? []) as $img) {
            $src = (string) ($img['src'] ?? '');
            $alt = (string) ($img['alt'] ?? '');
            $att_id = function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($src) : 0;
            $edit = '';
            if ($att_id > 0) {
                $edit = sprintf(
                    '<span class="tqk-alt-edit"><input type="text" size="18" placeholder="%s" value="%s" data-tqk-alt-for="%d">'
                    . '<button type="button" class="button button-small" data-tqk-set-alt="%d">%s</button></span>',
                    esc_attr__('New ALT text', 'tqk-wp-seo'),
                    esc_attr($alt),
                    $att_id,
                    $att_id,
                    esc_html__('Save ALT', 'tqk-wp-seo')
                );
            }
            $img_rows[] = [
                mb_strimwidth($src, 0, 50, '…'),
                ((string) $alt === '' ? '✗ ' : '✓ ') . ($img['declared'] ?? ''),
                (string) ($img['intrinsic'] ?? '') . ' · ' . (string) ($img['weight'] ?? ''),
                $edit,
            ];
        }
        $html .= '<h4>' . esc_html(sprintf(__('Images (%d)', 'tqk-wp-seo'), count($img_rows))) . '</h4>';
        $html .= $img_rows === []
            ? '<p>' . esc_html__('No images found.', 'tqk-wp-seo') . '</p>'
            : \TQK_WPSEO\Admin\View::issue_table(
                [__('Source', 'tqk-wp-seo'), __('ALT · declared', 'tqk-wp-seo'), __('Intrinsic · weight', 'tqk-wp-seo'), ''],
                $img_rows
            );
        return $html . '</div>';
    }
}
