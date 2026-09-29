<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

/**
 * S0.1 — Admin menu. One top-level item, ten submenus (map to docs/drafts/00-PLAN-MASTER.md).
 * Page bodies live in Pages.php; layout shell in templates/admin-page.php.
 */
class Menu {

    public const TOP_SLUG = 'tqk-wpseo';

    /** @return array Slug => [title, method]. Inactive modules hide their pages. */
    public static function items(): array {
        $all = [
            'tqk-wpseo' => [__('Dashboard', 'tqk-wpseo'), 'dashboard', ''],
            'tqk-wpseo-modules' => [__('Modules', 'tqk-wpseo'), 'modules', ''],
            'tqk-wpseo-site-analysis' => [__('Analytics website', 'tqk-wpseo'), 'site_analysis', 'analytics'],
            'tqk-wpseo-sitemap' => [__('Sitemap', 'tqk-wpseo'), 'sitemap', 'sitemap'],
            'tqk-wpseo-schema' => [__('Schema', 'tqk-wpseo'), 'schema', 'schema'],
            'tqk-wpseo-redirects' => [__('Redirects', 'tqk-wpseo'), 'redirects', 'redirects'],
            'tqk-wpseo-404' => [__('404 Monitor', 'tqk-wpseo'), 'monitor_404', 'monitor-404'],
            'tqk-wpseo-links' => [__('Internal Links', 'tqk-wpseo'), 'links', 'links'],
            'tqk-wpseo-security' => [__('Security scan', 'tqk-wpseo'), 'security', ''],
            'tqk-wpseo-settings' => [__('Settings', 'tqk-wpseo'), 'settings', ''],
            'tqk-wpseo-tools' => [__('Tools', 'tqk-wpseo'), 'tools', ''],
        ];
        $out = [];
        foreach ($all as $slug => [$title, $method, $module]) {
            if ($module !== '' && !\TQK_WPSEO\Core\Modules::is_active($module)) {
                continue;
            }
            $out[$slug] = [$title, $method];
        }
        return $out;
    }

    public static function register(): void {
        add_menu_page(
            __('TQK SEO', 'tqk-wpseo'),
            __('TQK SEO', 'tqk-wpseo'),
            \TQK_WPSEO\Admin\Access::CAP,
            self::TOP_SLUG,
            [Pages::class, 'dashboard'],
            'dashicons-chart-area',
            90
        );

        /*
         * Stabilize submenu hook names. Core rewrites
         * $admin_page_hooks[TOP] to basename(slug) when building the menu
         * (wp-admin/includes/menu.php), AFTER we registered. Without this,
         * hook names computed at registration (tqk-seo_page_*) differ from
         * the ones at render/dispatch (tqk-wpseo_page_*), producing raw-slug
         * hrefs and 403s. Setting it to the slug up front keeps both in sync.
         */
        $GLOBALS['admin_page_hooks'][self::TOP_SLUG] = self::TOP_SLUG;

        foreach (self::items() as $slug => [$title, $method]) {
            add_submenu_page(
                self::TOP_SLUG,
                sprintf('%s — %s', $title, __('TQK SEO', 'tqk-wpseo')),
                $title,
                \TQK_WPSEO\Admin\Access::CAP,
                $slug,
                [Pages::class, $method]
            );
        }
    }
}
