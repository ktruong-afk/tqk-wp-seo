<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * S1 — Resolves the current request into an SEO context array.
 * Shapes: home | singular | archive | search | 404 | general.
 */
class Context {

    public static function resolve(): array {
        if (is_404()) {
            return ['type' => '404'];
        }
        if (is_search()) {
            return ['type' => 'search'];
        }
        if (is_front_page()) {
            return ['type' => 'home'];
        }
        if (is_singular()) {
            $id = (int) get_queried_object_id();
            return ['type' => 'singular', 'object_id' => $id, 'post_type' => (string) get_post_type($id)];
        }
        if (is_post_type_archive()) {
            $o = get_queried_object();
            return ['type' => 'archive', 'subtype' => 'post_type', 'post_type' => (string) ($o->name ?? '')];
        }
        if (is_category() || is_tag() || is_tax()) {
            $t = get_queried_object();
            return [
                'type' => 'archive', 'subtype' => 'term',
                'taxonomy' => (string) ($t->taxonomy ?? ''), 'term_id' => (int) ($t->term_id ?? 0),
            ];
        }
        if (is_author()) {
            $o = get_queried_object();
            return ['type' => 'archive', 'subtype' => 'author', 'author_id' => (int) ($o->ID ?? 0)];
        }
        if (is_date() || is_archive()) {
            return ['type' => 'archive', 'subtype' => is_date() ? 'date' : 'general'];
        }
        if (is_home()) {
            return ['type' => 'home', 'subtype' => 'posts'];
        }
        return ['type' => 'general'];
    }

    public static function key(array $ctx): string {
        if (($ctx['type'] ?? '') === 'singular') {
            return 'post:' . (int) ($ctx['object_id'] ?? 0);
        }
        if (($ctx['type'] ?? '') === 'home') {
            return 'home';
        }
        if (($ctx['type'] ?? '') === 'archive' && ($ctx['subtype'] ?? '') === 'term') {
            return 'term:' . (int) ($ctx['term_id'] ?? 0);
        }
        if (($ctx['type'] ?? '') === 'archive' && ($ctx['subtype'] ?? '') === 'author') {
            return 'author:' . (int) ($ctx['author_id'] ?? 0);
        }
        return 'ctx:' . md5(serialize($ctx));
    }
}
