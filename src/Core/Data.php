<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * S1 — Builds the SEO data array for a context: cache → postmeta → templates.
 * Template tags: %title% %site_name% %site_description% %category% %author%
 * %excerpt% %date% %modified%.
 */
class Data {

    public const CACHE_GROUP = 'tqk_wpseo';

    public static function for(array $ctx): array {
        $key = Context::key($ctx);
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return self::paged($ctx, $cached);
        }
        $data = self::build($ctx);
        wp_cache_set($key, $data, self::CACHE_GROUP);
        return self::paged($ctx, $data);
    }

    /**
     * Page 2+ of an archive is its own URL: canonical must point to itself,
     * not to page 1. Applied after the cache so one cached entry serves all pages.
     */
    private static function paged(array $ctx, array $data): array {
        $paged = function_exists('get_query_var') ? (int) get_query_var('paged') : 0;
        if (($ctx['type'] ?? '') === 'archive' && $paged > 1 && ($data['canonical'] ?? '') !== '' && function_exists('get_pagenum_link')) {
            $data['canonical'] = (string) get_pagenum_link($paged);
        }
        return $data;
    }

    public static function invalidate_for_post(int $post_id): void {
        wp_cache_delete('post:' . $post_id, self::CACHE_GROUP);
    }

    public static function invalidate_for_term(int $term_id): void {
        wp_cache_delete('term:' . $term_id, self::CACHE_GROUP);
    }

    private static function settings(): array {
        $defaults = \TQK_WPSEO\Core\Activator::default_settings();
        $saved = get_option(TQK_WPSEO_OPTION, []);
        return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
    }

    private static function build(array $ctx): array {
        $type = $ctx['type'] ?? 'general';
        if ($type === 'singular') {
            return self::singular((int) ($ctx['object_id'] ?? 0));
        }
        if ($type === 'home') {
            return self::home();
        }
        if ($type === 'archive') {
            return self::archive($ctx);
        }
        return self::bare('index', true);
    }

    private static function bare(string $robots_mode, bool $follow): array {
        $robots = ['index' => $robots_mode === 'index', 'follow' => $follow, 'archive' => true];
        $canon = home_url('/');
        return [
            'title' => '', 'description' => '', 'canonical' => $canon, 'robots' => $robots,
            'og_title' => '', 'og_description' => '', 'og_image' => '',
            'twitter_title' => '', 'twitter_description' => '', 'twitter_image' => '',
            'focus_keyword' => '', 'schema_type' => '',
        ];
    }

    private static function robots_from_meta(string $raw, array $fallback): array {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return $fallback;
        }
        $parts = array_map('trim', explode(',', $raw));
        return [
            'index'   => !in_array('noindex', $parts, true),
            'follow'  => !in_array('nofollow', $parts, true),
            'archive' => !in_array('noarchive', $parts, true),
        ];
    }

    private static function singular(int $id): array {
        $post = get_post($id);
        $s = self::settings();
        $meta = fn($k) => (string) get_post_meta($id, $k, true);

        $is_attachment = ($post->post_type ?? '') === 'attachment';
        $fallback_robots = ['index' => !($is_attachment && ($s['noindex_attachments'] ?? true)), 'follow' => true, 'archive' => true];

        $map = [
            'title'   => $post->post_title ?? '',
            'excerpt' => $post->post_excerpt ?? '',
            'author'  => get_the_author_meta('display_name', (int) ($post->post_author ?? 0)),
            'date'    => substr((string) ($post->post_date ?? ''), 0, 10),
            'modified' => substr((string) ($post->post_modified ?? ''), 0, 10),
            'category' => '',
        ];

        // Per-post values may carry template tags too (imported from Yoast /
        // Rank Math, e.g. "%title% | %site_name%"): expand them the same way.
        $title = self::tags($meta('_tqk_wpseo_title') !== '' ? $meta('_tqk_wpseo_title') : (string) $s['title_template'], $map);
        $desc = self::tags($meta('_tqk_wpseo_description') !== '' ? $meta('_tqk_wpseo_description') : (string) $s['description_template'], $map);
        $canon = $meta('_tqk_wpseo_canonical') !== '' ? $meta('_tqk_wpseo_canonical') : (string) get_permalink($id);

        $thumb = (int) get_post_thumbnail_id($id);
        $img = '';
        if ($thumb > 0) {
            $src = wp_get_attachment_image_src($thumb, 'full');
            $img = is_array($src) ? (string) ($src[0] ?? '') : '';
        }
        // Nothing set: featured → site-wide default → first image in the body.
        if ($img === '') {
            $img = self::social_image($id, $s);
        }

        $d = self::bare('index', true);
        $d['title'] = $title;
        $d['description'] = $desc;
        $d['canonical'] = $canon;
        $d['robots'] = self::robots_from_meta($meta('_tqk_wpseo_robots'), $fallback_robots);
        $d['og_title'] = $meta('_tqk_wpseo_og_title') !== '' ? $meta('_tqk_wpseo_og_title') : $title;
        $d['og_description'] = $meta('_tqk_wpseo_og_description') !== '' ? $meta('_tqk_wpseo_og_description') : $desc;
        $d['og_image'] = $meta('_tqk_wpseo_og_image') !== '' ? $meta('_tqk_wpseo_og_image') : $img;
        $d['twitter_title'] = $meta('_tqk_wpseo_twitter_title') !== '' ? $meta('_tqk_wpseo_twitter_title') : $d['og_title'];
        $d['twitter_description'] = $meta('_tqk_wpseo_twitter_description') !== '' ? $meta('_tqk_wpseo_twitter_description') : $d['og_description'];
        $d['twitter_image'] = $d['og_image'];
        $d['focus_keyword'] = $meta('_tqk_wpseo_focus_keyword');
        return $d;
    }

    /**
     * Social image when the post has none: the site-wide default first, then
     * the first real image inside the content. A post with no image at all
     * gets nothing — never a guessed or generated URL.
     */
    private static function social_image(int $id, array $s): string {
        $default = (string) ($s['social_default_image'] ?? '');
        if ($default !== '') {
            return $default;
        }
        if (empty($s['social_use_content_image'])) {
            return '';
        }
        $post = get_post($id);
        $html = (string) ($post->post_content ?? '');
        if ($html === '' || !preg_match('/<img[^>]+>/i', $html, $m)) {
            return '';
        }
        if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $m[0], $src)) {
            $url = trim($src[1]);
            // data: and placeholder images are not shareable.
            if ($url !== '' && !str_starts_with($url, 'data:') && !str_contains(strtolower($url), 'placeholder')) {
                return $url;
            }
        }
        return '';
    }

    private static function home(): array {
        $d = self::bare('index', true);
        $d['title'] = (string) get_bloginfo('name');
        $d['description'] = (string) get_bloginfo('description');
        $d['og_title'] = $d['title'];
        $d['og_description'] = $d['description'];
        $d['twitter_title'] = $d['title'];
        $d['twitter_description'] = $d['description'];
        return $d;
    }

    private static function archive(array $ctx): array {
        $s = self::settings();
        $d = self::bare('index', true);
        // An archive is its own page: never canonical to the homepage (that
        // tells Google every category is a copy of the home page).
        $d['canonical'] = '';
        $label = '';
        $term_id = 0;
        if (($ctx['subtype'] ?? '') === 'term') {
            $t = get_queried_object();
            $label = (string) ($t->name ?? '');
            $term_id = (int) ($t->term_id ?? 0);
            $link = $t ? get_term_link($t) : '';
            $d['canonical'] = is_string($link) ? $link : '';
            if (($ctx['taxonomy'] ?? '') === 'post_tag' && ($s['noindex_tag'] ?? false)) {
                $d['robots']['index'] = false;
            }
        } elseif (($ctx['subtype'] ?? '') === 'author') {
            $label = (string) get_the_author_meta('display_name', (int) ($ctx['author_id'] ?? 0));
            $d['canonical'] = function_exists('get_author_posts_url') ? (string) get_author_posts_url((int) ($ctx['author_id'] ?? 0)) : '';
            if ($s['noindex_author'] ?? false) {
                $d['robots']['index'] = false;
            }
        } elseif (($ctx['subtype'] ?? '') === 'post_type') {
            $o = get_post_type_object((string) ($ctx['post_type'] ?? 'post'));
            $label = (string) ($o->labels->singular_name ?? '');
            $link = function_exists('get_post_type_archive_link') ? get_post_type_archive_link((string) ($ctx['post_type'] ?? 'post')) : '';
            $d['canonical'] = is_string($link) ? $link : '';
        }
        $map = ['title' => $label, 'excerpt' => '', 'author' => $label, 'date' => '', 'modified' => '', 'category' => $label];
        // Per-term values (edited on the term screen or imported from Yoast /
        // Rank Math) win over the site templates.
        $tm = static fn(string $k) => $term_id > 0 && function_exists('get_term_meta') ? trim((string) get_term_meta($term_id, $k, true)) : '';
        $d['title'] = self::tags($tm('_tqk_wpseo_title') !== '' ? $tm('_tqk_wpseo_title') : (string) $s['title_template'], $map);
        $d['description'] = self::tags($tm('_tqk_wpseo_description') !== '' ? $tm('_tqk_wpseo_description') : (string) $s['description_template'], $map);
        if ($tm('_tqk_wpseo_robots') !== '') {
            $d['robots'] = self::robots_from_meta($tm('_tqk_wpseo_robots'), $d['robots']);
        }
        if ($tm('_tqk_wpseo_canonical') !== '') {
            $d['canonical'] = $tm('_tqk_wpseo_canonical');
        }
        if ($term_id > 0) {
            $d['focus_keyword'] = $tm('_tqk_wpseo_focus_keyword');
        }
        $d['og_title'] = $d['title'];
        $d['og_description'] = $d['description'];
        $d['twitter_title'] = $d['title'];
        $d['twitter_description'] = $d['description'];
        return $d;
    }

    public static function tags(string $text, array $map): string {
        $base = [
            '%site_name%' => (string) get_bloginfo('name'),
            '%site_description%' => (string) get_bloginfo('description'),
        ];
        foreach ($map as $k => $v) {
            $base['%' . $k . '%'] = (string) $v;
        }
        return trim(strtr($text, $base));
    }
}
