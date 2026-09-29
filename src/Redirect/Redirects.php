<?php
namespace TQK_WPSEO\Redirect;

defined('ABSPATH') || exit;

/** S5 — Redirect manager: CRUD + front-end matching (301/302/307/410). */
class Redirects {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tqk_wpseo_redirects';
    }

    public static function normalize(string $path): string {
        $path = '/' . ltrim(trim($path), '/');
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    /**
     * Internal target in the site's own URL shape. Stripping the trailing
     * slash (as for sources) would make WordPress add it back with a second
     * 301: a redirect chain. Query string is kept.
     */
    public static function target_path(string $target): string {
        $path = '/' . ltrim((string) (parse_url($target, PHP_URL_PATH) ?: '/'), '/');
        $query = (string) parse_url($target, PHP_URL_QUERY);
        if ($path !== '/' && !preg_match('/\.[a-z0-9]{2,5}$/i', $path) && function_exists('user_trailingslashit')) {
            $path = user_trailingslashit(rtrim($path, '/'));
        }
        return $path . ($query !== '' ? '?' . $query : '');
    }

    /** @return int Inserted ID (0 on invalid). */
    public static function add(string $source, string $target, int $type = 301): int {
        global $wpdb;
        $source = self::normalize((string) parse_url($source, PHP_URL_PATH) ?: $source);
        $target = trim($target);
        if (str_starts_with($target, '/')) {
            $target = self::target_path($target);
        }
        if ($source === '' || $target === '' || !in_array($type, [301, 302, 307, 410], true)) {
            return 0;
        }
        if ($type !== 410 && self::normalize((string) parse_url($target, PHP_URL_PATH)) === $source && !str_contains($target, '://')) {
            return 0; // Never create loops.
        }
        Monitor404::resolve($source);
        $existing = self::get($source);
        if ($existing) {
            $wpdb->update(self::table(), ['target' => $target, 'type' => $type, 'active' => 1], ['id' => $existing->id]);
            return (int) $existing->id;
        }
        $wpdb->insert(self::table(), ['source' => $source, 'target' => $target, 'type' => $type, 'hits' => 0, 'active' => 1]);
        $row = self::get($source);
        return $row ? (int) $row->id : 0;
    }

    public static function get(string $source) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE source = %s AND active = 1',
            self::normalize($source)
        ));
    }

    /** @return object[] */
    public static function all(int $limit = 200): array {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY hits DESC LIMIT ' . $limit);
    }

    public static function delete(int $id): void {
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => $id]);
    }

    public static function hooks(): void {
        add_action('template_redirect', [self::class, 'handle'], 1);
    }

    public static function request_path(): string {
        return self::normalize((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    }

    public static function handle(): void {
        if (is_admin() || !\TQK_WPSEO\Core\Modules::is_active('redirects')) {
            return;
        }
        $row = self::get(self::request_path());
        if (!$row) {
            return;
        }
        global $wpdb;
        $wpdb->update(self::table(), ['hits' => ((int) $row->hits) + 1], ['id' => $row->id]);
        if ((int) $row->type === 410) {
            status_header(410);
            exit;
        }
        wp_redirect($row->target, (int) $row->type);
        exit;
    }
}
