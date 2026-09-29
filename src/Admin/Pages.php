<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

use TQK_WPSEO\Links\Engine as LinkEngine;
use TQK_WPSEO\Redirect\Monitor404;
use TQK_WPSEO\Redirect\Redirects;
use TQK_WPSEO\Scanner\Scanner;

/**
 * Page bodies. Dashboard reads the latest scan summary (S5); every number
 * links to the filtered list. No fake data: without a scan, empty states show.
 */
class Pages {

    private static function guard(): bool {
        return \TQK_WPSEO\Admin\Access::can();
    }

    private static function denied(): void {
        View::page(
            __('TQK SEO', 'tqk-wpseo'),
            View::empty_state(
                __('Permission denied', 'tqk-wpseo'),
                __('You need the manage_options capability to view this page.', 'tqk-wpseo')
            )
        );
    }

    // ---------- Dashboard ----------

    public static function dashboard(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $summary = Scanner::summary();
        $site = $summary === [] ? '' : '<h2 class="tqk-wpseo-section">' . esc_html__('Site issues (schema · sitemap · robots)', 'tqk-wpseo') . '</h2>'
            . self::site_block((array) ($summary['site'] ?? []));

        // What the plugin is → how the site stands → simple fixes right here
        // → site-wide issues → what each page does → setup → author.
        $content = self::hero()
            . self::overview_block()
            . self::quick_edit_block()
            . $site
            . self::guide_cards()
            . '<h2 class="tqk-wpseo-section">' . esc_html__('Setup checklist', 'tqk-wpseo') . '</h2>' . self::setup_checklist()
            . self::author_card();

        View::page(__('Dashboard', 'tqk-wpseo'), $content);
    }

