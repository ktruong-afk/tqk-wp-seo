<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/** S1 — Open Graph + Twitter/X cards. */
class OpenGraph {

    public static function render(array $d, string $og_type = 'website'): string {
        $out = '';
        $tags = [
            ['property', 'og:title', $d['og_title'] ?? ''],
            ['property', 'og:description', $d['og_description'] ?? ''],
            ['property', 'og:type', $og_type],
            ['property', 'og:url', $d['canonical'] ?? ''],
            ['property', 'og:image', $d['og_image'] ?? ''],
            ['property', 'og:site_name', get_bloginfo('name')],
            ['name', 'twitter:card', (($d['og_image'] ?? '') !== '') ? 'summary_large_image' : 'summary'],
            ['name', 'twitter:title', $d['twitter_title'] ?? ''],
            ['name', 'twitter:description', $d['twitter_description'] ?? ''],
            ['name', 'twitter:image', $d['twitter_image'] ?? ''],
        ];
        foreach ($tags as [$attr, $key, $val]) {
            $val = trim((string) $val);
            if ($val === '') {
                continue;
            }
            $out .= sprintf("<meta %s=\"%s\" content=\"%s\" />\n", $attr, esc_attr($key), esc_attr($val));
        }
        return $out;
    }
}
