<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * S1 — Meta robots. Merged into core's wp_robots so the page carries exactly
 * one robots tag; the most restrictive of core and ours wins, and a site set
 * to "Discourage search engines" (blog_public = 0) is never index/follow.
 */
class Robots {

    public static function content(array $robots): string {
        $parts = [($robots['index'] ?? true) ? 'index' : 'noindex'];
        $parts[] = ($robots['follow'] ?? true) ? 'follow' : 'nofollow';
        if (!($robots['archive'] ?? true)) {
            $parts[] = 'noarchive';
        }
        return implode(',', $parts);
    }

    /** wp_robots filter callback. */
    public static function filter($robots): array {
        $ctx = Context::resolve();
        $data = apply_filters('tqk_wpseo_data', Data::for($ctx), $ctx);
        return self::merge((array) $robots, (array) ($data['robots'] ?? []));
    }

    public static function merge(array $core, array $ours): array {
        $public = (string) get_option('blog_public', '1') !== '0';
        $index = $public && empty($core['noindex']) && ($ours['index'] ?? true);
        $follow = $public && empty($core['nofollow']) && ($ours['follow'] ?? true);
        unset($core['index'], $core['noindex'], $core['follow'], $core['nofollow']);
        $core[$index ? 'index' : 'noindex'] = true;
        $core[$follow ? 'follow' : 'nofollow'] = true;
        if (!($ours['archive'] ?? true)) {
            $core['noarchive'] = true;
        }
        return $core;
    }
}
