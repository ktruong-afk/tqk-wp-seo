<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

/**
 * S0.2 — Assets. Tailwind-modern design system (no Bootstrap).
 * Scoped under .tqk-wpseo so nothing leaks into wp-admin.
 */
class Assets {

    public static function hooks(): void {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function is_plugin_screen(?string $hook): bool {
        if ($hook === null || $hook === '') {
            return false;
        }
        return $hook === 'toplevel_page_tqk-wpseo'
            || (bool) preg_match('/_page_tqk-wpseo(-|$)/', $hook);
    }

    private static function ver(string $rel): string {
        $mtime = @filemtime(TQK_WPSEO_DIR . $rel);
        return $mtime ? TQK_WPSEO_VERSION . '.' . $mtime : TQK_WPSEO_VERSION;
    }

    public static function enqueue(?string $hook = null): void {
        \TQK_WPSEO\Admin\Metabox::assets((string) ($hook ?? ''));
        if (!self::is_plugin_screen($hook ?? '')) {
            return;
        }

        // Hide Hello Dolly lyric (#dolly) on our screens for a clean UI.
        remove_action('admin_notices', 'hello_dolly');
        remove_action('admin_head', 'dolly_css');

        wp_enqueue_style(
            'tqk-wpseo-admin',
            TQK_WPSEO_URL . 'assets/css/tqk-wpseo-admin.css',
            [],
            self::ver('assets/css/tqk-wpseo-admin.css')
        );
        wp_enqueue_script(
            'tqk-wpseo-admin',
            TQK_WPSEO_URL . 'assets/js/tqk-wpseo-admin.js',
            [],
            self::ver('assets/js/tqk-wpseo-admin.js'),
            true
        );
        wp_localize_script('tqk-wpseo-admin', 'tqkWpseoAjax', ['nonce' => wp_create_nonce(Ajax::NONCE)]);
        wp_enqueue_script(
            'tqk-wpseo-tools',
            TQK_WPSEO_URL . 'assets/js/tqk-wpseo-tools.js',
            ['tqk-wpseo-admin'],
            self::ver('assets/js/tqk-wpseo-tools.js'),
            true
        );
        // Settings has a site-wide default share image, also a media picker.
        wp_enqueue_media();
        if ($hook === 'tqk-wpseo_page_tqk-wpseo-site-analysis') {
            wp_enqueue_script(
                'tqk-wpseo-scanner',
                TQK_WPSEO_URL . 'assets/js/tqk-wpseo-scanner.js',
                [],
                self::ver('assets/js/tqk-wpseo-scanner.js'),
                true
            );
            wp_localize_script('tqk-wpseo-scanner', 'tqkWpseoScan', ['nonce' => wp_create_nonce('tqk-wpseo-scan')]);

            wp_enqueue_script(
                'tqk-wpseo-advisor',
                TQK_WPSEO_URL . 'assets/js/tqk-wpseo-advisor.js',
                [],
                self::ver('assets/js/tqk-wpseo-advisor.js'),
                true
            );
            wp_enqueue_script(
                'tqk-wpseo-stage',
                TQK_WPSEO_URL . 'assets/js/tqk-wpseo-stage.js',
                [],
                self::ver('assets/js/tqk-wpseo-stage.js'),
                true
            );
            // Live audit: real iframe measurement + full-page snapshot, all client-side.
            wp_enqueue_script(
                'tqk-wpseo-snapshot',
                TQK_WPSEO_URL . 'assets/js/tqk-wpseo-snapshot.js',
                [],
                self::ver('assets/js/tqk-wpseo-snapshot.js'),
                true
            );
            wp_enqueue_script(
                'tqk-wpseo-responsive',
                TQK_WPSEO_URL . 'assets/js/tqk-wpseo-responsive.js',
                [],
                self::ver('assets/js/tqk-wpseo-responsive.js'),
                true
            );
            // Same action plan / page report as the post editor's Scan.
            wp_enqueue_script(
                'tqk-wpseo-plan',
                TQK_WPSEO_URL . 'assets/js/tqk-wpseo-plan.js',
                [],
                self::ver('assets/js/tqk-wpseo-plan.js'),
                true
            );
            wp_enqueue_script(
                'tqk-wpseo-responsive-ui',
                TQK_WPSEO_URL . 'assets/js/tqk-wpseo-responsive-ui.js',
                [],
                self::ver('assets/js/tqk-wpseo-responsive-ui.js'),
                true
            );
        }
    }
}
