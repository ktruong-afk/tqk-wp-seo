<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

/**
 * "TQK SEO" on the WordPress toolbar, on the front end, for people who can
 * edit the page they are looking at: last SEO score, and "Audit this page"
 * — the same engine as Live Audit and the editor Scan (visible stage, 4
 * screen sizes, snapshots, plan, security), run right there. The report is
 * stored like the others, so the editor, the dashboard and MCP see it.
 */
class AdminBar {

    public static function hooks(): void {
        add_action('admin_bar_menu', [self::class, 'menu'], 90);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
    }

    /** Front end, toolbar shown, not inside an audit frame, analyzer on. */
    private static function active(): bool {
        return !is_admin()
            && is_admin_bar_showing()
            && !\TQK_WPSEO\Analyzer\LiveAudit::in_frame()
            && \TQK_WPSEO\Core\Modules::is_active('analyzer')
            && Access::can();
    }

    /** Post shown on this screen (0 = home / archive: audited, not stored). */
    private static function post_id(): int {
        if (is_singular()) {
            $id = (int) get_queried_object_id();
            return $id > 0 && current_user_can('edit_post', $id) ? $id : 0;
        }
        if (is_front_page() && get_option('show_on_front') === 'page') {
            return (int) get_option('page_on_front');
        }
        return 0;
    }

    public static function menu($bar): void {
        if (!self::active() || !is_object($bar)) {
            return;
        }
        $id = self::post_id();
        $r = $id > 0 ? \TQK_WPSEO\MCP\Reports::get($id) : null;
        $score = $r !== null ? (int) ($r['score'] ?? 0) : null;
        $tone = $score === null ? '' : ($score >= 80 ? ' is-good' : ($score >= 50 ? ' is-ok' : ' is-bad'));
        $bar->add_node([
            'id' => 'tqk-wpseo',
            'title' => '<span class="ab-icon tqk-ab-icon" aria-hidden="true"></span><span class="ab-label">TQK SEO</span>'
                . '<span class="tqk-ab-score' . $tone . '">' . ($score === null ? '–' : $score) . '</span>',
            'href' => admin_url('admin.php?page=tqk-wpseo'),
        ]);
        $bar->add_node([
            'parent' => 'tqk-wpseo',
            'id' => 'tqk-wpseo-audit',
            'title' => esc_html__('Audit this page (4 screens)', 'tqk-wp-seo'),
            'href' => '#tqk-audit',
            'meta' => ['class' => 'tqk-ab-audit'],
        ]);
        if ($r !== null) {
            $n = [1 => 0, 2 => 0];
            foreach ((array) ($r['plan'] ?? []) as $it) {
                $p = (int) ($it['prio'] ?? 3);
                if (isset($n[$p])) {
                    $n[$p]++;
                }
            }
            $bar->add_node([
                'parent' => 'tqk-wpseo',
                'id' => 'tqk-wpseo-last',
                'title' => esc_html(sprintf(__('Last plan: %1$d fix now · %2$d should fix', 'tqk-wp-seo'), $n[1], $n[2])),
                'href' => '#tqk-last',
                'meta' => ['class' => 'tqk-ab-last'],
            ]);
        }
        if ($id > 0) {
            $bar->add_node([
                'parent' => 'tqk-wpseo',
                'id' => 'tqk-wpseo-edit',
                'title' => esc_html__('Edit SEO of this page', 'tqk-wp-seo'),
                'href' => (string) get_edit_post_link($id, 'raw') . '#tqk-wpseo',
            ]);
        }
        $bar->add_node([
            'parent' => 'tqk-wpseo',
            'id' => 'tqk-wpseo-analytics',
            'title' => esc_html__('Analytics website', 'tqk-wp-seo'),
            'href' => admin_url('admin.php?page=tqk-wpseo-site-analysis'),
        ]);
    }

    public static function assets(): void {
        if (!self::active()) {
            return;
        }
        $ver = static function (string $rel): string {
            $m = @filemtime(TQK_WPSEO_DIR . $rel);
            return $m ? TQK_WPSEO_VERSION . '.' . $m : TQK_WPSEO_VERSION;
        };
        $deps = [];
        foreach (['tqk-wpseo-stage', 'tqk-wpseo-snapshot', 'tqk-wpseo-responsive', 'tqk-wpseo-sections', 'tqk-wpseo-plan'] as $h) {
            wp_enqueue_script($h, TQK_WPSEO_URL . 'assets/js/' . $h . '.js', [], $ver('assets/js/' . $h . '.js'), true);
            $deps[] = $h;
        }
        wp_enqueue_script('tqk-wpseo-adminbar', TQK_WPSEO_URL . 'assets/js/tqk-wpseo-adminbar.js', $deps, $ver('assets/js/tqk-wpseo-adminbar.js'), true);
        // Plan / report styles are shared with the editor.
        wp_enqueue_style('tqk-wpseo-metabox', TQK_WPSEO_URL . 'assets/css/tqk-wpseo-metabox.css', [], $ver('assets/css/tqk-wpseo-metabox.css'));
        wp_enqueue_style('tqk-wpseo-adminbar', TQK_WPSEO_URL . 'assets/css/tqk-wpseo-adminbar.css', ['tqk-wpseo-metabox'], $ver('assets/css/tqk-wpseo-adminbar.css'));
        $id = self::post_id();
        $last = $id > 0 ? \TQK_WPSEO\MCP\Reports::get($id) : null;
        if ($last !== null) {
            $last['shots'] = array_filter(array_map([\TQK_WPSEO\Analyzer\LiveAudit::class, 'file_url'], array_map('strval', (array) ($last['shots'] ?? []))));
        }
        wp_localize_script('tqk-wpseo-adminbar', 'tqkWpseoBar', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'postId' => $id,
            'editUrl' => $id > 0 ? (string) get_edit_post_link($id, 'raw') . '#tqk-wpseo' : '',
            'last' => $last,
            't' => Metabox::js_strings() + [
                'barTitle' => __('TQK SEO — this page', 'tqk-wp-seo'),
                'barClose' => __('Close', 'tqk-wp-seo'),
                'barOpenEditor' => __('Open in the editor to fix', 'tqk-wp-seo'),
                'barNotStored' => __('Archive / listing page: the result is shown here but not stored (reports belong to posts and pages).', 'tqk-wp-seo'),
                'barSaved' => __('Saved: the editor, the dashboard and MCP now show this result.', 'tqk-wp-seo'),
            ],
        ]);
    }
}