    /**
     * Quick edit of the simple SEO fields, right on the dashboard, for pages
     * already checked (Live Audit / editor Scan) that miss them: SEO title,
     * meta description, focus keyword, schema type. Everything else is done
     * in the post editor.
     */
    private static function quick_edit_block(): string {
        $rows = [];
        foreach (\TQK_WPSEO\MCP\Reports::ids() as $id) {
            $r = \TQK_WPSEO\MCP\Reports::get($id);
            if ($r === null || !current_user_can('edit_post', $id)) {
                continue;
            }
            $codes = array_map(static fn($i) => (string) ($i['code'] ?? ''), (array) ($r['plan'] ?? []));
            $titles = implode(' ', array_map(static fn($i) => (string) ($i['title'] ?? ''), (array) ($r['plan'] ?? [])));
            $has = static fn(array $c, string $re) => array_intersect($codes, $c) !== [] || (bool) preg_match($re, $titles);
            $missing = [];
            if ((string) get_post_meta($id, '_tqk_wpseo_title', true) === '' && $has(['noTitle', 'longTitle'], '/<title>|Title over/i')) {
                $missing[] = 'title';
            }
            if ((string) get_post_meta($id, '_tqk_wpseo_description', true) === '') {
                $missing[] = 'desc';
            }
            if ((string) get_post_meta($id, '_tqk_wpseo_focus_keyword', true) === '') {
                $missing[] = 'keyword';
            }
            if ($has(['noSchema', 'schemaMiss', 'badJson'], '/schema|JSON-LD/i')) {
                $missing[] = 'schema';
            }
            if ($missing !== []) {
                $rows[$id] = $missing;
            }
        }
        if ($rows === []) {
            return '';
        }
        uasort($rows, static fn($a, $b) => count($b) <=> count($a));
        $labels = ['title' => __('SEO title', 'tqk-wpseo'), 'desc' => __('Description', 'tqk-wpseo'), 'keyword' => __('Focus keyword', 'tqk-wpseo'), 'schema' => __('Schema', 'tqk-wpseo')];
        $html = '';
        foreach (array_slice($rows, 0, 25, true) as $id => $missing) {
            $p = Metabox::preview($id);
            $miss = static fn(string $k) => in_array($k, $missing, true) ? ' is-missing' : '';
            $schema_cur = (string) get_post_meta($id, '_tqk_wpseo_schema_type', true);
            $schema_opts = '';
            foreach (Metabox::schema_options() as $sv => $sl) {
                $schema_opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($sv), $sv === $schema_cur ? ' selected' : '', esc_html($sl));
            }
            $html .= sprintf(
                '<form class="tqk-qe-row" data-tqk-quickfix data-id="%1$d">'
                . '<div class="tqk-qe-page"><a href="%2$s">%3$s</a><small>%4$s</small></div>'
                . '<input type="text" name="title" class="%5$s" value="%6$s" placeholder="%7$s" aria-label="%8$s">'
                . '<input type="text" name="desc" class="%9$s" value="%10$s" placeholder="%11$s" aria-label="%12$s">'
                . '<input type="text" name="keyword" class="%13$s" value="%14$s" placeholder="%15$s" aria-label="%15$s">'
                . '<select name="schema" class="%16$s" aria-label="%17$s">%18$s</select>'
                . '<span class="tqk-qe-save"><button type="submit" class="button button-primary button-small">%19$s</button><span class="tqk-qf-status"></span></span>'
                . '</form>',
                $id,
                esc_url((string) get_edit_post_link($id, 'raw')),
                esc_html((string) get_the_title($id)),
                esc_html(sprintf(__('Missing: %s', 'tqk-wpseo'), implode(', ', array_map(static fn($k) => $labels[$k], $missing)))),
                trim($miss('title')),
                esc_attr((string) get_post_meta($id, '_tqk_wpseo_title', true)),
                esc_attr((string) ($p['title'] ?? '')),
                esc_attr($labels['title']),
                trim($miss('desc')),
                esc_attr((string) get_post_meta($id, '_tqk_wpseo_description', true)),
                esc_attr__('1–2 sentences, about 150 characters', 'tqk-wpseo'),
                esc_attr($labels['desc']),
                trim($miss('keyword')),
                esc_attr((string) get_post_meta($id, '_tqk_wpseo_focus_keyword', true)),
                esc_attr($labels['keyword']),
                trim($miss('schema')),
                esc_attr($labels['schema']),
                $schema_opts,
                esc_html__('Save', 'tqk-wpseo')
            );
        }
        $more = count($rows) - 25;
        return '<h2 class="tqk-wpseo-section">' . esc_html(sprintf(__('Quick fix: pages missing SEO basics (%d)', 'tqk-wpseo'), count($rows))) . '</h2>'
            . '<p class="description">' . esc_html__('From the pages already checked. Fill the empty fields and press Save: done here, no need to open each post. Empty SEO title = the title template is used.', 'tqk-wpseo') . '</p>'
            . '<div class="tqk-qe">'
            . '<div class="tqk-qe-head"><span>' . esc_html__('Page', 'tqk-wpseo') . '</span><span>' . esc_html($labels['title']) . '</span><span>' . esc_html($labels['desc']) . '</span><span>' . esc_html($labels['keyword']) . '</span><span>' . esc_html($labels['schema']) . '</span><span></span></div>'
            . $html . '</div>'
            . ($more > 0 ? '<p class="description">' . esc_html(sprintf(__('+%d more page(s): fix these first, the list refreshes after the next audit.', 'tqk-wpseo'), $more)) . '</p>' : '');
    }

    /**
     * Top of the dashboard: the logo, then what the plugin is (tagline,
     * name, one-paragraph pitch, support line). Scores live below, in the
     * site overview.
     */
    private static function hero(): string {
        return '<div class="tqk-wpseo-hero is-intro">'
            . '<span class="tqk-hero-logo" aria-hidden="true">'
            . '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5a1 1 0 0 1 1-1h14"/><path d="M4 19a1 1 0 0 0 1 1h14"/><path d="M9 9l2 2 4-4"/></svg>'
            . '</span>'
            . '<div class="tqk-wpseo-hero-meta">'
            . '<div class="tqk-hero-kicker">Website Audit &amp; Optimization Engine</div>'
            . '<h2>TQK WP SEO</h2>'
            . '<p class="tqk-hero-desc">' . esc_html__('TQK WP SEO checks every page the way a visitor sees it — on desktop, laptop, tablet and phone — turns what it finds into a plan to fix, lets an AI suggest the wording while you approve every change, and keeps the site clean of the malware cracked plugins bring.', 'tqk-wpseo') . '</p>'
            . '<p class="tqk-hero-support">' . esc_html__('Support:', 'tqk-wpseo') . ' <a href="tel:0364999916">0364 999 916</a> · <a href="https://www.facebook.com/Unk.khai/" target="_blank" rel="noopener">facebook.com/Unk.khai</a></p>'
            . '</div>'
            . '</div>';
    }

    /**
     * Never called: lists the plugin header texts so the translation build
     * picks them up (WordPress translates the Plugins screen with them).
     */
    private static function header_strings(): array {
        return [
            __('Website Audit & Optimization Engine. TQK WP SEO checks every page the way a visitor sees it — on desktop, laptop, tablet and phone — turns what it finds into a plan to fix, lets an AI suggest the wording while you approve every change, and keeps the site clean of the malware cracked plugins bring. Support: 0364 999 916.', 'tqk-wpseo'),
        ];
    }

    /**
     * One card per plugin page: what it is for and how it works, with a link.
     * Only pages of active modules are shown (same list as the menu).
     */
    private static function guide_cards(): string {
        $info = [
            'tqk-wpseo-site-analysis' => ['chart-area', __('Audit every page like a visitor: SEO tags, schema, 4 screen sizes, images, malware. Each page gets an action plan; send pages to an AI app over MCP.', 'tqk-wpseo'), __('Pick what to check (pages, 1 sample or all posts, archives), press Start: it runs by itself and resumes if stopped.', 'tqk-wpseo')],
            'tqk-wpseo-modules' => ['admin-plugins', __('Turn features on or off so the plugin only loads what you use.', 'tqk-wpseo'), __('One switch per module; pages of disabled modules disappear from the menu.', 'tqk-wpseo')],
            'tqk-wpseo-sitemap' => ['networking', __('XML sitemap for Google and Bing, with a private address if you want one, and robots.txt.', 'tqk-wpseo'), __('Choose what goes in, then "Submit to Google & Bing" sends it directly.', 'tqk-wpseo')],
            'tqk-wpseo-schema' => ['editor-code', __('Structured data (Organization, WebSite, Article, Product, FAQ, Recipe…) so Google understands the site.', 'tqk-wpseo'), __('Set the site-wide identity here; each post picks its own type in the editor.', 'tqk-wpseo')],
            'tqk-wpseo-redirects' => ['randomize', __('301 / 302 redirects when a URL changes, in one hop.', 'tqk-wpseo'), __('Add old → new address; 404s can be turned into redirects with one click.', 'tqk-wpseo')],
            'tqk-wpseo-404' => ['warning', __('Every "page not found" hit, with how often it happens.', 'tqk-wpseo'), __('Fix the frequent ones with a redirect; noise (bots, .map files) is ignored.', 'tqk-wpseo')],
            'tqk-wpseo-links' => ['admin-links', __('Internal links between your posts, orphan pages nobody links to, and broken links.', 'tqk-wpseo'), __('Rebuild the index, then link the orphans from related posts.', 'tqk-wpseo')],
            'tqk-wpseo-security' => ['shield', __('Malware and SEO-spam scan: core and plugin files against official checksums, backdoors, hidden spam links, cloaking.', 'tqk-wpseo'), __('Runs in small steps so shared hosting never times out; it only reports, never deletes.', 'tqk-wpseo')],
            'tqk-wpseo-settings' => ['admin-settings', __('Title and description templates, social image, Vietnamese options, AI provider, report branding, Pro license.', 'tqk-wpseo'), __('Set once for the whole site; each post can override in its editor.', 'tqk-wpseo')],
            'tqk-wpseo-tools' => ['admin-tools', __('Import from Yoast SEO / Rank Math, MCP connection for Claude / Cursor, settings export, reset.', 'tqk-wpseo'), __('Switch from another SEO plugin in minutes; connect an AI app with the ready-made config.', 'tqk-wpseo')],
        ];
        $cards = '';
        foreach (Menu::items() as $slug => [$title]) {
            if (!isset($info[$slug])) {
                continue;
            }
            [$icon, $what, $how] = $info[$slug];
            $cards .= self::guide_card($icon, $title, $what, $how, admin_url('admin.php?page=' . $slug));
        }
        // Not a menu page, but where most of the daily work happens.
        $cards .= self::guide_card(
            'edit-page',
            __('In each post (TQK SEO box)', 'tqk-wpseo'),
            __('Google and Facebook / Zalo / X previews, title and description with pixel counters, live checklist, Scan of the page, AI sets and AI suggestions to approve.', 'tqk-wpseo'),
            __('Open any post or page: the box sits under the editor; the last audit of that page is shown there.', 'tqk-wpseo'),
            admin_url('edit.php?post_type=page')
        );
        return '<h2 class="tqk-wpseo-section">' . esc_html__('What each page does', 'tqk-wpseo') . '</h2><div class="tqk-guide">' . $cards . '</div>';
    }

    private static function guide_card(string $icon, string $title, string $what, string $how, string $url): string {
        return '<a class="tqk-guide-card" href="' . esc_url($url) . '">'
            . '<span class="tqk-guide-head"><span class="dashicons dashicons-' . esc_attr($icon) . '"></span><strong>' . esc_html($title) . '</strong></span>'
            . '<span class="tqk-guide-what">' . esc_html($what) . '</span>'
            . '<span class="tqk-guide-how">' . esc_html($how) . '</span>'
            . '<span class="tqk-guide-go">' . esc_html__('Open', 'tqk-wpseo') . ' →</span>'
            . '</a>';
    }

    /** Short name of each plan item kind, for the site-wide to-do list. */
    private static function issue_labels(): array {
        return [
            'noindex' => __('Hidden from Google (noindex)', 'tqk-wpseo'),
            'robotsMany' => __('Contradicting robots tags', 'tqk-wpseo'),
            'noTitle' => __('Missing SEO title', 'tqk-wpseo'),
            'noDesc' => __('Missing meta description', 'tqk-wpseo'),
            'longTitle' => __('SEO title too long', 'tqk-wpseo'),
            'longDesc' => __('Meta description too long', 'tqk-wpseo'),
            'noH1' => __('No H1 heading', 'tqk-wpseo'),
            'manyH1' => __('More than one H1', 'tqk-wpseo'),
            'noH2' => __('No H2 headings', 'tqk-wpseo'),
            'noCanon' => __('No canonical', 'tqk-wpseo'),
            'canonOther' => __('Canonical points to another page', 'tqk-wpseo'),
            'redirect' => __('Page redirects', 'tqk-wpseo'),
            'noOg' => __('Share tags (Facebook / Zalo) incomplete', 'tqk-wpseo'),
            'ogSmall' => __('Share image too small', 'tqk-wpseo'),
            'ogRatio' => __('Share image in the wrong ratio', 'tqk-wpseo'),
            'ogBroken' => __('Share image does not load', 'tqk-wpseo'),
            'noSchema' => __('No schema', 'tqk-wpseo'),
            'schemaMiss' => __('Schema missing required data', 'tqk-wpseo'),
            'badJson' => __('Broken schema (JSON-LD)', 'tqk-wpseo'),
            'noAlt' => __('Images without ALT text', 'tqk-wpseo'),
            'thin' => __('Thin content', 'tqk-wpseo'),
            'priceZero' => __('Product price shown as 0', 'tqk-wpseo'),
            'overflow' => __('Page scrolls sideways (tablet / mobile)', 'tqk-wpseo'),
            'smallTap' => __('Buttons / links too small to tap', 'tqk-wpseo'),
            'mainSmall' => __('Text too small to read', 'tqk-wpseo'),
            'bodySmall' => __('Text too small to read', 'tqk-wpseo'),
            'contrast' => __('Text hard to read (low contrast)', 'tqk-wpseo'),
            'hBig' => __('Headings too big on phones', 'tqk-wpseo'),
            'emptyLinks' => __('Links without text', 'tqk-wpseo'),
            'images' => __('Images to resize for each screen', 'tqk-wpseo'),
            'security' => __('Security warnings', 'tqk-wpseo'),
            'unknownScripts' => __('Scripts from unknown domains', 'tqk-wpseo'),
            'checklist' => __('Content checklist not met', 'tqk-wpseo'),
        ];
    }

    /**
     * Site overview from the page reports (Live Audit + editor Scan): pages
     * checked, average SEO, weakest display, AI suggestions waiting,
     * security; then the to-do list across the site, grouped by kind.
     */
    private static function overview_block(): string {
        $analysis = admin_url('admin.php?page=tqk-wpseo-site-analysis');
        $o = \TQK_WPSEO\MCP\Reports::overview();
        $head = '<h2 class="tqk-wpseo-section">' . esc_html__('Site overview', 'tqk-wpseo') . '</h2>';
        if ($o['pages'] === 0) {
            return $head . View::empty_state(
                __('No page checked yet', 'tqk-wpseo'),
                __('Run the Live Audit: every page is opened like a visitor, at 4 screen sizes, and gets its own action plan.', 'tqk-wpseo'),
                View::button(__('Open Analytics website', 'tqk-wpseo'), $analysis)
            );
        }
        $pending = count(\TQK_WPSEO\MCP\Reports::pending());
        $sec = $o['security'];
        $sec_value = $sec['infected'] > 0 ? (string) $sec['infected'] : ($sec['review'] > 0 ? (string) $sec['review'] : '✓');
        $sec_sub = $sec['infected'] > 0 ? __('page(s) with malware signs', 'tqk-wpseo')
            : ($sec['review'] > 0 ? __('page(s) to review', 'tqk-wpseo') : sprintf(__('%d page(s) checked, clean', 'tqk-wpseo'), $sec['clean']));
        $worst = $o['worst'];
        $html = $head . '<div class="tqk-wpseo-stats tqk-overview">'
            . View::stat_card(__('Pages checked', 'tqk-wpseo'), (string) $o['pages'], $o['last'] ? sprintf(__('last on %s', 'tqk-wpseo'), wp_date('d/m/Y H:i', $o['last'])) : '', $analysis, 'pages')
            . View::stat_card(__('Average SEO score', 'tqk-wpseo'), $o['avg_seo'] . '/100', '', $analysis, 'search', $o['avg_seo'] < 50 ? 'is-bad' : ($o['avg_seo'] < 80 ? 'is-warn' : ''))
            . View::stat_card(__('Weakest display', 'tqk-wpseo'), $worst ? $worst['score'] . '/100' : '—', $worst ? (string) $worst['title'] : '', $worst ? (string) get_edit_post_link((int) $worst['id'], 'raw') : $analysis, 'display', $worst && $worst['score'] < 80 ? 'is-warn' : '')
            . View::stat_card(__('AI suggestions to approve', 'tqk-wpseo'), (string) $pending, $pending > 0 ? __('open to review', 'tqk-wpseo') : __('nothing waiting', 'tqk-wpseo'), $analysis, 'ai', $pending > 0 ? 'is-warn' : '')
            . View::stat_card(__('Security', 'tqk-wpseo'), $sec_value, $sec_sub, $analysis, 'shield', $sec['infected'] > 0 ? 'is-bad' : ($sec['review'] > 0 ? 'is-warn' : ''))
            . '</div>';

        $labels = self::issue_labels();
        $rows = '';
        foreach (array_slice($o['issues'], 0, 10) as $code => $i) {
            $label = $labels[$code] ?? (string) $i['title'];
            $ex = implode(', ', array_map(static fn($e) => '<a href="' . esc_url((string) get_edit_post_link((int) $e['id'], 'raw')) . '">' . esc_html((string) $e['title']) . '</a>', $i['examples']));
            $rows .= '<tr><td><span class="tqk-todo-dot is-' . ($i['prio'] === 1 ? 'bad' : 'warn') . '"></span>' . esc_html($label) . '</td>'
                . '<td class="tqk-la-num">' . (int) $i['pages'] . '</td>'
                . '<td class="tqk-todo-ex">' . $ex . ($i['pages'] > count($i['examples']) ? ' …' : '') . '</td></tr>';
        }
        if ($rows === '') {
            return $html . '<div class="tqk-wpseo-allok">✓ ' . esc_html__('Nothing to fix now on the checked pages.', 'tqk-wpseo') . '</div>';
        }
        return $html . '<h2 class="tqk-wpseo-section">' . esc_html__('To do across the site', 'tqk-wpseo') . '</h2>'
            . '<div class="tqk-wpseo-table-wrap"><table class="tqk-wpseo-table tqk-todo"><thead><tr><th>' . esc_html__('Problem', 'tqk-wpseo') . '</th><th class="tqk-la-num">' . esc_html__('Pages', 'tqk-wpseo') . '</th><th>' . esc_html__('For example', 'tqk-wpseo') . '</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    /**
     * Author and donate card, bottom right of the dashboard. Agencies can
     * hide it: add_filter('tqk_wpseo_show_author', '__return_false').
     */
    private static function author_card(): string {
        if (!apply_filters('tqk_wpseo_show_author', true)) {
            return '';
        }
        $row = static fn(string $k, string $v, string $copy = '') => '<div class="tqk-author-row"><span>' . esc_html($k) . '</span><strong>' . esc_html($v) . '</strong>'
            . ($copy !== '' ? '<button type="button" class="button button-small tqk-copy" data-copy="' . esc_attr($copy) . '">' . esc_html__('Copy', 'tqk-wpseo') . '</button>' : '') . '</div>';
        return '<div class="tqk-author">'
            . '<div class="tqk-author-col">'
            . '<div class="tqk-author-title">' . esc_html__('Contact', 'tqk-wpseo') . '</div>'
            . '<div class="tqk-author-name">' . esc_html__('TQK WP SEO — made by', 'tqk-wpseo') . ' <strong>Trương Quang Khải</strong></div>'
            . '<div class="tqk-author-links"><a href="tel:0364999916">0364 999 916</a><a href="mailto:roo.adm@gmail.com">roo.adm@gmail.com</a><a href="https://www.facebook.com/Unk.khai/" target="_blank" rel="noopener">facebook.com/Unk.khai</a><a href="https://conggame.online" target="_blank" rel="noopener">conggame.online</a></div>'
            . '</div>'
            . '<div class="tqk-author-col tqk-donate">'
            . '<div class="tqk-author-title">' . esc_html__('Support / Donate', 'tqk-wpseo') . '</div>'
            . $row(__('Bank', 'tqk-wpseo'), 'Vietcombank')
            . $row(__('Account number', 'tqk-wpseo'), '0501 000 151853', '0501000151853')
            . $row(__('Account holder', 'tqk-wpseo'), 'Truong Quang Khai')
            . $row(__('Branch', 'tqk-wpseo'), 'Củ Chi')
            . $row('SWIFT/BIC', 'BFTVVNVX', 'BFTVVNVX')
            . '</div>'
            . '</div>';
    }

    /** @param array $issues Site-level snapshot from Scanner::site_issues(). Single-place fix, no page jumps. */
    private static function site_block(array $issues): string {
        if ($issues === []) {
            return '<div class="tqk-wpseo-allok">✓ ' . esc_html__('Site basics OK (sitemap · robots · schema).', 'tqk-wpseo') . '</div>';
        }
        $rows = [];
        foreach ($issues as $f) {
            $mark = ($f['severity'] ?? '') === 'critical' ? '✗' : '⚠';
            $fix = (string) ($f['fix'] ?? '');
            $action = match ($fix) {
                'sitemap' => sprintf('<a class="tqk-btn tqk-btn-outline tqk-btn-sm" href="%s">%s</a>', esc_url(admin_url('admin.php?page=tqk-wpseo-sitemap')), esc_html__('Open Sitemap', 'tqk-wpseo')),
                'robots' => sprintf('<a class="tqk-btn tqk-btn-outline tqk-btn-sm" href="%s">%s</a>', esc_url(admin_url('admin.php?page=tqk-wpseo-sitemap')), esc_html__('Edit robots.txt', 'tqk-wpseo')),
                'schema' => sprintf('<a class="tqk-btn tqk-btn-outline tqk-btn-sm" href="%s">%s</a>', esc_url(admin_url('admin.php?page=tqk-wpseo-settings')), esc_html__('Edit Schema', 'tqk-wpseo')),
                default => '',
            };
            $rows[] = [trim($mark . ' ' . ($f['message'] ?? '')), (string) ($f['group'] ?? ''), $action];
        }
        return View::issue_table([__('Issue', 'tqk-wpseo'), __('Group', 'tqk-wpseo'), ''], $rows)
            . '<p class="description">' . esc_html__('Saved here once per scan. Re-audit shows the same list until you fix — no rescan needed.', 'tqk-wpseo') . '</p>';
    }

    /** @return string Checklist HTML with real S0-computable checks. */
    public static function setup_checklist(): string {
        global $wpdb, $wp_version;

        $settings_ok = get_option(TQK_WPSEO_OPTION) !== false;
        $tables_ok = true;
        foreach (\TQK_WPSEO\Core\Activator::table_names($wpdb->prefix) as $table) {
            // phpcs:ignore -- table name from internal allowlist.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $tables_ok = false;
                break;
            }
        }

        $pretty = (string) get_option('permalink_structure', '');

        $items = [
            [$settings_ok, __('Settings initialized', 'tqk-wpseo'), ''],
            [$tables_ok, __('Database tables created', 'tqk-wpseo'), $tables_ok ? '' : admin_url('admin.php?page=tqk-wpseo-tools')],
            [version_compare(PHP_VERSION, '8.0', '>='), sprintf(__('PHP %s (requires 8.0+)', 'tqk-wpseo'), PHP_VERSION), ''],
            [version_compare((string) $wp_version, '6.0', '>='), sprintf(__('WordPress %s (requires 6.0+)', 'tqk-wpseo'), $wp_version), ''],
            [$pretty !== '', __('Pretty permalinks enabled', 'tqk-wpseo'), $pretty !== '' ? '' : admin_url('options-permalink.php')],
            [
                (bool) \TQK_WPSEO\Admin\Settings::get('sitemap_enabled', true),
                __('XML Sitemap ready', 'tqk-wpseo'),
                admin_url('admin.php?page=tqk-wpseo-sitemap'),
            ],
        ];

        $html = '<ul class="tqk-wpseo-checklist">';
        foreach ($items as [$state, $label, $url]) {
            $badge = $state === null
                ? '<span class="tqk-wpseo-badge is-wait">…</span>'
                : ($state
                    ? '<span class="tqk-wpseo-badge is-ok">✓</span>'
                    : '<span class="tqk-wpseo-badge is-bad">!</span>');
            $text = $url !== '' && !$state
                ? sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($label))
                : esc_html($label);
            $html .= sprintf('<li>%s %s</li>', $badge, $text);
        }
        return $html . '</ul>';
    }

    private static function recent_scans(): string {
        $history = Scanner::history();
        if ($history === []) {
            return View::empty_state(
                __('No scans yet', 'tqk-wpseo'),
                __('Scan history and the trend versus your last run will appear here.', 'tqk-wpseo')
            );
        }
        $rows = [];
        foreach ($history as $h) {
            $rows[] = [
                (string) ($h['finished_at'] ?? ''),
                sprintf(__('Health %d · Critical %d · Warnings %d', 'tqk-wpseo'), (int) ($h['health'] ?? 0), (int) ($h['critical'] ?? 0), (int) ($h['warnings'] ?? 0)),
                '',
            ];
        }
        return View::issue_table([__('Finished', 'tqk-wpseo'), __('Result', 'tqk-wpseo'), ''], $rows);
    }

    // ---------- Modules ----------

    public static function modules(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $groups = [
            __('Core', 'tqk-wpseo') => ['sitemap', 'schema', 'analyzer', 'scanner', 'analytics'],
            __('Technical', 'tqk-wpseo') => ['redirects', 'monitor-404', 'links', 'image-seo', 'role-manager', 'instant-indexing'],
            __('Advanced', 'tqk-wpseo') => ['local-seo', 'woo', 'ai', 'mcp'],
        ];
        $all = \TQK_WPSEO\Core\Modules::all();
        $content = '<p>' . esc_html__('Toggle feature sets. Disabled modules unload their hooks and hide their menu pages. Scanner needs Analyzer.', 'tqk-wpseo') . '</p>';
        foreach ($groups as $glabel => $slugs) {
            $cards = '';
            foreach ($slugs as $slug) {
                if (!isset($all[$slug])) {
                    continue;
                }
                [$title, $desc, $default] = $all[$slug];
                $on = \TQK_WPSEO\Core\Modules::is_active($slug);
                $badge = $on
                    ? '<span class="tqk-wpseo-ptype" style="background:#d1fae5;border-color:#a7f3d0;color:#059669">ON</span>'
                    : '<span class="tqk-wpseo-ptype">OFF</span>';
                $cards .= sprintf(
                    '<div class="tqk-wpseo-module-card"><div><strong>%s</strong> %s<p>%s</p></div>'
                    . '<label class="tqk-wpseo-switch"><input type="checkbox" data-tqk-module="%s"%s>'
                    . '<span class="tqk-wpseo-track"><span class="tqk-wpseo-thumb"></span></span></label></div>',
                    esc_html($title),
                    $badge,
                    esc_html($desc),
                    esc_attr($slug),
                    $on ? ' checked' : ''
                );
            }
            $content .= '<h2 class="tqk-wpseo-section">' . esc_html($glabel) . '</h2>'
                . '<div class="tqk-wpseo-modules">' . $cards . '</div>';
        }
        View::page(__('Modules', 'tqk-wpseo'), $content);
    }

    // ---------- Site Analysis ----------

    public static function site_analysis(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $job = get_option(Scanner::JOB_OPTION, []);
        $running = is_array($job) && ($job['status'] ?? '') === 'running';
        $s = array_merge(\TQK_WPSEO\Core\Activator::default_settings(), (array) get_option(TQK_WPSEO_OPTION, []));
        $st = \TQK_WPSEO\Google\SearchConsole::status();
        $badge = $st['connected']
            ? '<span class="tqk-wpseo-ptype" style="background:#d1fae5;border-color:#a7f3d0;color:#059669">Connected</span>'
            : '<span class="tqk-wpseo-ptype">Not connected</span>';
        $content = '<h2 class="tqk-wpseo-section">' . esc_html__('Google Search Console (standard)', 'tqk-wpseo') . ' ' . $badge . '</h2>'
            . '<div class="tqk-wpseo-formcard" style="max-width:none"><p class="desc">'
            . esc_html__('Connect once, Sync for clicks/impressions/CTR/position (28d). Free quota, no rank SaaS. Property format: sc-domain:example.com or https://example.com/', 'tqk-wpseo')
            . '</p>'
            . '<form data-tqk-form="gsc" class="tqk-wpseo-inline-form">'
            . sprintf('<label><strong>%s</strong><br><input type="text" name="settings[gsc_property]" value="%s" size="26" placeholder="sc-domain:example.com"></label> ', esc_html__('Property', 'tqk-wpseo'), esc_attr((string) ($s['gsc_property'] ?? '')))
            . sprintf('<label><strong>%s</strong><br><input type="password" autocomplete="off" name="settings[gsc_api_key]" value="%s" size="20"></label> ', esc_html__('API key (optional)', 'tqk-wpseo'), esc_attr((string) ($s['gsc_api_key'] ?? '')))
            . sprintf('<label><strong>%s</strong><br><input type="text" name="settings[gsc_token]" value="%s" size="26" placeholder="ya29..."></label> ', esc_html__('OAuth token', 'tqk-wpseo'), esc_attr((string) ($s['gsc_token'] ?? '')))
            . sprintf('<button type="submit" class="button button-primary">%s</button> ', esc_html__('Save', 'tqk-wpseo'))
            . sprintf('<button type="button" class="button" data-tqk-gsc-sync>%s</button>', esc_html__('Sync now', 'tqk-wpseo'))
            . ' <span id="tqk-gsc-status"></span></form>'
            . '<p class="description">' . esc_html__('Get token: Google Cloud Console → Enable Search Console API → OAuth consent → Desktop app → copy token. Or paste export later.', 'tqk-wpseo') . '</p>'
            . '<div id="tqk-gsc-table">' . \TQK_WPSEO\Google\SearchConsole::table_html() . '</div>'
            . self::gsc_history_card()
            . '</div>'
            . '<div class="tqk-wpseo-run" id="tqk-wpseo-scan" data-running="' . ($running ? '1' : '0') . '" data-nonce="' . esc_attr(wp_create_nonce('tqk-wpseo-scan')) . '">'
            . '<p>' . esc_html__('Scans all public post types in batches of 50 (posts, pages, CPTs), plus site checks for sitemap · robots · schema. Large sites never time out.', 'tqk-wpseo') . '</p>'
            . '<p><label><input type="radio" name="tqk-scan-mode" value="full" checked> ' . esc_html__('Full — every page', 'tqk-wpseo') . '</label> '
            . '<label><input type="radio" name="tqk-scan-mode" value="sample"> ' . esc_html__('Sample — one page per post type', 'tqk-wpseo') . '</label></p>'
            . '<p><button class="tqk-btn tqk-btn-primary" id="tqk-wpseo-scan-start">' . esc_html__('Run SEO Scan', 'tqk-wpseo') . '</button></p>'
            . '<div class="tqk-wpseo-score"><div class="tqk-wpseo-score-track"><div class="tqk-wpseo-score-fill" id="tqk-wpseo-scan-fill" style="width:0%"></div></div>'
            . '<p id="tqk-wpseo-scan-label"></p></div>'
            . '</div>'
            . self::schema_quick()
            . '<h2 class="tqk-wpseo-section">' . esc_html__('Latest result', 'tqk-wpseo') . '</h2>'
            . '<div id="tqk-wpseo-latest">' . self::recent_scans() . '</div>'
            . '<h2 class="tqk-wpseo-section">' . esc_html__('Page Audit (bot view)', 'tqk-wpseo') . '</h2>'
            . '<p>' . esc_html__('Fetches the page exactly like an outside bot (shortcodes and builders fully rendered), then audits headings, keyword, meta, social, schema and every image. Fix in one go below — no page jumps.', 'tqk-wpseo') . '</p>'
            . '<p><select id="tqk-audit-pick">'
            . self::audit_options()
            . '</select> '
            . '<button type="button" class="button button-primary" id="tqk-audit-run">' . esc_html__('Audit page', 'tqk-wpseo') . '</button></p>'
            . '<div id="tqk-audit-result"></div>'
            . self::ai_pending_card()
            . self::responsive_card()
            . self::image_card()
            . self::advisor_card()
            . self::bulk_ai_card();
        View::page(__('Analytics website', 'tqk-wpseo'), $content);
    }

    /**
     * Live Audit: the browser opens each queued page in a real iframe, checks
     * tags + schema + fields, measures 1440/1280/768/375, snapshots the full
     * page, then moves to the next page by itself. The full list is taken at
     * start and every page is marked done/failed as it finishes, so a run of
     * many hours can be stopped, crash, or be resumed from another session.
     */
    private static function responsive_card(): string {
        $types = \TQK_WPSEO\Analyzer\LiveAudit::types();
        if ($types === []) {
            return '';
        }
        $run = \TQK_WPSEO\Analyzer\LiveAudit::run();
        $state = $run === [] ? null : \TQK_WPSEO\Analyzer\LiveAudit::state($run);

        // Scope table: pages first (all of them, free), then every post type
        // with its taxonomies: skip / 1 sample post / all posts (Pro).
        $pro = \TQK_WPSEO\Core\Pro::active();
        $home = get_option('show_on_front') !== 'page' ? 1 : 0;
        $scope = '';
        foreach ($types as $pt => $t) {
            $tax = implode(' · ', array_map(static fn($x) => $x['label'] . ' (' . (int) $x['count'] . ')', (array) ($t['taxonomies'] ?? [])));
            $name = '<strong>' . esc_html((string) $t['plural']) . '</strong> <code>' . esc_html($pt) . '</code>'
                . ($tax !== '' ? '<span class="tqk-la-tax">' . esc_html($tax) . '</span>' : '');
            if ($pt === 'page') {
                $ctl = sprintf(
                    '<label class="tqk-check"><input type="checkbox" id="tqk-la-pages" checked data-count="%d"><span>%s</span></label>',
                    (int) $t['count'] + $home,
                    esc_html(sprintf(__('Audit every page (%d)', 'tqk-wpseo'), (int) $t['count'] + $home))
                );
            } else {
                $opt = static fn(string $v, string $label, bool $on, bool $off) => sprintf(
                    '<label%s><input type="radio" name="tqk-la-scope-%s" value="%s" data-type="%s" data-count="%d"%s%s><span>%s</span></label>',
                    $off ? ' class="is-locked"' : '',
                    esc_attr($pt),
                    $v,
                    esc_attr($pt),
                    $v === 'all' ? (int) $t['count'] : ($v === 'one' ? 1 : 0),
                    $on ? ' checked' : '',
                    $off ? ' disabled' : '',
                    $label
                );
                $ctl = '<span class="tqk-seg">'
                    . $opt('none', esc_html__('Skip', 'tqk-wpseo'), false, false)
                    . $opt('one', esc_html__('1 sample post', 'tqk-wpseo'), true, false)
                    . $opt('all', esc_html(sprintf(__('All %d', 'tqk-wpseo'), (int) $t['count'])) . ($pro ? '' : ' <span class="tqk-la-pro-badge">PRO</span>'), false, !$pro)
                    . '</span>'
                    . ($pro ? '' : '<span class="tqk-la-pro">' . esc_html(sprintf(__('Upgrade to Pro to audit every %s.', 'tqk-wpseo'), mb_strtolower((string) $t['plural']))) . '</span>');
            }
            $scope .= '<tr><th scope="row">' . $name . '</th><td class="tqk-la-num">' . (int) $t['count'] . '</td><td>' . $ctl . '</td></tr>';
        }
        // Taxonomy archives (category / tag / product category pages).
        $taxes = \TQK_WPSEO\Analyzer\LiveAudit::taxonomies();
        if ($taxes !== []) {
            $scope .= '<tr class="tqk-la-group"><th colspan="3">' . esc_html__('Archive pages (taxonomies)', 'tqk-wpseo') . '</th></tr>';
        }
        foreach ($taxes as $tax => $x) {
            $key = 'tax:' . $tax;
            $opt = static fn(string $v, string $label, bool $on, bool $off) => sprintf(
                '<label%s><input type="radio" name="tqk-la-scope-%s" value="%s" data-type="%s" data-count="%d"%s%s><span>%s</span></label>',
                $off ? ' class="is-locked"' : '',
                esc_attr(str_replace(':', '-', $key)),
                $v,
                esc_attr($key),
                $v === 'all' ? (int) $x['count'] : ($v === 'one' ? 1 : 0),
                $on ? ' checked' : '',
                $off ? ' disabled' : '',
                $label
            );
            $scope .= '<tr><th scope="row"><strong>' . esc_html($x['label']) . '</strong> <code>' . esc_html($tax) . '</code>'
                . '<span class="tqk-la-tax">' . esc_html(sprintf(__('Archive pages of %s', 'tqk-wpseo'), $x['for'])) . '</span></th>'
                . '<td class="tqk-la-num">' . (int) $x['count'] . '</td><td><span class="tqk-seg">'
                . $opt('none', esc_html__('Skip', 'tqk-wpseo'), false, false)
                . $opt('one', esc_html__('1 sample page', 'tqk-wpseo'), true, false)
                . $opt('all', esc_html(sprintf(__('All %d', 'tqk-wpseo'), (int) $x['count'])) . ($pro ? '' : ' <span class="tqk-la-pro-badge">PRO</span>'), false, !$pro)
                . '</span>' . ($pro ? '' : '<span class="tqk-la-pro">' . esc_html(sprintf(__('Upgrade to Pro to audit every %s page.', 'tqk-wpseo'), mb_strtolower($x['label']))) . '</span>')
                . '</td></tr>';
        }
        $scope = '<table class="tqk-la-scope"><thead><tr><th>' . esc_html__('Content', 'tqk-wpseo') . '</th><th class="tqk-la-num">' . esc_html__('Published', 'tqk-wpseo') . '</th><th>' . esc_html__('Audit', 'tqk-wpseo') . '</th></tr></thead><tbody>' . $scope . '</tbody></table>';

        $report = '';
        $pager = '';
        if ($run !== []) {
            $rows = \TQK_WPSEO\Analyzer\LiveAudit::report_rows($run);
            $report = $rows['html'];
            $pager = (int) $rows['pages'] > 1 ? (string) (int) $rows['pages'] : '';
        }

        $t = [
            'start' => __('Starting…', 'tqk-wpseo'),
            'auditing' => __('Auditing %1$s — %2$s', 'tqk-wpseo'),
            'snapshot' => __('Snapshot %s…', 'tqk-wpseo'),
            'saving' => __('Saving…', 'tqk-wpseo'),
            'progress' => __('%1$d / %2$d done · %3$d failed · %4$d waiting', 'tqk-wpseo'),
            'finished' => __('Finished: %1$d done, %2$d failed.', 'tqk-wpseo'),
            'paused' => __('Paused. Press Continue to resume from the next unfinished page.', 'tqk-wpseo'),
            'stopping' => __('Stopping after this page…', 'tqk-wpseo'),
            'netRetry' => __('Connection lost, retrying in %d s…', 'tqk-wpseo'),
            'confirmNew' => __('Start a new run? The current run and its snapshots will be replaced.', 'tqk-wpseo'),
            'leave' => __('An audit is running. Leave anyway? You can continue it later.', 'tqk-wpseo'),
            'needType' => __('Choose at least one thing to audit.', 'tqk-wpseo'),
            'scopeHint' => __('%d page(s) will be audited.', 'tqk-wpseo'),
            'scopeSlow' => __('%1$d page(s) will be audited — about %2$s. It runs by itself and resumes if stopped.', 'tqk-wpseo'),
            'noLoad' => __('Page did not load within %d s.', 'tqk-wpseo'),
            'blocked' => __('Page cannot be opened in a frame (X-Frame-Options / CSP).', 'tqk-wpseo'),
            'expired' => __('Your login session expired. Reload this page and press Continue: finished pages are kept.', 'tqk-wpseo'),
            'stage' => self::stage_strings(),
            'hiddenTab' => __('This tab is in the background, so the browser slows the audit down. Keep it visible (its own window is fine).', 'tqk-wpseo'),
            'seo' => self::seo_strings(),
            'plan' => Metabox::plan_strings(),
            'security' => __('Security check…', 'tqk-wpseo'),
            'pushed' => __('Sent', 'tqk-wpseo'),
        ];
        $mcp = \TQK_WPSEO\Core\Modules::is_active('mcp');
        $data = [
            'state' => $state,
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'ajax' => admin_url('admin-ajax.php'),
            't' => $t,
        ];

        $has_run = $state !== null;
        $resumable = $has_run && $state['pending'] > 0;
        $failed = $has_run && $state['failed'] > 0;

        return '<h2 class="tqk-wpseo-section">' . esc_html__('Live Audit (SEO + schema + responsive + snapshot)', 'tqk-wpseo') . '</h2>'
            . '<div id="tqk-la" class="tqk-la">'
            . '<p>' . esc_html__('Opens every page like a visitor, checks tags, schema and fields, measures 4 screen sizes, snapshots the full page (desktop + mobile), then moves to the next page by itself. The full list is taken at start and each page is marked done as it finishes: stop, close the tab or lose the connection at any time and Continue picks up at the next unfinished page.', 'tqk-wpseo') . '</p>'
            . '<p class="description">' . esc_html__('Pages are each built by hand, so every one is checked. Posts, products and other types share a template: one sample post already shows whether its SEO tags and schema are right.', 'tqk-wpseo') . '</p>'
            . $scope
            . '<div class="tqk-la-opts">'
            . '<label class="tqk-check"><input type="checkbox" id="tqk-la-snap" checked><span>' . esc_html__('Save full-page snapshots (desktop + mobile)', 'tqk-wpseo') . '</span></label>'
            . '<label class="tqk-check"><input type="checkbox" id="tqk-la-sec" checked><span>' . esc_html__('Malware check of each page (as a visitor, from Google, as Googlebot) — adds about 10 seconds per page', 'tqk-wpseo') . '</span></label>'
            . '</div>'
            . '<p class="tqk-la-hint" id="tqk-la-hint" aria-live="polite"></p>'
            . '<p class="tqk-la-buttons">'
            . '<button type="button" class="button button-primary" id="tqk-la-start">' . esc_html__('Start new audit', 'tqk-wpseo') . '</button> '
            . '<button type="button" class="button" id="tqk-la-continue"' . ($resumable ? '' : ' hidden') . '>' . esc_html__('Continue', 'tqk-wpseo') . '</button> '
            . '<button type="button" class="button" id="tqk-la-retry"' . ($failed ? '' : ' hidden') . '>' . esc_html__('Retry failed pages', 'tqk-wpseo') . '</button> '
            . '<button type="button" class="button" id="tqk-la-stop" hidden>' . esc_html__('Stop', 'tqk-wpseo') . '</button> '
            . ($has_run && $state['done'] > 0 ? '<a class="button" target="_blank" rel="noopener" href="' . esc_url(\TQK_WPSEO\Report\Report::url()) . '">' . esc_html__('Client report (PDF)', 'tqk-wpseo') . '</a> ' : '')
            . ($has_run && $state['done'] > 0 && $mcp ? '<button type="button" class="button" id="tqk-la-push-all">' . esc_html__('Push all pages with issues to MCP', 'tqk-wpseo') . '</button>' : '')
            . '</p>'
            . '<div class="tqk-rs-bar"><div id="tqk-la-fill"></div></div>'
            . '<p id="tqk-la-status" aria-live="polite"></p>'
            . \TQK_WPSEO\Analyzer\LiveAudit::table_open() . $report . \TQK_WPSEO\Analyzer\LiveAudit::table_close()
            . '<p id="tqk-la-pager" data-pages="' . esc_attr($pager) . '"></p>'
            . '<script type="application/json" id="tqk-la-data">' . wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
            . '</div>';
    }

    /**
     * Image optimizer: per page of the last Live Audit, images measured at 4
     * screens → resize / crop-to-visible / compress / WebP into
     * uploads/audit-pics/{id}-page/, served through core srcset once activated.
     */
    private static function image_card(): string {
        $pages = \TQK_WPSEO\Analyzer\ImageFix::pages();
        $site = \TQK_WPSEO\Analyzer\ImageFix::rows('site');
        if ($pages === [] && $site === []) {
            return '';
        }
        $html = $site !== [] ? \TQK_WPSEO\Analyzer\ImageFix::page_html('site') : '';
        foreach (array_keys($pages) as $id) {
            $html .= \TQK_WPSEO\Analyzer\ImageFix::page_html((int) $id);
        }
        $webp = function_exists('wp_image_editor_supports') && wp_image_editor_supports(['mime_type' => 'image/webp']);
        $pro = \TQK_WPSEO\Core\Pro::active();
        $conflicts = \TQK_WPSEO\Analyzer\ImageFix::conflicts();
        return '<h2 class="tqk-wpseo-section">' . esc_html__('Image optimizer (from Live Audit)', 'tqk-wpseo') . ($pro ? '' : ' <span class="tqk-rs-badge">Pro</span>') . '</h2>'
            . '<div id="tqk-img" data-nonce="' . esc_attr(wp_create_nonce(Ajax::NONCE)) . '" data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '">'
            . '<p>' . esc_html__('Only images the audit confirmed as a problem are listed. Sizes come from the real boxes measured at 1440 / 1280 / 768 / 375 px (1x and 2x).', 'tqk-wpseo') . '</p>'
            . '<p class="description">' . ($pro
                ? esc_html__('Generate writes resized, compressed files to uploads/audit-pics/{id}-page/ (original uploads are never touched); Activate serves them through WordPress srcset, so it works with any builder.', 'tqk-wpseo') . ' '
                    . ($webp ? esc_html__('Output: WebP, quality 78.', 'tqk-wpseo') : esc_html__('This server cannot write WebP (no Imagick/GD WebP support): files keep their original format, compressed.', 'tqk-wpseo'))
                : esc_html__('Free: each image lists the exact manual steps. Pro: the same steps done automatically with one click.', 'tqk-wpseo')) . '</p>'
            . ($conflicts !== [] ? '<p class="notice notice-warning inline" style="padding:8px 12px">' . esc_html(sprintf(
                __('%s is active and may also resize, convert or rewrite images. Use one image optimizer only, or turn off its WebP / srcset rewriting before activating ours.', 'tqk-wpseo'),
                implode(', ', $conflicts)
            )) . '</p>' : '')
            . $html . '</div>';
    }

    /**
     * AI Advisor (Gemini Flash): suggestions for every page the last Live
     * Audit flagged, paced for the free tier, preview → tick → apply.
     */
    private static function advisor_card(): string {
        if (!\TQK_WPSEO\Core\Modules::is_active('ai')) {
            return '';
        }
        $provider = (string) \TQK_WPSEO\Admin\Settings::get('ai_provider', 'none');
        $run = \TQK_WPSEO\AI\Advisor::run();
        $state = $run === [] ? null : \TQK_WPSEO\AI\Advisor::state($run);
        $n = count(\TQK_WPSEO\AI\Advisor::candidates());
        $rows = '';
        if ($run !== []) {
            $st = (string) ($run['st'] ?? '');
            foreach ((array) ($run['ids'] ?? []) as $i => $id) {
                if (($st[$i] ?? '') === \TQK_WPSEO\AI\Advisor::DONE) {
                    $rows .= \TQK_WPSEO\AI\Advisor::row_html((int) $id);
                }
            }
        }
        $t = [
            'waiting' => __('Waiting %d s (rate limit)…', 'tqk-wpseo'),
            'daily' => __('Daily quota used up. Resumes after %s — press Continue then.', 'tqk-wpseo'),
            'working' => __('Asking Gemini…', 'tqk-wpseo'),
            'progress' => __('%1$d / %2$d pages · %3$d failed · %4$d requests · %5$d from cache · today %6$d/%7$d', 'tqk-wpseo'),
            'finished' => __('Finished.', 'tqk-wpseo'),
            'stopped' => __('Stopped. Continue picks up the next page.', 'tqk-wpseo'),
            'applied' => __('Applied.', 'tqk-wpseo'),
            'expired' => __('Your login session expired. Reload this page and press Continue.', 'tqk-wpseo'),
        ];
        $data = ['state' => $state, 'nonce' => wp_create_nonce(Ajax::NONCE), 'ajax' => admin_url('admin-ajax.php'), 't' => $t];
        $ready = $provider === 'gemini' && (string) \TQK_WPSEO\Admin\Settings::get('gemini_api_key', '') !== '';

        return '<h2 class="tqk-wpseo-section">' . esc_html__('AI Advisor (Gemini Flash)', 'tqk-wpseo') . '</h2>'
            . '<div id="tqk-ai" class="tqk-la">'
            . '<p>' . esc_html(sprintf(__('Suggests titles, descriptions, focus keywords, image ALT and missing schema fields for the %d page(s) the last Live Audit flagged. Pages are sent 3 per request and spaced to your per-minute and per-day limits; unchanged pages reuse their cached suggestion. Nothing is written until you tick and apply.', 'tqk-wpseo'), $n)) . '</p>'
            . ($ready ? '' : '<p class="notice notice-warning inline" style="padding:8px 12px">' . sprintf(
                esc_html__('Choose Google Gemini and paste an API key in %s first.', 'tqk-wpseo'),
                '<a href="' . esc_url(admin_url('admin.php?page=tqk-wpseo-settings')) . '">' . esc_html__('Settings', 'tqk-wpseo') . '</a>'
            ) . '</p>')
            . '<p class="tqk-la-buttons">'
            . '<button type="button" class="button button-primary" id="tqk-ai-start"' . ($ready && $n > 0 ? '' : ' disabled') . '>' . esc_html__('Start suggestions', 'tqk-wpseo') . '</button> '
            . '<button type="button" class="button" id="tqk-ai-continue"' . ($state && $state['pending'] > 0 ? '' : ' hidden') . '>' . esc_html__('Continue', 'tqk-wpseo') . '</button> '
            . '<button type="button" class="button" id="tqk-ai-retry"' . ($state && $state['failed'] > 0 ? '' : ' hidden') . '>' . esc_html__('Retry failed pages', 'tqk-wpseo') . '</button> '
            . '<button type="button" class="button" id="tqk-ai-stop" hidden>' . esc_html__('Stop', 'tqk-wpseo') . '</button>'
            . '</p>'
            . '<div class="tqk-rs-bar"><div id="tqk-ai-fill"></div></div>'
            . '<p id="tqk-ai-status" aria-live="polite"></p>'
            . '<div id="tqk-ai-rows" class="tqk-ai-rows">' . $rows . '</div>'
            . '<script type="application/json" id="tqk-ai-data">' . wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
            . '</div>';
    }

    /** Bulk AI (local): generate titles/descriptions for pages missing them, preview → apply. */
    private static function bulk_ai_card(): string {
        if (!\TQK_WPSEO\Core\Modules::is_active('ai')) {
            return '';
        }
        $summary = Scanner::summary();
        $cands = [];
        foreach ((array) ($summary['pages'] ?? []) as $p) {
            $ids = array_column((array) ($p['findings'] ?? []), 'id');
            if (in_array('title_missing', $ids, true) || in_array('description_missing', $ids, true)) {
                $cands[] = $p;
            }
            if (count($cands) >= 10) {
                break;
            }
        }
        if ($cands === []) {
            return '<h2 class="tqk-wpseo-section">' . esc_html__('Bulk AI (local)', 'tqk-wpseo') . '</h2>'
                . '<p>' . esc_html__('No pages missing titles or descriptions. Run a scan first.', 'tqk-wpseo') . '</p>';
        }
        $rows = '';
        foreach ($cands as $p) {
            $oid = (int) ($p['object_id'] ?? 0);
            $rows .= sprintf(
                '<label class="tqk-wpseo-switch"><input type="checkbox" data-tqk-bulk-ai="%d" checked><span class="tqk-wpseo-track"><span class="tqk-wpseo-thumb"></span></span><span>%s</span></label>',
                $oid,
                esc_html((string) ($p['title'] ?? '') . ' #' . $oid)
            );
        }
        return '<h2 class="tqk-wpseo-section">' . esc_html__('Bulk AI (local)', 'tqk-wpseo') . '</h2>'
            . '<div class="tqk-wpseo-formcard" style="max-width:none"><p class="desc">'
            . esc_html__('Local LLM writes titles/descriptions for checked pages (max 10). Preview first — Apply saves via the normal bulk path.', 'tqk-wpseo')
            . '</p><p><select id="tqk-bulk-ai-type">'
            . '<option value="title">' . esc_html__('Titles', 'tqk-wpseo') . '</option>'
            . '<option value="description">' . esc_html__('Descriptions', 'tqk-wpseo') . '</option>'
            . '</select> <button type="button" class="button button-primary" data-tqk-bulk-ai-go data-nonce="' . esc_attr(wp_create_nonce('tqk-wpseo-ai')) . '">' . esc_html__('Generate', 'tqk-wpseo') . '</button> '
            . '<button type="button" class="button" data-tqk-bulk-ai-apply hidden>' . esc_html__('Apply selected', 'tqk-wpseo') . '</button></p>'
            . $rows
            . '<div id="tqk-bulk-ai-out"></div></div>';
    }

    /** Rank Tracker (local): per-query history from daily snapshots. */
    private static function gsc_history_card(): string {
        $opts = '<option value="">' . esc_html__('Pick a query…', 'tqk-wpseo') . '</option>';
        foreach (\TQK_WPSEO\Google\SearchConsole::queries() as $q) {
            $opts .= sprintf('<option value="%s">%s</option>', esc_attr($q), esc_html($q));
        }
        return '<h4>' . esc_html__('Rank history (local tracker)', 'tqk-wpseo') . '</h4>'
            . '<p class="description">' . esc_html__('Every Sync snapshots positions. Daily cron keeps history while connected — no SaaS.', 'tqk-wpseo') . '</p>'
            . '<p><select id="tqk-gsc-q">' . $opts . '</select></p>'
            . '<div id="tqk-gsc-hist"></div>';
    }

    /**
     * Renders a stored secret as a mask. The plaintext is never sent to the
     * browser; an empty submission keeps whatever is stored at rest.
     */
    private static function masked_secret(string $stored): string {
        return \TQK_WPSEO\Core\Secrets::decrypt($stored) === '' ? '' : '********';
    }

    /** One-go schema templates for all public post types — fix missing schema here. */
    private static function schema_quick(): string {
        $s = array_merge(\TQK_WPSEO\Core\Activator::default_settings(), (array) get_option(TQK_WPSEO_OPTION, []));
        $map = is_array($s['schema_map'] ?? null) ? $s['schema_map'] : [];
        $types = ['Article' => 'Article', 'BlogPosting' => 'BlogPosting', 'WebPage' => 'WebPage', 'Product' => 'Product', 'Course' => 'Course', 'FAQPage' => 'FAQPage', 'HowTo' => 'HowTo', 'Recipe' => 'Recipe', 'Event' => 'Event'];
        $pts = function_exists('get_post_types') ? array_keys((array) get_post_types(['public' => true])) : ['post', 'page'];
        $pts = array_values(array_unique(array_merge(['post', 'page', 'product'], $pts)));
        $html = '<h2 class="tqk-wpseo-section">' . esc_html__('Schema per post type (fix missing in one go)', 'tqk-wpseo') . '</h2>'
            . '<form data-tqk-form="settings" class="tqk-wpseo-inline-form">';
        foreach ($pts as $pt) {
            $pto = function_exists('get_post_type_object') ? get_post_type_object($pt) : null;
            $label = $pto && isset($pto->labels->singular_name) ? (string) $pto->labels->singular_name : ucfirst($pt);
            $opts = '';
            foreach ($types as $v => $l) {
                $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($v), ((string) ($map[$pt] ?? '') === $v) ? ' selected' : '', esc_html($l));
            }
            $html .= sprintf('<label><strong>%s</strong><br><select name="settings[schema_map][%s]">%s</select></label>', esc_html($label), esc_attr($pt), $opts);
        }
        $html .= sprintf('<button type="submit" class="button button-primary">%s</button></form>', esc_html__('Save schema', 'tqk-wpseo'));
        return $html;
    }

    private static function audit_options(): string {
        $pts = function_exists('get_post_types') ? array_values((array) get_post_types(['public' => true])) : ['post', 'page'];
        $pts = array_values(array_unique(array_merge(['post', 'page'], $pts)));
        $pts = array_values(array_filter($pts, fn($t) => $t !== 'attachment'));
        $html = sprintf('<option value="%s">%s</option>', esc_attr(home_url('/')), esc_html__('Homepage', 'tqk-wpseo'));
        foreach (get_posts(['post_type' => $pts, 'post_status' => ['publish'], 'numberposts' => 200]) as $p) {
            $html .= sprintf(
                '<option value="%s">%s [%s]</option>',
                esc_attr((string) get_permalink($p)),
                esc_html((string) $p->post_title),
                esc_html((string) $p->post_type)
            );
        }
        return $html;
    }

    /** @return string Report HTML for the AJAX refresh (no reload). */
    public static function scan_report_html(): string {
        return '<h2 class="tqk-wpseo-section">' . esc_html__('Latest result', 'tqk-wpseo') . '</h2>'
            . self::recent_scans();
    }

    // ---------- Content page removed: simple fields are edited in the dashboard quick fix. ----------

    // ---------- Redirects (AJAX: add appends a row, delete removes it) ----------

    /** @return string Single <tr> for a redirect row (full render + AJAX append share it). */
    public static function redirect_row($r): string {
        return sprintf(
            '<tr data-id="%d"><td>%s</td><td>%s</td><td>%s</td><td><button type="button" class="tqk-btn tqk-btn-outline tqk-btn-sm" data-tqk-del="redirect" data-id="%d">%s</button></td></tr>',
            (int) $r->id,
            esc_html((string) $r->source),
            esc_html((string) $r->target),
            esc_html(sprintf(__('Type %d · %d hits', 'tqk-wpseo'), (int) $r->type, (int) $r->hits)),
            (int) $r->id,
            esc_html__('Delete', 'tqk-wpseo')
        );
    }

    public static function redirects(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $prefill = isset($_GET['from_404']) ? sanitize_text_field(wp_unslash($_GET['from_404'])) : '';
        $rows = '';
        foreach (Redirects::all() as $r) {
            $rows .= self::redirect_row($r);
        }
        $content = '<h2 class="tqk-wpseo-section">' . esc_html__('Add redirect', 'tqk-wpseo') . '</h2>'
            . '<form data-tqk-form="redirect" class="tqk-wpseo-inline-form">'
            . sprintf('<input type="text" name="source" placeholder="%s" value="%s" size="28"> ', esc_attr__('Old URL', 'tqk-wpseo'), esc_attr($prefill))
            . sprintf('<input type="text" name="target" placeholder="%s" size="28"> ', esc_attr__('New URL', 'tqk-wpseo'))
            . '<select name="type"><option>301</option><option>302</option><option>307</option><option>410</option></select> '
            . sprintf('<button type="submit" class="button button-primary">%s</button></form>', esc_html__('Add', 'tqk-wpseo'))
            . '<p><button type="button" class="button" data-tqk-export-csv>' . esc_html__('Export CSV', 'tqk-wpseo') . '</button> '
            . '<label class="button">' . esc_html__('Import CSV', 'tqk-wpseo') . '<input type="file" id="tqk-import-csv" accept=".csv" hidden></label></p>'
            . '<h2 class="tqk-wpseo-section">' . esc_html__('Redirects', 'tqk-wpseo') . '</h2>'
            . '<div class="tqk-wpseo-table-wrap"><div class="table-responsive"><table class="tqk-wpseo-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('Old URL', 'tqk-wpseo') . '</th>'
            . '<th scope="col">' . esc_html__('New URL', 'tqk-wpseo') . '</th>'
            . '<th scope="col">' . esc_html__('Type', 'tqk-wpseo') . '</th><th></th>'
            . '</tr></thead><tbody id="tqk-rows-redirects">' . $rows . '</tbody></table></div></div>';
        View::page(__('Redirects', 'tqk-wpseo'), $content);
    }

    // ---------- 404 Monitor (AJAX delete removes the row) ----------

    /** @return string Single <tr> for a 404 row. */
    public static function four04_row($r): string {
        return sprintf(
            '<tr data-id="%d"><td>%s</td><td>%s</td><td>%s</td><td><a class="tqk-btn tqk-btn-primary tqk-btn-sm" href="%s">%s</a> <button type="button" class="tqk-btn tqk-btn-outline tqk-btn-sm" data-tqk-del="four04" data-id="%d">%s</button></td></tr>',
            (int) $r->id,
            esc_html((string) $r->url . ' (' . (int) $r->hits . ')'),
            esc_html((string) ($r->last_hit ?? '')),
            esc_html(mb_strimwidth((string) ($r->referrer ?? ''), 0, 40, '…')),
            esc_url(admin_url('admin.php?page=tqk-wpseo-redirects&from_404=' . rawurlencode((string) $r->url))),
            esc_html__('Create redirect', 'tqk-wpseo'),
            (int) $r->id,
            esc_html__('Delete', 'tqk-wpseo')
        );
    }

    public static function monitor_404(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $rows = '';
        foreach (Monitor404::all() as $r) {
            $rows .= self::four04_row($r);
        }
        $content = $rows === ''
            ? View::empty_state(__('No 404s logged', 'tqk-wpseo'), __('Misses will appear here ordered by hits.', 'tqk-wpseo'))
            : '<div class="tqk-wpseo-table-wrap"><div class="table-responsive"><table class="tqk-wpseo-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('URL (hits)', 'tqk-wpseo') . '</th>'
            . '<th scope="col">' . esc_html__('Last hit', 'tqk-wpseo') . '</th>'
            . '<th scope="col">' . esc_html__('Referrer', 'tqk-wpseo') . '</th><th></th>'
            . '</tr></thead><tbody id="tqk-rows-404">' . $rows . '</tbody></table></div></div>';
        View::page(__('404 Monitor', 'tqk-wpseo'), $content);
    }

    // ---------- Internal Links ----------

    public static function links(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $indexed_at = (string) get_option('tqk_wpseo_links_indexed_at', '');
        $content = '<h2 class="tqk-wpseo-section">' . esc_html__('Link index', 'tqk-wpseo') . '</h2>'
            . '<p>' . esc_html__('Reads every published post, page and product (shortcodes expanded) plus your menus. Posts are re-indexed on save; rebuild once after installing, or after big changes.', 'tqk-wpseo')
            . ($indexed_at !== '' ? ' ' . esc_html(sprintf(__('Last full index: %s', 'tqk-wpseo'), $indexed_at)) : '') . '</p>'
            . ($indexed_at === '' ? '<p class="notice notice-warning inline" style="padding:8px 12px">' . esc_html__('The link index has never been built on this site, so the orphan list below is not reliable yet. Press Rebuild.', 'tqk-wpseo') . '</p>' : '')
            . '<p><button type="button" class="button button-primary" data-tqk-links-rebuild>' . esc_html__('Rebuild link index', 'tqk-wpseo') . '</button> '
            . '<span id="tqk-links-rebuild-status"></span></p>'
            . '<h2 class="tqk-wpseo-section">' . esc_html__('Orphan content', 'tqk-wpseo') . '</h2>'
            . '<div id="tqk-orphans-wrap">' . self::orphans_html() . '</div>';
        $checked_at = (string) get_option('tqk_wpseo_links_checked_at', '');
        $content .= '<h2 class="tqk-wpseo-section">' . esc_html__('Broken links', 'tqk-wpseo') . '</h2>'
            . '<p>' . esc_html__('Checks external links and internal links that do not point to a post (archives, files, typos) with HTTP requests in small batches.', 'tqk-wpseo')
            . ($checked_at !== '' ? ' ' . esc_html(sprintf(__('Last check: %s', 'tqk-wpseo'), $checked_at)) : '')
            . '</p><p><button type="button" class="button button-primary" data-tqk-links-check>'
            . esc_html__('Check links', 'tqk-wpseo') . '</button> '
            . '<span id="tqk-links-status"></span></p>'
            . '<div id="tqk-broken-wrap">' . self::broken_html() . '</div>';
        View::page(__('Internal Links', 'tqk-wpseo'), $content);
    }

    public static function orphans_html(): string {
        $orows = [];
        foreach (array_slice(LinkEngine::orphan_ids(), 0, 50) as $id) {
            [$in, $out] = LinkEngine::counts($id);
            $orows[] = [
                get_the_title($id),
                sprintf(__('in %d · out %d', 'tqk-wpseo'), $in, $out),
                sprintf('<a class="tqk-btn tqk-btn-outline tqk-btn-sm" href="%s">%s</a>', esc_url(admin_url('post.php?post=' . $id . '&action=edit')), esc_html__('Edit', 'tqk-wpseo')),
            ];
        }
        return $orows === []
            ? View::empty_state(__('No orphans found', 'tqk-wpseo'), __('Every indexable post and page has at least one incoming link from content or menus.', 'tqk-wpseo'))
            : '<p class="description">' . esc_html__('No link from any post or menu points here, so visitors and Google can hardly find it. Link to it from a related post or a menu. Front page, cart / checkout / account and noindex pages are left out.', 'tqk-wpseo') . '</p>'
                . View::issue_table([__('Post', 'tqk-wpseo'), __('Links', 'tqk-wpseo'), ''], $orows);
    }

    public static function broken_html(): string {
        $rows = [];
        foreach (LinkEngine::broken() as $r) {
            $rows[] = [(string) $r->url, (string) ($r->anchor ?? ''), ''];
        }
        if ($rows === []) {
            return '<p>' . esc_html__('No broken links found.', 'tqk-wpseo') . '</p>';
        }
        return View::issue_table([__('URL', 'tqk-wpseo'), __('Anchor', 'tqk-wpseo'), ''], $rows);
    }

    // ---------- Settings ----------

    public static function settings(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $s = array_merge(\TQK_WPSEO\Core\Activator::default_settings(), (array) get_option(TQK_WPSEO_OPTION, []));
        $v = static fn(string $k) => (string) ($s[$k] ?? '');

        // Business profile: short fields side by side, long ones full width.
        $business = self::field('business_name', __('Business name', 'tqk-wpseo'), $v('business_name'))
            . self::field('business_industry', __('Industry', 'tqk-wpseo'), $v('business_industry'))
            . self::field('business_audience', __('Target customers', 'tqk-wpseo'), $v('business_audience'))
            . self::field('business_tone', __('Writing tone', 'tqk-wpseo'), $v('business_tone'))
            . self::field('business_phone', __('Phone', 'tqk-wpseo'), $v('business_phone'))
            . self::field('business_hours', __('Opening hours', 'tqk-wpseo'), $v('business_hours'), ['placeholder' => 'Mo-Fr 08:00-17:00'])
            . self::field('business_address', __('Address', 'tqk-wpseo'), $v('business_address'), ['wide' => true])
            . implode('', array_map(
                static fn($m) => '<p class="notice notice-warning inline is-wide" style="margin:0;padding:6px 10px">' . esc_html($m) . '</p>',
                \TQK_WPSEO\Local\Address::check($v('business_address'))['messages']
            ))
            . sprintf(
                '<label class="tqk-field is-wide"><span class="tqk-field-label">%s</span><textarea rows="2" name="settings[business_description]">%s</textarea></label>',
                esc_html__('What do you do?', 'tqk-wpseo'),
                esc_html($v('business_description'))
            );

        $img = $v('social_default_image');
        $social = '<div class="tqk-field is-wide"><span class="tqk-field-label">' . esc_html__('Default share image (site-wide)', 'tqk-wpseo') . '</span>'
            . '<span class="tqk-image-row" data-image-field>'
            . '<span class="tqk-wpseo-image-preview-wrap" data-image-preview-wrap>' . ($img !== '' ? sprintf('<img class="tqk-wpseo-image-preview" src="%s" alt="">', esc_url($img)) : '') . '</span>'
            . sprintf('<input type="hidden" name="settings[social_default_image]" value="%s" data-image-url>', esc_attr($img))
            . '<button type="button" class="button tqk-wpseo-image-pick">' . esc_html__('Select image', 'tqk-wpseo') . '</button>'
            . '<button type="button" class="button-link tqk-wpseo-image-clear">' . esc_html__('Remove', 'tqk-wpseo') . '</button>'
            . '</span></div>'
            . '<div class="is-wide">' . View::switch('settings[social_use_content_image]', !empty($s['social_use_content_image']), __('Use the first image in the post when there is no featured image', 'tqk-wpseo')) . '</div>';

        $templates = self::field('title_template', __('Title template', 'tqk-wpseo'), $v('title_template'))
            . self::field('description_template', __('Description template', 'tqk-wpseo'), $v('description_template'));

        $index = '';
        foreach (['noindex_attachments' => __('Noindex attachments', 'tqk-wpseo'), 'noindex_search' => __('Noindex search pages', 'tqk-wpseo'), 'noindex_author' => __('Noindex author archives', 'tqk-wpseo'), 'noindex_tag' => __('Noindex tag archives', 'tqk-wpseo')] as $k => $label) {
            $index .= View::switch('settings[' . $k . ']', !empty($s[$k]), $label);
        }

        $smaps = ['post' => __('Posts', 'tqk-wpseo'), 'page' => __('Pages', 'tqk-wpseo'), 'category' => __('Categories', 'tqk-wpseo'), 'post_tag' => __('Tags', 'tqk-wpseo'), 'news' => __('News', 'tqk-wpseo'), 'video' => __('Video', 'tqk-wpseo')];
        if (function_exists('get_post_types')) {
            foreach (array_keys((array) get_post_types(['public' => true])) as $cpt) {
                if (!isset($smaps[$cpt]) && $cpt !== 'attachment') {
                    $obj = function_exists('get_post_type_object') ? get_post_type_object($cpt) : null;
                    $smaps[$cpt] = (string) ($obj->labels->name ?? ucfirst($cpt));
                }
            }
        }
        $sitemap = View::switch('settings[sitemap_enabled]', !empty($s['sitemap_enabled']), __('Enable sitemap', 'tqk-wpseo'))
            . '</div><div class="tqk-fields is-head">'
            . self::field('sitemap_slug', __('Private sitemap name (optional)', 'tqk-wpseo'), $v('sitemap_slug'), ['placeholder' => 'map-' . bin2hex(random_bytes(4)), 'wide' => true])
            . '<p class="description is-wide">' . esc_html__('Empty = /sitemap_index.xml. With a name, the sitemap moves to /{name}.xml, the usual addresses return a normal 404 and robots.txt no longer lists it; it is sent to Google Search Console and Bing directly instead. 6-40 characters: a-z, 0-9, dashes.', 'tqk-wpseo') . '</p>';
        $maps = '';
        foreach ($smaps as $m => $label) {
            $maps .= View::switch('settings[sitemap_maps][' . $m . ']', in_array($m, (array) ($s['sitemap_maps'] ?? []), true), $label);
        }

        $schema_types = ['Article' => 'Article', 'BlogPosting' => 'BlogPosting', 'WebPage' => 'WebPage', 'Product' => 'Product', 'Course' => 'Course', 'FAQPage' => 'FAQPage', 'HowTo' => 'HowTo', 'Recipe' => 'Recipe', 'Event' => 'Event'];
        $map = is_array($s['schema_map'] ?? null) ? $s['schema_map'] : [];
        $pts = function_exists('get_post_types') ? array_keys((array) get_post_types(['public' => true])) : ['post', 'page'];
        $pts = array_values(array_unique(array_merge(['post', 'page', 'product'], $pts)));
        $schema = '';
        foreach ($pts as $pt) {
            $pto = function_exists('get_post_type_object') ? get_post_type_object($pt) : null;
            $label = $pto && isset($pto->labels->singular_name) ? (string) $pto->labels->singular_name : ucfirst($pt);
            $schema .= sprintf(
                '<label class="tqk-field is-row"><span class="tqk-field-label">%s</span><select name="settings[schema_map][%s]">%s</select></label>',
                esc_html($label),
                esc_attr($pt),
                self::options_html($schema_types, (string) ($map[$pt] ?? ''))
            );
        }

        $schema .= sprintf(
            '<label class="tqk-field is-wide"><span class="tqk-field-label">%s</span><select name="settings[product_no_price]">%s</select></label>',
            esc_html__('Products without a price ("Liên hệ" / contact for price)', 'tqk-wpseo'),
            self::options_html([
                'skip' => __('No Product schema (recommended: avoids Search Console errors)', 'tqk-wpseo'),
                'no_offers' => __('Product schema without price', 'tqk-wpseo'),
            ], $v('product_no_price'))
        );

        $logo = $v('report_brand_logo');
        $brand = self::field('report_brand_name', __('Agency / brand name', 'tqk-wpseo'), $v('report_brand_name'))
            . sprintf(
                '<label class="tqk-field"><span class="tqk-field-label">%s</span><input type="color" name="settings[report_brand_color]" value="%s"></label>',
                esc_html__('Brand colour', 'tqk-wpseo'),
                esc_attr($v('report_brand_color') ?: '#4f46e5')
            )
            . sprintf(
                '<label class="tqk-field"><span class="tqk-field-label">%s</span><select name="settings[report_locale]">%s</select></label>',
                esc_html__('Report language', 'tqk-wpseo'),
                self::options_html(['' => __('Same as the admin viewing it', 'tqk-wpseo'), 'vi' => 'Tiếng Việt', 'en_US' => 'English'], $v('report_locale'))
            )
            . sprintf(
                '<label class="tqk-field"><span class="tqk-field-label">%s</span><select name="settings[audit_keep_days]">%s</select></label>',
                esc_html__('Keep audit snapshots & files', 'tqk-wpseo'),
                self::options_html(['1' => __('1 day (safest)', 'tqk-wpseo'), '7' => __('7 days', 'tqk-wpseo'), '30' => __('30 days', 'tqk-wpseo')], (string) ($v('audit_keep_days') ?: '1'))
            )
            . self::field('report_brand_contact', __('Contact line (phone, Zalo, website)', 'tqk-wpseo'), $v('report_brand_contact'))
            . '<div class="tqk-field is-wide"><span class="tqk-field-label">' . esc_html__('Logo', 'tqk-wpseo') . '</span>'
            . '<span class="tqk-image-row" data-image-field>'
            . '<span class="tqk-wpseo-image-preview-wrap" data-image-preview-wrap>' . ($logo !== '' ? sprintf('<img class="tqk-wpseo-image-preview" src="%s" alt="">', esc_url($logo)) : '') . '</span>'
            . sprintf('<input type="hidden" name="settings[report_brand_logo]" value="%s" data-image-url>', esc_attr($logo))
            . '<button type="button" class="button tqk-wpseo-image-pick">' . esc_html__('Select image', 'tqk-wpseo') . '</button>'
            . '<button type="button" class="button-link tqk-wpseo-image-clear">' . esc_html__('Remove', 'tqk-wpseo') . '</button>'
            . '</span></div>';

        $ai = sprintf(
            '<label class="tqk-field is-wide"><span class="tqk-field-label">%s</span><select name="settings[ai_provider]" data-ai-provider>%s</select></label>',
            esc_html__('Provider', 'tqk-wpseo'),
            self::options_html(['none' => __('None', 'tqk-wpseo'), 'gemini' => 'Google Gemini (Flash)', 'ollama' => 'Ollama', 'lmstudio' => 'LM Studio'], $v('ai_provider'))
        )
            . '<div class="tqk-fields is-wide" data-ai-for="ollama lmstudio">'
            . self::field('ai_endpoint', __('Endpoint', 'tqk-wpseo'), $v('ai_endpoint'))
            . self::field('ai_model', __('Model', 'tqk-wpseo'), $v('ai_model'))
            . '</div>'
            . '<div class="tqk-fields is-3 is-wide" data-ai-for="gemini">'
            . self::field('gemini_api_key', __('Gemini API key', 'tqk-wpseo'), self::masked_secret($v('gemini_api_key')), ['type' => 'password', 'wide' => true])
            . self::field('gemini_model', __('Model', 'tqk-wpseo'), $v('gemini_model'), ['placeholder' => 'gemini-2.5-flash'])
            . self::field('ai_rpm', __('Requests / minute', 'tqk-wpseo'), $v('ai_rpm'), ['type' => 'number'])
            . self::field('ai_rpd', __('Requests / day', 'tqk-wpseo'), $v('ai_rpd'), ['type' => 'number'])
            . '<p class="description is-wide">' . esc_html__('Free tier: keep the limits low (5/min, 200/day), each request fixes 3 pages. On the free tier Google may use what you send to improve its products.', 'tqk-wpseo') . '</p>'
            . '</div>'
            . '<div class="tqk-switches is-wide">'
            . View::switch('settings[mcp_direct_write]', !empty($s['mcp_direct_write']), __('AI apps connected by MCP may write SEO fields without my approval', 'tqk-wpseo'))
            . '</div>'
            . '<p class="description is-wide">' . esc_html__('Off (recommended): the AI app sends suggestions; you review them in the post editor and choose what to use.', 'tqk-wpseo') . '</p>';

        $roles_saved = is_array($s['seo_roles'] ?? null) ? $s['seo_roles'] : [];
        $roles = '';
        foreach (['editor' => __('Editor', 'tqk-wpseo'), 'author' => __('Author', 'tqk-wpseo'), 'contributor' => __('Contributor', 'tqk-wpseo')] as $r => $label) {
            $roles .= View::switch('settings[seo_roles][' . $r . ']', !empty($roles_saved[$r]), $label);
        }

        $indexing = '<div class="tqk-switches is-wide">'
            . View::switch('settings[indexing_google]', !empty($s['indexing_google']), __('Notify Google', 'tqk-wpseo'))
            . View::switch('settings[indexing_bing]', !empty($s['indexing_bing']), __('Notify Bing', 'tqk-wpseo'))
            . '</div>'
            . self::field('bing_api_key', __('Bing API key', 'tqk-wpseo'), self::masked_secret($v('bing_api_key')), ['type' => 'password', 'wide' => true]);
        $log = \TQK_WPSEO\Indexing\Submitter::recent();
        if ($log !== []) {
            $rows = [];
            foreach (array_slice($log, 0, 5) as $e) {
                $res = [];
                foreach ((array) ($e['result'] ?? []) as $eng => $r) {
                    $res[] = $eng . ': ' . (!empty($r['ok']) ? '✓' : '✗');
                }
                $rows[] = [(string) ($e['at'] ?? ''), mb_strimwidth((string) ($e['url'] ?? ''), 0, 50, '…'), implode(' · ', $res)];
            }
            $indexing .= '<div class="is-wide"><h4>' . esc_html__('Recent pings', 'tqk-wpseo') . '</h4>'
                . View::issue_table([__('At', 'tqk-wpseo'), __('URL', 'tqk-wpseo'), __('Result', 'tqk-wpseo')], $rows) . '</div>';
        }

        $content = '<form data-tqk-form="settings" class="tqk-settings">'
            . '<div class="tqk-settings-grid">'
            . self::card(__('Business profile (for AI)', 'tqk-wpseo'), __('Industry + audience feed every AI prompt. Fill once at setup, AI writes in context.', 'tqk-wpseo'), '<div class="tqk-fields is-3">' . $business . '</div>', 'is-wide')
            . self::card(__('Social sharing', 'tqk-wpseo'), __('Featured image first, then this default, then the first image inside the post.', 'tqk-wpseo'), '<div class="tqk-fields">' . $social . '</div>')
            . self::card(__('Templates', 'tqk-wpseo'), __('Variables: %title%, %site_name%, %excerpt%.', 'tqk-wpseo'), '<div class="tqk-fields is-1">' . $templates . '</div>')
            . self::card(__('Indexability', 'tqk-wpseo'), '', '<div class="tqk-switches">' . $index . '</div>')
            . self::card(__('Sitemap', 'tqk-wpseo'), __('Which types appear in sitemap_index.xml.', 'tqk-wpseo'), '<div class="tqk-switches is-head">' . $sitemap . '</div><div class="tqk-switches">' . $maps . '</div>')
            . self::card(__('Schema templates', 'tqk-wpseo'), __('Default Schema type per post type. A post can override this in its SEO box.', 'tqk-wpseo'), '<div class="tqk-fields is-rows">' . $schema . '</div>', 'is-wide')
            . self::card(__('AI provider (optional)', 'tqk-wpseo'), '', '<div class="tqk-fields">' . $ai . '</div>')
            . self::card(__('License (Pro)', 'tqk-wpseo'), __('Every SEO feature is free. The Pro add-on adds the automatic image optimizer (resize, crop, compress, WebP, srcset) and "audit all" in Live Audit.', 'tqk-wpseo'), \TQK_WPSEO\Core\Pro::settings_html())
            . self::card(__('Report branding (white-label)', 'tqk-wpseo'), __('Your name, logo and colour on the SEO report you send to clients.', 'tqk-wpseo'), '<div class="tqk-fields">' . $brand . '</div>')
            . self::card(__('Role Manager', 'tqk-wpseo'), __('Extra roles allowed into SEO screens (admins always have access).', 'tqk-wpseo'), '<div class="tqk-switches">' . $roles . '</div>')
            . self::card(__('Instant Indexing', 'tqk-wpseo'), __('Ping Google (reuses GSC token) + Bing on publish. Enable the module first.', 'tqk-wpseo'), '<div class="tqk-fields">' . $indexing . '</div>', 'is-wide')
            . '</div>'
            . '<div class="tqk-savebar"><button type="submit" class="button button-primary">' . esc_html__('Save settings', 'tqk-wpseo') . '</button></div>'
            . '</form>';
        View::page(__('Settings', 'tqk-wpseo'), $content);
    }

    /** One settings card: title, optional one-line description, body. */
    private static function card(string $title, string $desc, string $body, string $class = ''): string {
        return '<section class="tqk-wpseo-formcard ' . esc_attr($class) . '"><h3>' . esc_html($title) . '</h3>'
            . ($desc !== '' ? '<p class="desc">' . esc_html($desc) . '</p>' : '')
            . $body . '</section>';
    }

    /**
     * Label-on-top text field sharing one height with every other control.
     * opts: type, placeholder, wide (spans the full card row).
     */
    private static function field(string $key, string $label, string $value, array $opts = []): string {
        $type = (string) ($opts['type'] ?? 'text');
        return sprintf(
            '<label class="tqk-field%s"><span class="tqk-field-label">%s</span><input type="%s" name="settings[%s]" value="%s"%s%s></label>',
            !empty($opts['wide']) ? ' is-wide' : '',
            esc_html($label),
            esc_attr($type),
            esc_attr($key),
            esc_attr($value),
            isset($opts['placeholder']) ? ' placeholder="' . esc_attr((string) $opts['placeholder']) . '"' : '',
            $type === 'password' ? ' autocomplete="off"' : ''
        );
    }

    private static function options_html(array $options, string $current): string {
        $html = '';
        foreach ($options as $v => $label) {
            $html .= sprintf('<option value="%s"%s>%s</option>', esc_attr($v), $v === $current ? ' selected' : '', esc_html($label));
        }
        return $html;
    }

    // ---------- Tools ----------

    /**
     * Labels of the shared scan stage (Live Audit + post editor Scan).
     * Worded as what it is: an automatic check, not "AI" — the page is
     * driven by a script; AI is only the Gemini / JSON suggestion features.
     */
    /**
     * Suggestions AI apps sent back over MCP, waiting for approval: which
     * page, from whom, when, the start of the advice, and a link to review
     * it field by field in the editor.
     */
    private static function ai_pending_card(): string {
        $pending = \TQK_WPSEO\MCP\Reports::pending();
        if ($pending === []) {
            return '';
        }
        $rows = '';
        foreach ($pending as $id => $s) {
            $advice = trim(preg_replace('/\s+/u', ' ', (string) ($s['advice'] ?? '')));
            $rows .= '<tr><td><a href="' . esc_url((string) get_edit_post_link($id, 'raw')) . '"><strong>' . esc_html((string) get_the_title($id)) . '</strong></a>'
                . '<br><small>' . esc_html(sprintf(__('%1$s · %2$s', 'tqk-wpseo'), (string) ($s['from'] ?? 'AI'), wp_date('d/m/Y H:i', (int) ($s['at'] ?? 0)))) . '</small></td>'
                . '<td>' . esc_html(mb_strimwidth($advice, 0, 220, '…')) . '</td>'
                . '<td class="tqk-la-num">' . count((array) ($s['fields'] ?? [])) . '</td>'
                . '<td><a class="button button-primary button-small" href="' . esc_url((string) get_edit_post_link($id, 'raw')) . '">' . esc_html__('Review', 'tqk-wpseo') . '</a></td></tr>';
        }
        return '<h2 class="tqk-wpseo-section">' . esc_html(sprintf(__('AI suggestions waiting for approval (%d)', 'tqk-wpseo'), count($pending))) . '</h2>'
            . '<p class="description">' . esc_html__('Sent back by AI apps connected over MCP. Nothing has been changed on these pages: open one to read the advice and choose which values to use.', 'tqk-wpseo') . '</p>'
            . '<div class="tqk-wpseo-table-wrap"><table class="tqk-wpseo-table tqk-ai-pending"><thead><tr><th>' . esc_html__('Page', 'tqk-wpseo') . '</th><th>' . esc_html__('Advice', 'tqk-wpseo') . '</th><th class="tqk-la-num">' . esc_html__('Fields', 'tqk-wpseo') . '</th><th></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    /**
     * MCP access: endpoint, token and ready-to-paste configs for the usual
     * AI apps. The token grants read access to SEO data and SEO field writes.
     */
    public static function mcp_card(): string {
        $url = rest_url('tqk-wpseo/v1/mcp');
        $token = \TQK_WPSEO\MCP\Server::token();
        $on = \TQK_WPSEO\Core\Modules::is_active('mcp');
        $code = 'claude mcp add --transport http tqk-seo ' . $url . ' --header "X-TQK-Token: ' . $token . '"';
        $desktop = (string) wp_json_encode(['mcpServers' => ['tqk-seo' => [
            'command' => 'npx',
            'args' => ['-y', 'mcp-remote', $url, '--header', 'X-TQK-Token:' . $token],
        ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $cursor = (string) wp_json_encode(['mcpServers' => ['tqk-seo' => ['url' => $url, 'headers' => ['X-TQK-Token' => $token]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $pre = static fn(string $label, string $txt) => '<p><strong>' . esc_html($label) . '</strong></p><pre class="tqk-wpseo-code">' . esc_html($txt) . '</pre>';
        return '<h2 class="tqk-wpseo-section" id="mcp">' . esc_html__('MCP access (Claude, Cursor…)', 'tqk-wpseo') . '</h2>'
            . ($on ? '' : '<p class="notice notice-warning inline"><span>' . esc_html__('The MCP module is off: turn it on in Modules first.', 'tqk-wpseo') . '</span></p>')
            . '<p>' . esc_html__('Connect an AI app once. Then scan a page in the editor and press "Send to AI": the app reads the report, the action plan and screenshots of 4 screens, and can fill the SEO fields after you agree.', 'tqk-wpseo') . '</p>'
            . '<p><strong>' . esc_html__('Endpoint', 'tqk-wpseo') . '</strong></p><p><input type="text" class="large-text code" readonly value="' . esc_attr($url) . '"></p>'
            . sprintf(
                '<p><strong>%s</strong></p><p><input type="text" class="large-text code" id="tqk-mcp-token" readonly value="%s"> '
                . '<button type="button" class="button" data-tqk-mcp-regen>%s</button></p>',
                esc_html__('Token (keep it private, like a password)', 'tqk-wpseo'),
                esc_attr($token),
                esc_html__('Regenerate', 'tqk-wpseo')
            )
            . $pre(__('Claude Code — run in a terminal:', 'tqk-wpseo'), $code)
            . $pre(__('Claude Desktop — Settings → Developer → Edit config (needs Node.js):', 'tqk-wpseo'), $desktop)
            . $pre(__('Cursor — .cursor/mcp.json:', 'tqk-wpseo'), $cursor)
            . '<p class="description">' . esc_html__('Then ask the app: "review the pages I sent from TQK SEO". Every write is logged; regenerating the token disconnects every app.', 'tqk-wpseo') . '</p>';
    }

    /** Messages of the in-browser page check (Live Audit and the editor Scan). */
    public static function seo_strings(): array {
        return [
            'noTitle' => __('Missing <title>', 'tqk-wpseo'),
            'longTitle' => __('Title over 60 chars (%d)', 'tqk-wpseo'),
            'noDesc' => __('Missing meta description', 'tqk-wpseo'),
            'longDesc' => __('Description over 160 chars (%d)', 'tqk-wpseo'),
            'noCanon' => __('No canonical link', 'tqk-wpseo'),
            'canonOther' => __('Canonical points to another URL: %s', 'tqk-wpseo'),
            'noindex' => __('Page is set to noindex', 'tqk-wpseo'),
            'robotsMany' => __('%1$d robots meta tags, they contradict: %2$s', 'tqk-wpseo'),
            'redirect' => __('Redirects to %s', 'tqk-wpseo'),
            'noOg' => __('Open Graph title/description/image incomplete', 'tqk-wpseo'),
            'noH1' => __('No H1', 'tqk-wpseo'),
            'manyH1' => __('Multiple H1 (%d)', 'tqk-wpseo'),
            'noLang' => __('<html> has no lang attribute', 'tqk-wpseo'),
            'noSchema' => __('No JSON-LD schema', 'tqk-wpseo'),
            'badJson' => __('Invalid JSON-LD block (Google drops it)', 'tqk-wpseo'),
            'schemaMiss' => __('%1$s schema is missing: %2$s', 'tqk-wpseo'),
            'noAlt' => __('%d image(s) missing ALT', 'tqk-wpseo'),
            'thin' => __('Thin content (%d words)', 'tqk-wpseo'),
            'priceZero' => __('Product price is 0: Google shows it as free. Use the "no price" setting instead.', 'tqk-wpseo'),
            'ogSmall' => __('Share image %1$d×%2$d is too small for Facebook/Zalo previews (min 600×315, best 1200×630).', 'tqk-wpseo'),
            'ogRatio' => __('Share image ratio %s: Facebook/Zalo crop it to 1.91:1 (1200×630).', 'tqk-wpseo'),
            'ogBroken' => __('Share image does not load: %s', 'tqk-wpseo'),
            'ogRelative' => __('og:image must be a full https:// URL.', 'tqk-wpseo'),
            'ogUrl' => __('og:url differs from canonical: %s', 'tqk-wpseo'),
        ];
    }

    public static function stage_strings(): array {
        return [
            'title' => __('Automatic check', 'tqk-wpseo'),
            'full' => __('Full screen', 'tqk-wpseo'),
            'mini' => __('Minimize', 'tqk-wpseo'),
            'stop' => __('Stop', 'tqk-wpseo'),
        ];
    }

    /** Move from Yoast / Rank Math (often cracked "Pro" builds) without losing anything. */
    private static function import_card(): string {
        $counts = [];
        foreach (['yoast', 'rankmath'] as $src) {
            $counts[$src] = \TQK_WPSEO\Tools\Importer::count($src);
        }
        $opts = '';
        foreach ($counts as $src => $n) {
            $opts .= sprintf('<option value="%s"%s>%s (%s)</option>', esc_attr($src), $n > 0 && $opts === '' ? ' selected' : '', esc_html(\TQK_WPSEO\Tools\Importer::source_name($src)), esc_html(sprintf(__('%d post(s) with data', 'tqk-wpseo'), $n)));
        }
        $t = [
            'found' => __('%1$d post(s) with SEO data · %2$d redirect(s) (%3$d regex/partial skipped). Sample below.', 'tqk-wpseo'),
            'post' => __('Post', 'tqk-wpseo'),
            'values' => __('Values that will be imported', 'tqk-wpseo'),
            'progress' => __('Importing %1$d / %2$d…', 'tqk-wpseo'),
            'done' => __('Done: %1$d post(s), %2$d field(s) written, %3$d kept (already filled).', 'tqk-wpseo'),
            'lossy' => __('%d post(s) had "nofollow only", which has no equivalent here: left on default.', 'tqk-wpseo'),
            'redirects' => __('Redirects: %1$d imported, %2$d skipped.', 'tqk-wpseo'),
            'terms' => __('Categories / tags with SEO data: %d.', 'tqk-wpseo'),
            'termsDone' => __('Categories / tags: %1$d, %2$d field(s) written.', 'tqk-wpseo'),
            'next' => __('Check a few pages, then deactivate and DELETE the old plugin (a cracked copy keeps its malware while it stays installed).', 'tqk-wpseo'),
        ];
        return '<h2 class="tqk-wpseo-section">' . esc_html__('Import from Yoast SEO / Rank Math', 'tqk-wpseo') . '</h2>'
            . '<div class="tqk-wpseo-formcard" id="tqk-import" style="max-width:none" data-t="' . esc_attr((string) wp_json_encode($t)) . '">'
            . '<p class="desc">' . esc_html__('Copies titles, descriptions, focus + secondary keywords, canonical, robots, social tags, schema type and redirects. Works even if the old plugin is deactivated. Empty fields are filled; existing TQK values are kept unless you tick overwrite.', 'tqk-wpseo') . '</p>'
            . '<div class="tqk-fields is-3">'
            . '<label class="tqk-field"><span class="tqk-field-label">' . esc_html__('Source', 'tqk-wpseo') . '</span><select name="tqk-import-source">' . $opts . '</select></label>'
            . '<div class="tqk-switches" style="grid-column:span 2">'
            . View::switch('tqk-import-redirects', true, __('Import redirects too', 'tqk-wpseo'))
            . View::switch('tqk-import-overwrite', false, __('Overwrite existing TQK values', 'tqk-wpseo'))
            . '</div></div>'
            . '<p class="tqk-la-buttons"><button type="button" class="button" data-import="preview">' . esc_html__('Preview', 'tqk-wpseo') . '</button> '
            . '<button type="button" class="button button-primary" data-import="run" disabled>' . esc_html__('Import', 'tqk-wpseo') . '</button></p>'
            . '<div class="tqk-rs-bar"><div class="tqk-import-fill"></div></div>'
            . '<p class="tqk-import-status" aria-live="polite"></p><div class="tqk-import-out"></div>'
            . '</div>';
    }

    // ---------- Security ----------

    public static function security(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $s = \TQK_WPSEO\Security\Scan::state();
        $t = [
            'starting' => __('Downloading official checksums…', 'tqk-wpseo'),
            'step' => __('Step %1$d / %2$d', 'tqk-wpseo'),
            'done' => __('Scan finished.', 'tqk-wpseo'),
        ];
        $last = !empty($s['finished'])
            ? '<p class="description">' . esc_html(sprintf(__('Last scan: %s', 'tqk-wpseo'), date_i18n((string) get_option('date_format') . ' H:i', (int) $s['finished']))) . '</p>'
                . \TQK_WPSEO\Security\Scan::report_html($s)
            : '';
        $content = '<div class="tqk-wpseo-formcard" id="tqk-security" style="max-width:none" data-t="' . esc_attr((string) wp_json_encode($t)) . '">'
            . '<h3>' . esc_html__('Malware & SEO-spam scan', 'tqk-wpseo') . '</h3>'
            . '<p class="desc">' . esc_html__('Cracked "Pro" plugins and themes are the usual way malware gets in. This scan checks WordPress core and every WordPress.org plugin against their official checksums, scans premium plugins, themes and uploads for backdoor patterns, lists recently created admins, and compares what Googlebot sees with what visitors see (cloaking, hidden gambling / pharma links). Read-only: nothing is changed.', 'tqk-wpseo') . '</p>'
            . '<p><button type="button" class="button button-primary" data-sec="start">' . esc_html__('Scan now', 'tqk-wpseo') . '</button></p>'
            . '<div class="tqk-rs-bar"><div class="tqk-sec-fill"></div></div>'
            . '<p class="tqk-sec-status" aria-live="polite"></p>'
            . '<div class="tqk-sec-result">' . $last . '</div>'
            . '</div>';
        View::page(__('Security scan', 'tqk-wpseo'), $content);
    }

    public static function tools(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        global $wpdb;
        $info = sprintf(
            "PHP %s\nWordPress %s\nTQK WP SEO %s\nTables: %s",
            PHP_VERSION,
            $GLOBALS['wp_version'] ?? '?',
            TQK_WPSEO_VERSION,
            implode(', ', \TQK_WPSEO\Core\Activator::table_names($wpdb->prefix))
        );
        $content = self::import_card()
            . '<h2 class="tqk-wpseo-section">' . esc_html__('System info', 'tqk-wpseo') . '</h2>'
            . '<pre class="tqk-wpseo-code">' . esc_html($info) . '</pre>'
            . self::mcp_card()
            . '<h2 class="tqk-wpseo-section">' . esc_html__('Import / Export', 'tqk-wpseo') . '</h2>'
            . '<p><button type="button" class="button" data-tqk-export-settings>' . esc_html__('Export settings (JSON)', 'tqk-wpseo') . '</button> '
            . '<label class="button">' . esc_html__('Import settings', 'tqk-wpseo') . '<input type="file" id="tqk-import-settings" accept=".json,application/json" hidden></label></p>'
            . '<h2 class="tqk-wpseo-section">' . esc_html__('Reset', 'tqk-wpseo') . '</h2>'
            . '<form data-tqk-form="tools">'
            . sprintf(
                '<p><button type="submit" class="button button-secondary" data-confirm="%s">%s</button></p></form>',
                esc_attr__('Delete all TQK WP SEO data and start over?', 'tqk-wpseo'),
                esc_html__('Reset all data', 'tqk-wpseo')
            );
        View::page(__('Tools', 'tqk-wpseo'), $content);
    }

    // ---------- Sitemap / Schema (S3, kept) ----------

    public static function sitemap(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $sm = \TQK_WPSEO\Sitemap\Sitemap::class;
        $file = static fn(string $url) => sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($url), esc_html(basename((string) parse_url($url, PHP_URL_PATH))));
        $rows = [];
        foreach ($sm::maps() as $type => $label) {
            $rows[] = [$label, (string) count($sm::items($type)), $file($sm::map_url($type))];
        }
        foreach ($sm::special_maps() as $type => $label) {
            $n = $type === 'news' ? count($sm::news_items()) : count($sm::video_items());
            $rows[] = [$label, (string) $n, $file($sm::map_url($type))];
        }
        $private = $sm::slug() !== '';
        $last = get_option($sm::SUBMIT_OPTION, []);
        $last_html = '';
        if (is_array($last) && !empty($last['at'])) {
            foreach (['google' => 'Google', 'bing' => 'Bing'] as $k => $name) {
                $r = (array) ($last[$k] ?? []);
                $last_html .= '<li class="' . (!empty($r['ok']) ? 'is-pass' : 'is-warn') . '">' . esc_html($name . ': ' . (string) ($r['message'] ?? '')) . '</li>';
            }
            $last_html = '<p class="description">' . esc_html(sprintf(__('Last submission: %1$s — %2$s', 'tqk-wpseo'), date_i18n((string) get_option('date_format') . ' H:i', (int) $last['at']), (string) ($last['url'] ?? ''))) . '</p><ul class="tqk-live-findings">' . $last_html . '</ul>';
        }
        $content = '<h2 class="tqk-wpseo-section">' . esc_html__('Sitemap index', 'tqk-wpseo') . '</h2>'
            . '<p>' . $file($sm::index_url())
            . ($private ? ' <span class="tqk-rs-badge">' . esc_html__('private address', 'tqk-wpseo') . '</span>' : '') . '</p>'
            . '<p class="description">' . esc_html($private
                ? __('Not listed in robots.txt or llms.txt; /sitemap_index.xml and /wp-sitemap.xml return a normal 404. Search engines get this address only from you (below).', 'tqk-wpseo')
                : __('Public address, listed in robots.txt. Give it a private name in Settings → Sitemap if you do not want competitors to read your full URL list.', 'tqk-wpseo')) . '</p>'
            . '<p><button type="button" class="button button-primary" data-tqk-sitemap-submit>' . esc_html__('Submit to Google & Bing now', 'tqk-wpseo') . '</button> <span id="tqk-sitemap-submit-status"></span></p>'
            . '<div id="tqk-sitemap-submit-last">' . $last_html . '</div>'
            . View::issue_table([__('Type', 'tqk-wpseo'), __('URLs', 'tqk-wpseo'), __('File', 'tqk-wpseo')], $rows)
            . '<h2 class="tqk-wpseo-section">' . esc_html__('Settings (one-go, no page jumps)', 'tqk-wpseo') . '</h2>'
            . self::sitemap_settings_form()
            . '<h2 class="tqk-wpseo-section">' . esc_html__('robots.txt', 'tqk-wpseo') . '</h2>'
            . self::robots_form();
        View::page(__('Sitemap', 'tqk-wpseo'), $content);
    }

    private static function sitemap_settings_form(): string {
        $s = array_merge(\TQK_WPSEO\Core\Activator::default_settings(), (array) get_option(TQK_WPSEO_OPTION, []));
        $html = '<form data-tqk-form="settings" class="tqk-wpseo-inline-form">';
        $html .= View::switch('settings[sitemap_enabled]', !empty($s['sitemap_enabled']), __('Enable sitemap', 'tqk-wpseo'));
        $maps = ['post' => __('Posts', 'tqk-wpseo'), 'page' => __('Pages', 'tqk-wpseo'), 'category' => __('Categories', 'tqk-wpseo'), 'post_tag' => __('Tags', 'tqk-wpseo'), 'news' => __('News', 'tqk-wpseo'), 'video' => __('Video', 'tqk-wpseo')];
        if (function_exists('get_post_types')) {
            foreach (array_keys((array) get_post_types(['public' => true])) as $cpt) {
                if (!isset($maps[$cpt]) && $cpt !== 'attachment') {
                    $maps[$cpt] = ucfirst($cpt);
                }
            }
        }
        foreach ($maps as $m => $label) {
            $html .= View::switch('settings[sitemap_maps][' . $m . ']', in_array($m, (array) ($s['sitemap_maps'] ?? []), true), $label);
        }
        $html .= sprintf('<button type="submit" class="button button-primary">%s</button></form>', esc_html__('Save sitemap', 'tqk-wpseo'));
        $html .= '<p class="description">' . esc_html__('Per-page sitemap link + exclude lives in Dashboard accordion — no need to open this page. Hidden pages are skipped so bots can\'t crawl via sitemap.', 'tqk-wpseo') . '</p>';
        return $html;
    }

    private static function robots_form(): string {
        $custom = get_option(\TQK_WPSEO\Sitemap\Sitemap::ROBOTS_OPTION, '');
        $current = is_string($custom) && trim($custom) !== '' ? $custom : \TQK_WPSEO\Sitemap\Sitemap::default_robots();
        $warnings = \TQK_WPSEO\Sitemap\Sitemap::validate_robots($current);
        $html = '<div id="tqk-robots-warnings">';
        foreach ($warnings as $w) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($w) . '</p></div>';
        }
        $html .= '</div>';
        $html .= '<form data-tqk-form="robots">';
        $html .= sprintf('<p><textarea name="content" rows="8" class="large-text code" id="tqk-robots-text">%s</textarea></p>', esc_html($current));
        $html .= sprintf('<p><button type="submit" class="button button-primary">%s</button> ', esc_html__('Save robots.txt', 'tqk-wpseo'));
        $html .= sprintf('<button type="button" class="button" data-tqk-robots-reset>%s</button></p></form>', esc_html__('Reset to default', 'tqk-wpseo'));
        return $html;
    }

    public static function schema(): void {
        if (!self::guard()) {
            self::denied();
            return;
        }
        $data = \TQK_WPSEO\Core\Data::for(['type' => 'home']);
        $graph = \TQK_WPSEO\Schema\Schema::build(['type' => 'home'], $data);
        $pretty = (string) wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph->nodes()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $map = \TQK_WPSEO\Admin\Settings::get('schema_map', []);
        $rows = [];
        $pts = function_exists('get_post_types') ? array_keys((array) get_post_types(['public' => true])) : ['post', 'page'];
        $pts = array_values(array_unique(array_merge(['post', 'page', 'product'], $pts)));
        foreach ($pts as $pt) {
            $pto = function_exists('get_post_type_object') ? get_post_type_object($pt) : null;
            $label = $pto && isset($pto->labels->singular_name) ? (string) $pto->labels->singular_name : ucfirst($pt);
            $rows[] = [$label, (string) (is_array($map) ? ($map[$pt] ?? '—') : '—'), ''];
        }
        $content = '<h2 class="tqk-wpseo-section">' . esc_html__('Templates', 'tqk-wpseo') . '</h2>'
            . View::issue_table([__('Post type', 'tqk-wpseo'), __('Schema type', 'tqk-wpseo'), ''], $rows)
            . sprintf(
                '<p><a class="tqk-btn tqk-btn-outline" href="%s">%s</a></p>',
                esc_url(admin_url('admin.php?page=tqk-wpseo-settings')),
                esc_html__('Edit in Settings', 'tqk-wpseo')
            )
            . self::schema_rules_form()
            . '<p>' . esc_html__('One script tag with a single @graph is printed in wp_head. Homepage preview:', 'tqk-wpseo') . '</p>'
            . '<pre class="tqk-wpseo-code">' . esc_html($pretty) . '</pre>'
            . '<p>' . esc_html__('Article, WebPage and BreadcrumbList nodes are added on singular views.', 'tqk-wpseo') . '</p>';
        View::page(__('Schema', 'tqk-wpseo'), $content);
    }

    /** Conditional schema rules: post type (+ optional taxonomy term) → type. Priority: override → rules → map. */
    private static function schema_rules_form(): string {
        $rules = \TQK_WPSEO\Admin\Settings::get('schema_rules', []);
        $rules = is_array($rules) ? array_values($rules) : [];
        $types = ['Article' => 'Article', 'BlogPosting' => 'BlogPosting', 'WebPage' => 'WebPage', 'Product' => 'Product', 'Course' => 'Course', 'FAQPage' => 'FAQPage', 'HowTo' => 'HowTo', 'Recipe' => 'Recipe', 'Event' => 'Event'];
        $html = '<h2 class="tqk-wpseo-section">' . esc_html__('Conditional rules (Pro-style builder)', 'tqk-wpseo') . '</h2>'
            . '<form data-tqk-form="settings"><div class="tqk-wpseo-table-wrap"><div class="table-responsive"><table class="tqk-wpseo-table"><thead><tr>'
            . '<th>' . esc_html__('Post type', 'tqk-wpseo') . '</th><th>' . esc_html__('Taxonomy', 'tqk-wpseo') . '</th>'
            . '<th>' . esc_html__('Term slug (empty = any)', 'tqk-wpseo') . '</th><th>' . esc_html__('Schema type', 'tqk-wpseo') . '</th>'
            . '</tr></thead><tbody>';
        $i = 0;
        foreach (array_merge($rules, [['post_type' => '', 'taxonomy' => '', 'term' => '', 'schema_type' => '']]) as $r) {
            $opts = '';
            foreach ($types as $v => $l) {
                $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($v), ((string) ($r['schema_type'] ?? '') === $v) ? ' selected' : '', esc_html($l));
            }
            $html .= sprintf(
                '<tr><td><input type="text" name="settings[schema_rules][%d][post_type]" value="%s" placeholder="post" size="12"></td>'
                . '<td><input type="text" name="settings[schema_rules][%d][taxonomy]" value="%s" placeholder="category" size="12"></td>'
                . '<td><input type="text" name="settings[schema_rules][%d][term]" value="%s" size="14"></td>'
                . '<td><select name="settings[schema_rules][%d][schema_type]">%s</select></td></tr>',
                $i,
                esc_attr((string) ($r['post_type'] ?? '')),
                $i,
                esc_attr((string) ($r['taxonomy'] ?? '')),
                $i,
                esc_attr((string) ($r['term'] ?? '')),
                $i,
                $opts
            );
            $i++;
        }
        $html .= '</tbody></table></div></div>'
            . sprintf('<p><button type="submit" class="button button-primary">%s</button> <span class="description">%s</span></p></form>', esc_html__('Save rules', 'tqk-wpseo'), esc_html__('Blank rows are ignored. First match wins.', 'tqk-wpseo'));
        return $html;
    }
}
