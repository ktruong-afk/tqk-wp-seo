<?php
namespace TQK_WPSEO\Report;

defined('ABSPATH') || exit;

use TQK_WPSEO\Admin\Settings;
use TQK_WPSEO\Analyzer\LiveAudit;

/**
 * White-label SEO report for agencies: one printable page built from the
 * last Live Audit (+ security scan and image optimizer when they ran),
 * with the agency's name, logo, colour and contact instead of ours.
 *
 * PDF = the browser's own Print → Save as PDF: no PDF library to ship, and
 * the output is pixel-identical to what the agency previews on screen.
 */
class Report {

    public const ACTION = 'tqk_wpseo_report';

    public static function hooks(): void {
        add_action('admin_post_' . self::ACTION, [self::class, 'render']);
    }

    public static function url(): string {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION);
    }

    /** Aggregates for the summary block. Pure over the given rows. */
    public static function summarize(array $rows): array {
        $n = count($rows);
        $seo = 0;
        $rs = 0;
        $crit = 0;
        $failed = 0;
        $issues = [];
        foreach ($rows as [$item, $rec]) {
            if (!empty($rec['error'])) {
                $failed++;
                continue;
            }
            $seo += (int) ($rec['seo']['score'] ?? 0);
            $rs += LiveAudit::min_vp($rec);
            foreach ((array) ($rec['seo']['findings'] ?? []) as $f) {
                if (($f['sev'] ?? '') === 'pass') {
                    continue;
                }
                if ($f['sev'] === 'critical') {
                    $crit++;
                }
                // Group "Title over 60 chars (72)" and "(65)" together.
                $key = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\s*\(.*\)\s*$|\d+/', '', (string) $f['msg'])));
                $issues[$key] = ($issues[$key] ?? 0) + 1;
            }
            foreach ((array) ($rec['fields'] ?? []) as $m) {
                $key = trim((string) preg_replace('/\s+—\s+".*$/u', '', (string) $m));
                $issues[$key] = ($issues[$key] ?? 0) + 1;
            }
        }
        arsort($issues);
        $ok = max(1, $n - $failed);
        return [
            'pages' => $n,
            'failed' => $failed,
            'seo_avg' => (int) round($seo / $ok),
            'rs_avg' => (int) round($rs / $ok),
            'critical' => $crit,
            'top' => array_slice($issues, 0, 10, true),
        ];
    }

    /** @return list<array{0:array,1:array}> [item, record] of every finished page of the run. */
    public static function rows(array $run): array {
        $out = [];
        $st = (string) ($run['st'] ?? '');
        foreach ((array) ($run['ids'] ?? []) as $i => $id) {
            if (($st[$i] ?? '.') === '.') {
                continue;
            }
            $rec = LiveAudit::record((int) $id, (string) $run['id']);
            if ($rec !== null) {
                $out[] = [LiveAudit::item((int) $id), $rec];
            }
        }
        return $out;
    }

    /**
     * Report language, independent of the admin's own language: an agency
     * working in English still sends a Vietnamese report. Works without the
     * WordPress core language pack (only our .mo is needed).
     */
    public static function use_locale(string $locale): void {
        if ($locale === '') {
            return;
        }
        $force = static fn() => $locale;
        add_filter('determine_locale', $force, 99);
        add_filter('locale', $force, 99);
        if (class_exists('WP_Translation_Controller')) {
            \WP_Translation_Controller::get_instance()->set_locale($locale);
        }
        unload_textdomain('tqk-wp-seo');
        if ($locale !== 'en_US') {
            load_plugin_textdomain('tqk-wp-seo', false, basename(rtrim(TQK_WPSEO_DIR, '/\\')) . '/languages');
        }
    }

    public static function render(): void {
        if (!\TQK_WPSEO\Admin\Access::can()) {
            wp_die(esc_html__('Permission denied', 'tqk-wp-seo'));
        }
        check_admin_referer(self::ACTION);
        $locale = (string) Settings::get('report_locale', '');
        self::use_locale(in_array($locale, ['vi', 'en_US'], true) ? $locale : '');
        $run = LiveAudit::run();
        $rows = self::rows($run);
        $sum = self::summarize($rows);
        $brand = trim((string) Settings::get('report_brand_name', '')) ?: (string) get_bloginfo('name');
        $logo = (string) Settings::get('report_brand_logo', '');
        $color = sanitize_hex_color((string) Settings::get('report_brand_color', '#4f46e5')) ?: '#4f46e5';
        $contact = (string) Settings::get('report_brand_contact', '');
        $site = (string) get_bloginfo('name');
        // Numeric date for Vietnamese: month names need the core language pack.
        $date = $locale === 'vi' ? date_i18n('d/m/Y') : date_i18n((string) get_option('date_format', 'd/m/Y'));
        $tone = static fn(int $s) => $s >= 80 ? 'good' : ($s >= 50 ? 'ok' : 'bad');

        $sec = \TQK_WPSEO\Security\Scan::state();
        $sec_crit = count(array_filter((array) ($sec['findings'] ?? []), static fn($f) => ($f['sev'] ?? '') === 'critical'));
        $saved = 0;
        foreach ($rows as [$item]) {
            $plan = (int) $item['id'] > 0 ? \TQK_WPSEO\Analyzer\ImageFix::plan((int) $item['id']) : [];
            if (!empty($plan['active'])) {
                $saved += max(0, (int) ($plan['bytes_before'] ?? 0) - (int) ($plan['bytes_after'] ?? 0));
            }
        }

        // Worst pages first; the report shows up to 40 with snapshots.
        usort($rows, static fn($a, $b) => ((int) ($a[1]['seo']['score'] ?? 0) + LiveAudit::min_vp($a[1])) <=> ((int) ($b[1]['seo']['score'] ?? 0) + LiveAudit::min_vp($b[1])));
        $pages = '';
        foreach (array_slice($rows, 0, 40) as [$item, $rec]) {
            $s = (int) ($rec['seo']['score'] ?? 0);
            $r = LiveAudit::min_vp($rec);
            $top = [];
            foreach ((array) ($rec['seo']['findings'] ?? []) as $f) {
                if (($f['sev'] ?? '') !== 'pass' && count($top) < 4) {
                    $top[] = '<li>' . esc_html((string) $f['msg']) . '</li>';
                }
            }
            foreach ((array) ($rec['fields'] ?? []) as $m) {
                if (count($top) < 5) {
                    $top[] = '<li>' . esc_html((string) $m) . '</li>';
                }
            }
            $shots = '';
            foreach (['desktop', 'mobile'] as $k) {
                $u = LiveAudit::file_url((string) ($rec['shots'][$k] ?? ''));
                if ($u !== '') {
                    $shots .= '<img class="shot shot-' . $k . '" src="' . esc_url($u) . '" alt="">';
                }
            }
            $pages .= '<section class="page-card">'
                . '<div class="pc-head"><div><h3>' . esc_html((string) ($item['title'] ?: $item['url'])) . '</h3><a href="' . esc_url((string) $item['url']) . '">' . esc_html((string) $item['url']) . '</a></div>'
                . '<div class="scores"><span class="score ' . $tone($s) . '">' . $s . '<small>SEO</small></span><span class="score ' . $tone($r) . '">' . $r . '<small>' . esc_html__('Mobile', 'tqk-wp-seo') . '</small></span></div></div>'
                . '<div class="pc-body"><ul>' . (implode('', $top) ?: '<li class="clean">' . esc_html__('No issues', 'tqk-wp-seo') . '</li>') . '</ul><div class="shots">' . $shots . '</div></div>'
                . '</section>';
        }

        $top_issues = '';
        foreach ($sum['top'] as $msg => $count) {
            $top_issues .= '<tr><td>' . esc_html((string) $msg) . '</td><td class="num">' . (int) $count . '</td></tr>';
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="' . esc_attr($locale === 'vi' ? 'vi' : ($locale === 'en_US' ? 'en' : (string) get_bloginfo('language'))) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html(sprintf(__('SEO report — %s', 'tqk-wp-seo'), $site)) . '</title>'
            . '<style>' . self::css($color) . '</style></head><body>'
            . '<div class="toolbar"><button onclick="window.print()">' . esc_html__('Print / Save as PDF', 'tqk-wp-seo') . '</button></div>'
            . '<header class="cover"><div class="brand">' . ($logo !== '' ? '<img src="' . esc_url($logo) . '" alt="">' : '') . '<strong>' . esc_html($brand) . '</strong></div>'
            . '<h1>' . esc_html__('SEO & website health report', 'tqk-wp-seo') . '</h1>'
            . '<p class="site">' . esc_html($site) . ' · ' . esc_html(home_url('/')) . '</p><p class="date">' . esc_html($date) . '</p></header>'
            . '<section class="kpis">'
            . self::kpi((string) $sum['pages'], __('Pages audited', 'tqk-wp-seo'))
            . self::kpi($sum['seo_avg'] . '/100', __('Average SEO score', 'tqk-wp-seo'), $tone($sum['seo_avg']))
            . self::kpi($sum['rs_avg'] . '/100', __('Average mobile score', 'tqk-wp-seo'), $tone($sum['rs_avg']))
            . self::kpi((string) $sum['critical'], __('Critical issues', 'tqk-wp-seo'), $sum['critical'] > 0 ? 'bad' : 'good')
            . ($sec !== [] && !empty($sec['finished']) ? self::kpi((string) $sec_crit, __('Security alerts', 'tqk-wp-seo'), $sec_crit > 0 ? 'bad' : 'good') : '')
            . ($saved > 0 ? self::kpi(\TQK_WPSEO\Analyzer\ImageFix::fmt_bytes($saved), __('Lighter on phones (images)', 'tqk-wp-seo'), 'good') : '')
            . '</section>'
            . ($top_issues !== '' ? '<section><h2>' . esc_html__('Most frequent issues', 'tqk-wp-seo') . '</h2><table>' . $top_issues . '</table></section>' : '')
            . '<section><h2>' . esc_html__('Page by page (worst first)', 'tqk-wp-seo') . '</h2>' . $pages . '</section>'
            . '<footer><strong>' . esc_html($brand) . '</strong>' . ($contact !== '' ? ' · ' . esc_html($contact) : '') . '</footer>'
            . '</body></html>';
        exit;
    }

    private static function kpi(string $value, string $label, string $tone = ''): string {
        return '<div class="kpi ' . esc_attr($tone) . '"><b>' . esc_html($value) . '</b><span>' . esc_html($label) . '</span></div>';
    }

    private static function css(string $c): string {
        return ':root{--brand:' . $c . ';--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--good:#059669;--ok:#d97706;--bad:#e11d48}'
            . '*{box-sizing:border-box}body{margin:0;font:14px/1.5 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);background:#f1f5f9}'
            . 'body>*{max-width:900px;margin:0 auto 16px;padding:0 16px}'
            . '.toolbar{position:sticky;top:0;padding:10px 16px;text-align:right;background:#f1f5f9;z-index:2}.toolbar button{background:var(--brand);color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:700;cursor:pointer}'
            . '.cover{background:var(--brand);color:#fff;border-radius:14px;padding:28px}.cover .brand{display:flex;align-items:center;gap:12px}.cover .brand img{max-height:44px;background:#fff;border-radius:8px;padding:4px}'
            . '.cover h1{margin:18px 0 4px;font-size:26px}.cover .site{margin:0;opacity:.9}.cover .date{margin:2px 0 0;opacity:.75}'
            . '.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.kpi{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px}.kpi b{display:block;font-size:24px}.kpi span{color:var(--muted);font-size:12px}'
            . '.kpi.good b{color:var(--good)}.kpi.ok b{color:var(--ok)}.kpi.bad b{color:var(--bad)}'
            . 'h2{font-size:17px;margin:22px 0 10px;color:var(--brand)}table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}td{padding:8px 12px;border-top:1px solid var(--line)}td.num{text-align:right;font-weight:700;width:60px}'
            . '.page-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px;margin-bottom:10px;break-inside:avoid}'
            . '.pc-head{display:flex;justify-content:space-between;gap:12px}.pc-head h3{margin:0;font-size:15px}.pc-head a{font-size:12px;color:var(--muted);word-break:break-all}'
            . '.scores{display:flex;gap:6px}.score{min-width:56px;text-align:center;border-radius:10px;padding:4px 8px;font-weight:800;font-size:18px;background:#f8fafc}.score small{display:block;font-size:10px;font-weight:600;color:var(--muted)}'
            . '.score.good{color:var(--good)}.score.ok{color:var(--ok)}.score.bad{color:var(--bad)}'
            . '.pc-body{display:flex;gap:12px;margin-top:8px}.pc-body ul{flex:1;margin:0;padding-left:18px;font-size:13px}.pc-body li.clean{color:var(--good);list-style:none;margin-left:-18px}'
            . '.shots{display:flex;gap:6px;align-items:flex-start}.shot{border:1px solid var(--line);border-radius:6px;height:150px;width:auto;object-fit:cover;object-position:top}.shot-desktop{width:150px}.shot-mobile{width:60px}'
            . 'footer{color:var(--muted);text-align:center;padding:16px}'
            . '@media print{body{background:#fff}.toolbar{display:none}body>*{max-width:none}.cover{-webkit-print-color-adjust:exact;print-color-adjust:exact}.score,.kpi{-webkit-print-color-adjust:exact;print-color-adjust:exact}}'
            . '@media (max-width:600px){.pc-head,.pc-body{flex-direction:column}}';
    }
}
