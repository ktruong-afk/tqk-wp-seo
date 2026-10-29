<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * What the separate "TQK WP SEO Pro" add-on must provide. The free plugin
 * holds no Pro code: licensing and the image file generator live in the
 * add-on, so changing a line here cannot unlock anything.
 */
interface ProAddon {
    /** Add-on version, e.g. "0.1.0". */
    public function version(): string;

    /** True while the site holds a valid (or in-grace) license. */
    public function is_licensed(): bool;

    /** Short site fingerprint printed in the page-source banner ('' if none). */
    public function fingerprint(): string;

    /** HTML of the license box shown in Settings. */
    public function settings_html(): string;
}

/**
 * Hook point between the free plugin and the Pro add-on.
 *
 * Free = every diagnosis (audit, snapshots, plan, manual image steps, MCP).
 * Pro  = automation that does work on the server (image files, "audit all").
 */
class Pro {

    /** Where to get Pro (contact page until the shop is online). */
    public const BUY_URL = 'https://www.facebook.com/Unk.khai/';

    private static ?ProAddon $addon = null;

    /** Called by the add-on on plugins_loaded. */
    public static function register(ProAddon $addon): void {
        self::$addon = $addon;
    }

    /** Tests only. */
    public static function reset(): void {
        self::$addon = null;
    }

    public static function installed(): bool {
        return self::$addon !== null;
    }

    public static function addon(): ?ProAddon {
        return self::$addon;
    }

    /**
     * Pro features on: add-on installed AND licensed. The filter can only
     * turn Pro OFF (tests, staging), never on.
     */
    public static function active(): bool {
        return self::$addon !== null && self::$addon->is_licensed() && (bool) apply_filters('tqk_wpseo_is_pro', true);
    }

    /** Page-source banner: "TQK WP SEO v0.2.0" or "TQK WP SEO PRO v0.2.0 · 1a2b3c4d5e6f". */
    public static function banner(): string {
        $v = defined('TQK_WPSEO_VERSION') ? TQK_WPSEO_VERSION : '';
        if (!self::active()) {
            return 'TQK WP SEO v' . $v;
        }
        $fp = self::$addon->fingerprint();
        return 'TQK WP SEO PRO v' . $v . ' · ' . ($fp !== '' ? $fp : 'no-key');
    }

    /** Where Pro buttons point: the license box when installed, else the buy page. */
    public static function url(): string {
        return self::installed() ? admin_url('admin.php?page=tqk-wpseo-settings#tqk-license') : self::BUY_URL;
    }

    /** Settings box: the add-on's license form, or how to get Pro. */
    public static function settings_html(): string {
        if (self::$addon !== null) {
            return self::$addon->settings_html();
        }
        return '<div id="tqk-license"><p><span class="tqk-wpseo-badge is-wait">' . esc_html__('Free', 'tqk-wp-seo') . '</span></p>'
            . '<p class="description">' . esc_html__('Pro is a separate add-on plugin ("TQK WP SEO Pro"). Install and activate it, then enter your license key here.', 'tqk-wp-seo') . '</p>'
            . '<p><a class="button" href="' . esc_url(self::BUY_URL) . '" target="_blank" rel="noopener">' . esc_html__('Get TQK WP SEO Pro', 'tqk-wp-seo') . '</a></p></div>';
    }
}
