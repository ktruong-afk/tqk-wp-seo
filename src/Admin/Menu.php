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
            'tqk-wpseo' => [__('Dashboard', 'tqk-wp-seo'), 'dashboard', ''],
            'tqk-wpseo-modules' => [__('Modules', 'tqk-wp-seo'), 'modules', ''],
            'tqk-wpseo-site-analysis' => [__('Analytics website', 'tqk-wp-seo'), 'site_analysis', 'analytics'],
            'tqk-wpseo-sitemap' => [__('Sitemap', 'tqk-wp-seo'), 'sitemap', 'sitemap'],
            'tqk-wpseo-schema' => [__('Schema', 'tqk-wp-seo'), 'schema', 'schema'],
            'tqk-wpseo-redirects' => [__('Redirects', 'tqk-wp-seo'), 'redirects', 'redirects'],
            'tqk-wpseo-404' => [__('404 Monitor', 'tqk-wp-seo'), 'monitor_404', 'monitor-404'],
            'tqk-wpseo-links' => [__('Internal Links', 'tqk-wp-seo'), 'links', 'links'],
            'tqk-wpseo-security' => [__('Security scan', 'tqk-wp-seo'), 'security', ''],
            'tqk-wpseo-settings' => [__('Settings', 'tqk-wp-seo'), 'settings', ''],
            'tqk-wpseo-tools' => [__('Tools', 'tqk-wp-seo'), 'tools', ''],
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
            __('TQK SEO', 'tqk-wp-seo'),
            __('TQK SEO', 'tqk-wp-seo'),
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
                sprintf('%s — %s', $title, __('TQK SEO', 'tqk-wp-seo')),
                $title,
                \TQK_WPSEO\Admin\Access::CAP,
                $slug,
                [Pages::class, $method]
            );
        }
    }
}
