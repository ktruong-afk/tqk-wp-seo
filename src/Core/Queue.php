<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/** S2 — Background queue (option list). S4 consumes it; save_post never blocks. */
class Queue {

    public const OPTION = 'tqk_wpseo_queue';

    public static function push(int $post_id): void {
        if ($post_id <= 0) {
            return;
        }
        $q = get_option(self::OPTION, []);
        if (!is_array($q)) {
            $q = [];
        }
        if (!in_array($post_id, $q, true)) {
            $q[] = $post_id;
        }
        update_option(self::OPTION, array_values(array_slice($q, -200)));
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('tqk_wpseo_process_queue')) {
            wp_schedule_single_event(time() + 60, 'tqk_wpseo_process_queue');
        }
    }

    /** @return int[] */
    public static function all(): array {
        $q = get_option(self::OPTION, []);
        return is_array($q) ? array_values(array_map('intval', $q)) : [];
    }

    public static function consume(): array {
        $q = self::all();
        update_option(self::OPTION, []);
        return $q;
    }
}
