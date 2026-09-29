<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * Private storage for audit files (snapshots, per-page JSON).
 *
 * Screenshots of a site's pages (drafts, account pages, carts) are not for
 * the public. So the files live in a folder with a random name inside
 * uploads, direct web access is refused (.htaccess for Apache/LiteSpeed,
 * web.config for IIS, index.php everywhere), and they are shown only
 * through an admin-ajax gate that needs a logged-in user with the right
 * capability and a valid nonce. On nginx, where .htaccess is ignored, the
 * random folder name is what keeps them out of reach.
 *
 * Files are pruned after the chosen number of days (default 1): once a day
 * on the first plugin screen opened, and by a daily cron as a fallback.
 * The text reports (score, plan) stay in the database.
 *
 * Paths handed around are relative keys: "{folder}/{file}.jpg|json".
 */
class Vault {

    public const KEY_OPTION = 'tqk_wpseo_vault_key';
    public const PRUNED_OPTION = 'tqk_wpseo_vault_pruned';
    public const CRON = 'tqk_wpseo_vault_prune';
    public const ACTION = 'tqk_wpseo_file';

    public static function hooks(): void {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'serve']);
        add_action(self::CRON, [self::class, 'prune']);
        add_action('admin_init', [self::class, 'maybe_prune']);
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::CRON) && function_exists('wp_schedule_event')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
        }
    }

    /** Days files are kept (setting), 1 by default. */
    public static function keep_days(): int {
        $d = (int) \TQK_WPSEO\Admin\Settings::get('audit_keep_days', 1);
        return in_array($d, [1, 7, 30], true) ? $d : 1;
    }

    /** Base folder (created and locked on first use); '' when uploads are unavailable. */
    public static function base(): string {
        if (!function_exists('wp_upload_dir')) {
            return '';
        }
        $up = wp_upload_dir();
        if (!empty($up['error'])) {
            return '';
        }
        $key = (string) get_option(self::KEY_OPTION, '');
        if (!preg_match('/^[a-f0-9]{24}$/', $key)) {
            $key = bin2hex(random_bytes(12));
            update_option(self::KEY_OPTION, $key, false);
        }
        $dir = rtrim((string) $up['basedir'], '/\\') . '/tqk-wpseo-private-' . $key;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        self::lock($dir);
        return $dir;
    }

    /** Refuses direct web access to a folder. */
    private static function lock(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = [
            '.htaccess' => "# TQK WP SEO private files\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.php' => "<?php\n// Silence.\n",
        ];
        foreach ($files as $name => $body) {
            if (!file_exists($dir . '/' . $name)) {
                @file_put_contents($dir . '/' . $name, $body);
            }
        }
    }

    /** A relative key is "folder/file.ext", nothing else. */
    public static function valid(string $rel): bool {
        return (bool) preg_match('#^[a-z0-9]{8,32}/[a-z0-9-]{1,80}\.(jpg|json)$#', $rel);
    }

    /** Absolute path of a key (existing or not); '' when invalid. */
    public static function path(string $rel): string {
        $base = self::base();
        return $base !== '' && self::valid($rel) ? $base . '/' . $rel : '';
    }

    /** Writes a file. @return string Its key, '' on failure. */
    public static function write(string $folder, string $name, string $bytes): string {
        $rel = $folder . '/' . $name;
        $path = self::path($rel);
        if ($path === '') {
            return '';
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return '';
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence.\n");
        }
        return @file_put_contents($path, $bytes) === false ? '' : $rel;
    }

    public static function delete_folder(string $folder): void {
        $base = self::base();
        if ($base === '' || !preg_match('/^[a-z0-9]{8,32}$/', $folder) || !is_dir($base . '/' . $folder)) {
            return;
        }
        foreach (array_merge((array) glob($base . '/' . $folder . '/*.jpg'), (array) glob($base . '/' . $folder . '/*.json')) as $f) {
            @unlink((string) $f);
        }
        @unlink($base . '/' . $folder . '/index.php');
        @rmdir($base . '/' . $folder);
    }

    /**
     * Link to view a stored file (admins only, nonce-bound). Anything that is
     * not a key (an older absolute URL) is returned unchanged.
     */
    public static function url(string $rel_or_url): string {
        if (!self::valid($rel_or_url)) {
            return $rel_or_url;
        }
        $v = @filemtime(self::path($rel_or_url));
        return add_query_arg([
            'action' => self::ACTION,
            'f' => $rel_or_url,
            'v' => (int) $v,
            '_wpnonce' => wp_create_nonce(self::ACTION),
        ], admin_url('admin-ajax.php'));
    }

    /** Local path of a key or of an older uploads URL; '' when none. */
    public static function local(string $rel_or_url): string {
        if (self::valid($rel_or_url)) {
            $p = self::path($rel_or_url);
            return $p !== '' && is_file($p) ? $p : '';
        }
        return \TQK_WPSEO\Analyzer\ImageFix::url_to_path((string) strtok($rel_or_url, '?'));
    }

    /** admin-ajax gate: logged in, allowed, valid nonce, valid key. */
    public static function serve(): void {
        $rel = (string) wp_unslash($_GET['f'] ?? '');
        if (!check_ajax_referer(self::ACTION, '_wpnonce', false) || !current_user_can('edit_posts') || !self::valid($rel)) {
            status_header(403);
            exit;
        }
        $path = self::path($rel);
        if ($path === '' || !is_file($path)) {
            status_header(404);
            exit;
        }
        nocache_headers();
        header('Content-Type: ' . (str_ends_with($rel, '.jpg') ? 'image/jpeg' : 'application/json; charset=utf-8'));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        // No copy kept by the browser: on a shared computer a logged-out
        // person must not see a cached screenshot.
        header('Cache-Control: no-store, private');
        readfile($path);
        exit;
    }

    /** Deletes files older than the kept days. @return int Files removed. */
    public static function prune(?int $days = null, ?int $now = null): int {
        $base = self::base();
        if ($base === '') {
            return 0;
        }
        $limit = ($now ?? time()) - DAY_IN_SECONDS * ($days ?? self::keep_days());
        $n = 0;
        foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            foreach (array_merge((array) glob($dir . '/*.jpg'), (array) glob($dir . '/*.json')) as $f) {
                if (@filemtime((string) $f) < $limit && @unlink((string) $f)) {
                    $n++;
                }
            }
            if (count((array) glob($dir . '/*')) <= 1) {
                @unlink($dir . '/index.php');
                @rmdir((string) $dir);
            }
        }
        $n += self::drop_legacy();
        update_option(self::PRUNED_OPTION, gmdate('Y-m-d', $now ?? time()), false);
        return $n;
    }

    /**
     * Older versions wrote audit files to the public uploads/tqk-wpseo-audit
     * folder: removed entirely (only our own jpg / json / index files).
     */
    private static function drop_legacy(): int {
        $up = wp_upload_dir();
        $old = rtrim((string) ($up['basedir'] ?? ''), '/\\') . '/tqk-wpseo-audit';
        if (($up['basedir'] ?? '') === '' || !is_dir($old)) {
            return 0;
        }
        $n = 0;
        foreach ((array) glob($old . '/*', GLOB_ONLYDIR) as $dir) {
            foreach (array_merge((array) glob($dir . '/*.jpg'), (array) glob($dir . '/*.json')) as $f) {
                $n += @unlink((string) $f) ? 1 : 0;
            }
            @unlink($dir . '/index.php');
            @rmdir((string) $dir);
        }
        @unlink($old . '/index.php');
        @rmdir($old);
        return $n;
    }

    /** Once a day, on the first plugin / editor screen opened. */
    public static function maybe_prune(): void {
        if ((string) get_option(self::PRUNED_OPTION, '') === gmdate('Y-m-d')) {
            return;
        }
        $page = (string) ($_GET['page'] ?? '');
        $screen = $GLOBALS['pagenow'] ?? '';
        if (str_starts_with($page, 'tqk-wpseo') || in_array($screen, ['post.php', 'post-new.php'], true)) {
            self::prune();
        }
    }
}
