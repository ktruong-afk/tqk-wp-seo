<?php
namespace TQK_WPSEO\Links;

defined('ABSPATH') || exit;

use TQK_WPSEO\Analyzer\Text;

/**
 * Internal link engine: link index, orphans, broken-link checker.
 * Row: source_id, source_type, target_id (0 = external/unresolved), url, anchor.
 *
 * - The index is rebuilt on save, on menu save, and in full on demand (in
 *   batches), so a site installed long after its content was written still
 *   gets real numbers instead of "everything is an orphan".
 * - Menu items pointing at a page/post count as incoming links: on builder
 *   sites the menu is often the ONLY link to a page.
 * - Shortcodes are expanded before links are read (Flatsome / UX Builder
 *   keep buttons as [button link="..."]).
 * - Nothing is called broken until an HTTP check says so.
 */
class Engine {

    public const BATCH = 50;
    public const CHECK_BATCH = 20;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tqk_wpseo_links';
    }

    public static function hooks(): void {
        add_action('save_post', [self::class, 'rebuild_for_post'], 20, 1);
        add_action('wp_update_nav_menu', [self::class, 'rebuild_menus']);
    }

    /** @return string[] Public post types that can be linked to (no attachments). */
    public static function post_types(): array {
        $pts = function_exists('get_post_types') ? array_values((array) get_post_types(['public' => true])) : ['post', 'page'];
        return array_values(array_unique(array_diff(array_merge(['post', 'page'], $pts), ['attachment'])));
    }

    /**
     * Types that are meant to rank: the ones chosen for the sitemap. Builder
     * template types (mega menus, reusable blocks) are public but never
     * landing pages, so they must not show up as "orphans".
     */
    public static function orphan_types(): array {
        $maps = (array) \TQK_WPSEO\Admin\Settings::get('sitemap_maps', ['post', 'page']);
        $types = array_values(array_intersect(self::post_types(), $maps));
        return $types !== [] ? $types : ['post', 'page'];
    }

    /**
     * Menu links, including items that point at a page / post / term by ID
     * (they have no stored URL, the target is _menu_item_object_id).
     */
    public static function rebuild_menus(): int {
        global $wpdb;
        $table = self::table();
        $wpdb->delete($table, ['source_type' => 'menu']);
        $n = 0;
        foreach (get_posts(['post_type' => ['nav_menu_item'], 'post_status' => ['publish'], 'numberposts' => 1000]) as $item) {
            $id = (int) $item->ID;
            $type = (string) get_post_meta($id, '_menu_item_type', true);
            $target = 0;
            $url = '';
            if ($type === 'post_type') {
                $target = (int) get_post_meta($id, '_menu_item_object_id', true);
                $url = $target > 0 ? (string) get_permalink($target) : '';
            } else {
                $url = (string) get_post_meta($id, '_menu_item_url', true);
                if ($url === '' || str_starts_with($url, '#')) {
                    continue;
                }
                $target = (int) url_to_postid($url);
            }
            if ($url === '' && $target === 0) {
                continue;
            }
            $wpdb->insert($table, [
                'source_id' => $id,
                'source_type' => 'menu',
                'target_id' => $target,
                'url' => substr($url, 0, 500),
                'anchor' => substr((string) ($item->post_title ?? ''), 0, 200),
                'is_internal' => 1,
                'is_broken' => 0,
            ]);
            $n++;
        }
        return $n;
    }

    /** Post HTML with shortcodes expanded, so builder buttons count too. */
    public static function content_for_links($post): string {
        $html = (string) ($post->post_content ?? '');
        if (str_contains($html, '[') && function_exists('do_shortcode')) {
            // WooCommerce shortcodes render account / cart screens for the
            // current (admin) session with context-less URLs: not content.
            $html = (string) preg_replace('/\[\/?(?:woocommerce_|wc_)[^\]]*\]/i', '', $html);
            $html = (string) do_shortcode($html);
        }
        return (string) apply_filters('tqk_wpseo_links_content', $html, $post);
    }

    public static function rebuild_for_post(int $post_id): int {
        global $wpdb;
        if (!\TQK_WPSEO\Core\Modules::is_active('links')) {
            return 0;
        }
        $post = get_post($post_id);
        $table = self::table();
        $wpdb->delete($table, ['source_id' => $post_id]);
        if (!$post || ($post->post_status ?? '') !== 'publish' || !in_array((string) ($post->post_type ?? ''), self::post_types(), true)) {
            return 0;
        }
        if (in_array($post_id, self::system_ids(), true)) {
            return 0; // cart / checkout / account: app screens, not content
        }
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $html = self::content_for_links($post);
        $n = 0;
        foreach (array_unique(Text::links($html)) as $href) {
            $href = html_entity_decode(trim($href), ENT_QUOTES, 'UTF-8');
            if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto|tel|javascript|sms|zalo):#i', $href)) {
                continue;
            }
            $host = strtolower((string) wp_parse_url($href, PHP_URL_HOST));
            $internal = $host === '' || $host === $home_host;
            $target = 0;
            if ($internal) {
                $target = (int) url_to_postid($host === '' ? home_url($href) : $href);
            }
            $wpdb->insert($table, [
                'source_id' => $post_id,
                'source_type' => (string) ($post->post_type ?? 'post'),
                'target_id' => $target,
                'url' => substr($href, 0, 500),
                'anchor' => substr(self::anchor_for($html, $href), 0, 200),
                'is_internal' => $internal ? 1 : 0,
                'is_broken' => 0, // only an HTTP check may say "broken"
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Full index rebuild, one batch per call. Offset 0 also re-syncs menus.
     *
     * @return array{done:bool, next:int, total:int, links:int}
     */
    public static function rebuild_batch(int $offset): array {
        if ($offset === 0) {
            self::rebuild_menus();
        }
        $ids = array_map('intval', (array) get_posts([
            'post_type' => self::post_types(), 'post_status' => ['publish'], 'fields' => 'ids',
            'numberposts' => self::BATCH, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC',
        ]));
        $links = 0;
        foreach ($ids as $id) {
            $links += self::rebuild_for_post($id);
        }
        $total = 0;
        foreach (self::post_types() as $pt) {
            $total += function_exists('wp_count_posts') ? (int) (wp_count_posts($pt)->publish ?? 0) : 0;
        }
        $next = $offset + count($ids);
        $done = count($ids) < self::BATCH;
        if ($done) {
            update_option('tqk_wpseo_links_indexed_at', gmdate('Y-m-d H:i'), false);
        }
        return ['done' => $done, 'next' => $next, 'total' => $total, 'links' => $links];
    }

    private static function anchor_for(string $html, string $href): string {
        $q = preg_quote($href, '/');
        if (preg_match('/<a\b[^>]*href\s*=\s*["\']?' . $q . '["\']?[^>]*>(.*?)<\/a>/is', $html, $m)) {
            return Text::plain($m[1]);
        }
        return '';
    }

    /** URLs never worth an HTTP check (admin, login, feeds, REST). */
    public static function skip_check(string $url): bool {
        return (bool) preg_match('#/(wp-admin|wp-login\.php|wp-json|feed)(/|$|\?)#i', $url);
    }

    /**
     * HTTP-checks external links and internal links that did not resolve to
     * a post (archives, files, typos), one batch per call from $offset.
     * Broken = no answer or status >= 400 (HEAD, then GET for servers that
     * refuse HEAD).
     *
     * @return array{0:int,1:int,2:int} [checked, broken, next offset]
     */
    public static function check_links(int $offset = 0, int $limit = self::CHECK_BATCH): array {
        global $wpdb;
        // target_id = 0 covers both: external links never resolve to a post.
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE target_id = 0 ORDER BY id ASC LIMIT %d, %d", max(0, $offset), max(1, $limit)));
        $checked = 0;
        $broken = 0;
        foreach ((array) $rows as $row) {
            $url = (string) $row->url;
            $abs = str_starts_with($url, '/') ? home_url($url) : $url;
            $is_broken = 0;
            if (!self::skip_check($abs) && preg_match('#^https?://#i', $abs)) {
                $res = wp_remote_head($abs, ['timeout' => 10, 'redirection' => 3]);
                $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
                if ($code === 405 || $code === 403 || $code === 0) {
                    $res = wp_remote_get($abs, ['timeout' => 10, 'redirection' => 3, 'limit_response_size' => 1024]);
                    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
                }
                $is_broken = $code === 0 || $code >= 400 ? 1 : 0;
            }
            $wpdb->update(self::table(), ['is_broken' => $is_broken], ['id' => $row->id]);
            $checked++;
            $broken += $is_broken;
        }
        update_option('tqk_wpseo_links_checked_at', gmdate('Y-m-d H:i'));
        return [$checked, $broken, $offset + $checked];
    }

    /** Backward-compatible name used by older callers. @return array [checked, broken] */
    public static function check_external(int $limit = 20): array {
        [$c, $b] = self::check_links(0, $limit);
        return [$c, $b];
    }

    /** WooCommerce cart / checkout / account pages. @return int[] */
    public static function system_ids(): array {
        $ids = [];
        foreach (['woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id'] as $opt) {
            $ids[] = (int) get_option($opt, 0);
        }
        return array_values(array_filter($ids));
    }

    /** Pages that are not meant to rank on their own. @return int[] */
    public static function excluded_ids(): array {
        return array_values(array_filter(array_merge(
            [(int) get_option('page_on_front', 0), (int) get_option('page_for_posts', 0)],
            self::system_ids()
        )));
    }

    /**
     * Published, indexable content with zero incoming links from content or
     * menus. Front page, blog page, WooCommerce system pages and noindex
     * pages are left out: they are not supposed to rank on their own.
     *
     * @return int[]
     */
    public static function orphan_ids(): array {
        global $wpdb;
        $ids = array_map('intval', (array) get_posts(['post_type' => self::orphan_types(), 'post_status' => ['publish'], 'numberposts' => 5000, 'fields' => 'ids']));
        $linked = [];
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $distinct = $wpdb->get_results("SELECT DISTINCT target_id FROM {$table}");
        foreach ((array) $distinct as $r) {
            if ((int) ($r->target_id ?? 0) > 0) {
                $linked[] = (int) $r->target_id;
            }
        }
        $out = [];
        foreach (array_diff($ids, $linked, self::excluded_ids()) as $id) {
            if (str_contains((string) get_post_meta($id, '_tqk_wpseo_robots', true), 'noindex')) {
                continue;
            }
            $out[] = (int) $id;
        }
        return $out;
    }

    public static function indexed(): bool {
        return (string) get_option('tqk_wpseo_links_indexed_at', '') !== '';
    }

    /** @return object[] Broken links, newest first. */
    public static function broken(int $limit = 50): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE is_broken = 1 ORDER BY id DESC LIMIT %d", max(1, $limit)));
    }

    /** @return array [incoming, outgoing]. */
    public static function counts(int $post_id): array {
        global $wpdb;
        $table = self::table();
        $in = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE target_id = %d", $post_id));
        $out = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE source_id = %d", $post_id));
        return [$in, $out];
    }
}
