<?php
namespace TQK_WPSEO\Redirect;

defined('ABSPATH') || exit;

/** S5 — 404 monitor: logs misses, prunes to newest 1000, one-click redirect. */
class Monitor404 {

    public const LIMIT = 1000;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tqk_wpseo_404';
    }

    public static function hooks(): void {
        add_action('template_redirect', [self::class, 'maybe_log'], 20);
    }

    public static function maybe_log(): void {
        if (!is_404() || is_admin() || !\TQK_WPSEO\Core\Modules::is_active('monitor-404')) {
            return;
        }
        $path = Redirects::request_path();
        if (self::ignored($path)) {
            return;
        }
        self::log($path, (string) ($_SERVER['HTTP_REFERER'] ?? ''));
    }

    /**
     * Misses no visitor or search engine ever sees: browser DevTools asking
     * for source maps (*.map), wp-admin and REST paths. They only bury the
     * real broken links. Filterable: tqk_wpseo_404_ignore.
     */
    public static function ignored(string $path): bool {
        $skip = (bool) preg_match('#(\.map$|^/wp-admin(/|$)|^/wp-json(/|$))#i', $path);
        return (bool) apply_filters('tqk_wpseo_404_ignore', $skip, $path);
    }

    public static function log(string $url, string $referrer = ''): void {
        global $wpdb;
        $table = self::table();
        $url = substr(Redirects::normalize($url), 0, 255);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE url = %s", $url));
        if ($row) {
            $wpdb->update($table, ['hits' => ((int) $row->hits) + 1, 'last_hit' => gmdate('Y-m-d H:i:s'), 'referrer' => $referrer], ['id' => $row->id]);
        } else {
            $wpdb->insert($table, ['url' => $url, 'hits' => 1, 'last_hit' => gmdate('Y-m-d H:i:s'), 'referrer' => $referrer]);
        }
        self::prune();
    }

    private static function prune(): void {
        global $wpdb;
        $table = self::table();
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($total <= self::LIMIT) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $old = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} ORDER BY last_hit ASC LIMIT %d", $total - self::LIMIT));
        foreach ($old as $row) {
            $wpdb->delete($table, ['id' => $row->id]);
        }
    }

    /** @return object[] Hottest first. */
    public static function all(int $limit = 200): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY hits DESC LIMIT %d", $limit));
    }

    public static function delete(int $id): void {
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => $id]);
    }

    /** A redirect now handles this URL: the miss is resolved, drop it. */
    public static function resolve(string $url): void {
        global $wpdb;
        $wpdb->delete(self::table(), ['url' => Redirects::normalize((string) (wp_parse_url($url, PHP_URL_PATH) ?: $url))]);
    }
}
