<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/** S1 — <title> + meta description. Title echoes only when the theme
 * lacks title-tag support; otherwise pre_get_document_title carries it. */
class Meta {

    public static function document_title(array $d): string {
        return (string) ($d['title'] ?? '');
    }

    public static function render(array $d): string {
        $out = '';
        if (($d['title'] ?? '') !== '' && !current_theme_supports('title-tag')) {
            $out .= sprintf("<title>%s</title>\n", esc_html($d['title']));
        }
        if (($d['description'] ?? '') !== '') {
            $out .= sprintf("<meta name=\"description\" content=\"%s\" />\n", esc_attr($d['description']));
        }
        return $out;
    }
}
