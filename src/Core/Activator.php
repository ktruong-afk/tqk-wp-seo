<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * S0.4 — Activation: checks, custom tables, settings, postmeta, rewrite flush.
 * Deactivation never deletes data (Reset lives in Tools, later stage).
 */
class Activator {

    public static function activate(): void {
        global $wpdb;

        self::check_requirements();
        self::create_tables($wpdb);
        self::seed_options();
        self::register_meta();

        update_option(TQK_WPSEO_VERSION_OPTION, TQK_WPSEO_VERSION);
        self::migrate_secrets();
        update_option('tqk_wpseo_wizard_pending', 1);
        \TQK_WPSEO\Admin\Access::ensure_admin();

        // S3 will register sitemap rewrites; flush here so later stages start clean.
        flush_rewrite_rules();
    }

    private static function check_requirements(): void {
        global $wp_version;

        if (version_compare(PHP_VERSION, '8.0', '<')) {
            wp_die(esc_html__('TQK WP SEO requires PHP 8.0 or higher.', 'tqk-wpseo'));
        }
        if (isset($wp_version) && version_compare($wp_version, '6.0', '<')) {
            wp_die(esc_html__('TQK WP SEO requires WordPress 6.0 or higher.', 'tqk-wpseo'));
        }
    }
    /** @return string[] Created table names (without prefix handling for tests). */
    public static function table_names(string $prefix): array {
        return [
            $prefix . 'tqk_wpseo_redirects',
            $prefix . 'tqk_wpseo_404',
            $prefix . 'tqk_wpseo_links',
            $prefix . 'tqk_wpseo_scan',
            $prefix . 'tqk_wpseo_gsc',
        ];
    }

    private static function create_tables($wpdb): void {
        $charset = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        [$redirects, $not_found, $links, $scan, $gsc] = self::table_names($wpdb->prefix);

        // dbDelta is admin-only; loaded on demand for robustness.
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta("CREATE TABLE {$redirects} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source VARCHAR(255) NOT NULL,
            target TEXT NOT NULL,
            type SMALLINT NOT NULL DEFAULT 301,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source (source),
            KEY active (active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$not_found} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url VARCHAR(255) NOT NULL,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_hit DATETIME NULL,
            referrer TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY url (url),
            KEY hits (hits)
        ) {$charset};");

        dbDelta("CREATE TABLE {$links} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(20) NOT NULL DEFAULT 'post',
            target_id BIGINT UNSIGNED NULL,
            url TEXT NOT NULL,
            anchor TEXT NULL,
            is_internal TINYINT NOT NULL DEFAULT 0,
            is_broken TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY source_id (source_id),
            KEY target_id (target_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$scan} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id VARCHAR(64) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            object_type VARCHAR(20) NOT NULL DEFAULT 'post',
            group_scores TEXT NULL,
            issues TEXT NULL,
            score SMALLINT NOT NULL DEFAULT 0,
            evaluated SMALLINT NOT NULL DEFAULT 0,
            scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY job_id (job_id),
            KEY object_id (object_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$gsc} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day DATE NOT NULL,
            query VARCHAR(255) NOT NULL DEFAULT '',
            clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ctr FLOAT NOT NULL DEFAULT 0,
            position FLOAT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY day (day),
            KEY query (query(191))
        ) {$charset};");
    }

    /** Creates tables added after initial activation (upgrade-safe, dbDelta). */
    public static function ensure_tables(): void {
        global $wpdb;
        foreach (self::table_names($wpdb->prefix) as $table) {
            // phpcs:ignore -- table name from internal allowlist.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                self::create_tables($wpdb);
                break;
            }
        }
    }

    public static function default_settings(): array {
        return [
            'title_template'       => '%title% | %site_name%',
            'description_template' => '%excerpt%',
            'noindex_attachments'  => true,
            'noindex_search'       => true,
            'noindex_author'       => false,
            'noindex_tag'          => false,
            'sitemap_enabled'      => true,
            'sitemap_maps'         => ['post', 'page', 'category', 'post_tag'],
            // Social cards fall back to this site-wide image before the
            // featured image, then the first image inside the content.
            'social_default_image' => '',
            'social_use_content_image' => true,
            'ai_provider'          => 'none',
            'ai_endpoint'          => 'http://127.0.0.1:11434',
            'ai_model'             => 'llama3.1',
            // Products without price ("Giá: Liên hệ"): skip | no_offers.
            'product_no_price'     => 'skip',
            // Private sitemap name ('' = sitemap_index.xml).
            'sitemap_slug'         => '',
            // White-label report branding.
            'report_brand_name'    => '',
            'report_brand_logo'    => '',
            'report_brand_color'   => '#4f46e5',
            'report_brand_contact' => '',
            'report_locale'        => '',
            'audit_keep_days'      => 1,
            'gemini_api_key'       => '',
            'gemini_model'         => 'gemini-2.5-flash',
            // Conservative free-tier pacing; raise both on a paid key.
            'ai_rpm'               => 5,
            'ai_rpd'               => 200,
            'schema_map'           => ['post' => 'Article', 'page' => 'WebPage', 'product' => 'Product'],
            'schema_rules'         => [],
            'business_name'        => '',
            'business_industry'    => '',
            'business_description' => '',
            'business_audience'    => '',
            'business_tone'        => '',
            'business_phone'       => '',
            'business_address'     => '',
            'business_hours'       => '',
            'gsc_property'         => '',
            'gsc_api_key'          => '',
            'gsc_token'            => '',
            'indexing_google'      => false,
            'indexing_bing'        => false,
            'bing_api_key'         => '',
        ];
    }

    /**
     * One-way migration: encrypts any secret still stored as plaintext by an
     * earlier version. Idempotent — already-prefixed values are skipped.
     */
    public static function migrate_secrets(): void {
        $saved = get_option(TQK_WPSEO_OPTION, []);
        if (!is_array($saved)) {
            return;
        }
        $changed = false;
        foreach (Secrets::keys() as $k) {
            $val = $saved[$k] ?? '';
            if (is_string($val) && $val !== '' && !Secrets::is_encrypted($val)) {
                $saved[$k] = Secrets::encrypt($val);
                $changed = true;
            }
        }
        if ($changed) {
            update_option(TQK_WPSEO_OPTION, $saved);
        }
    }

    private static function seed_options(): void {
        if (get_option(TQK_WPSEO_OPTION) === false) {
            add_option(TQK_WPSEO_OPTION, self::default_settings());
        }
        register_setting('tqk_wpseo', TQK_WPSEO_OPTION);
    }

    /** @return string[] Registered meta keys. */
    public static function meta_keys(): array {
        return [
            '_tqk_wpseo_title',
            '_tqk_wpseo_description',
            '_tqk_wpseo_canonical',
            '_tqk_wpseo_robots',
            '_tqk_wpseo_focus_keyword',
            '_tqk_wpseo_secondary_keywords',
            '_tqk_wpseo_og_title',
            '_tqk_wpseo_og_description',
            '_tqk_wpseo_og_image',
            '_tqk_wpseo_twitter_title',
            '_tqk_wpseo_twitter_description',
            '_tqk_wpseo_schema_type',
            '_tqk_wpseo_sitemap_exclude',
        ];
    }

    private static function register_meta(): void {
        foreach (self::meta_keys() as $key) {
            register_post_meta('', $key, [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
            ]);
        }
    }
}
