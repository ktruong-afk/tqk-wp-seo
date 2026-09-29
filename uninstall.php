<?php
// Uninstall: removes ALL TQK WP SEO data — tables, options, transients,
// post/term meta, cron events, the role capability, and the files it wrote
// into uploads. Prefix-based, so options added later are covered too.
// Original media and other plugins' data are never touched.
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

foreach (['tqk_wpseo_redirects', 'tqk_wpseo_404', 'tqk_wpseo_links', 'tqk_wpseo_scan', 'tqk_wpseo_gsc'] as $t) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$t}");
}

// Options + transients (and their timeouts): everything prefixed tqk_wpseo_.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE 'tqk\\_wpseo\\_%'
        OR option_name LIKE '\\_transient\\_tqk\\_wpseo\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_tqk\\_wpseo\\_%'
        OR option_name LIKE '\\_site\\_transient\\_tqk\\_wpseo\\_%'
        OR option_name LIKE '\\_site\\_transient\\_timeout\\_tqk\\_wpseo\\_%'"
);

// Post meta (SEO fields, audit records, AI suggestions, image plans) + term meta.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_tqk\\_wpseo\\_%'");
$wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\\_tqk\\_wpseo\\_%'");

// Scheduled events.
foreach (['tqk_wpseo_process_queue', 'tqk_wpseo_gsc_daily', 'tqk_wpseo_license_check'] as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Role capability granted by the Role Manager.
if (function_exists('wp_roles')) {
    foreach (array_keys(wp_roles()->roles) as $role) {
        $r = get_role($role);
        if ($r && $r->has_cap('tqk_seo_access')) {
            $r->remove_cap('tqk_seo_access');
        }
    }
}

// Files this plugin wrote into uploads: audit snapshots/JSON and optimized
// images. Only these two folders; original uploads stay untouched.
$up = wp_upload_dir(null, false);
$base = isset($up['basedir']) ? (string) $up['basedir'] : '';
if ($base !== '') {
    $rm = static function (string $dir) use (&$rm): void {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $rm($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    foreach (['tqk-wpseo-audit', 'audit-pics'] as $folder) {
        $rm($base . '/' . $folder);
    }
    // Private audit files (random folder name, see Core\Vault).
    foreach ((array) glob($base . '/tqk-wpseo-private-*', GLOB_ONLYDIR) as $dir) {
        if (preg_match('#/tqk-wpseo-private-[a-f0-9]{24}$#', str_replace('\\', '/', (string) $dir))) {
            $rm((string) $dir);
        }
    }
}
