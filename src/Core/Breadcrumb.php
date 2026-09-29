<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * S3 — Breadcrumb trail + HTML. Theme API: tqk_wpseo_breadcrumb().
 *
 * @return array List of [label, url].
 */
class Breadcrumb {

    public static function trail(array $ctx = []): array {
        if ($ctx === []) {
            $ctx = Context::resolve();
        }
        $trail = [[__('Home', 'tqk-wpseo'), home_url('/')]];
        $type = $ctx['type'] ?? 'general';

        if ($type === 'singular') {
            $post = get_post((int) ($ctx['object_id'] ?? 0));
            if (!$post) {
                return $trail;
            }
            $pt = $post->post_type ?? 'post';
            if ($pt !== 'post' && $pt !== 'page') {
                $o = get_post_type_object($pt);
                $trail[] = [(string) ($o->labels->singular_name ?? $pt), (string) get_post_type_archive_link($pt)];
            } elseif ($pt === 'post') {
                $terms = get_the_terms((int) $post->ID, 'category');
                if (is_array($terms) && $terms !== []) {
                    foreach (self::term_parents($terms[0]) as $t) {
                        $trail[] = [$t->name, (string) get_term_link($t)];
                    }
                }
            }
            $trail[] = [(string) ($post->post_title ?? ''), (string) get_permalink($post)];
        } elseif ($type === 'archive' && ($ctx['subtype'] ?? '') === 'term') {
            $t = get_term((int) ($ctx['term_id'] ?? 0));
            if ($t) {
                foreach (self::term_parents($t, false) as $p) {
                    $trail[] = [$p->name, (string) get_term_link($p)];
                }
                $trail[] = [(string) $t->name, (string) get_term_link($t)];
            }
        }
        return $trail;
    }

    /** @return object[] Ancestors oldest-first (optionally excluding self). */
    private static function term_parents($term, bool $include_self = true): array {
        $chain = $include_self ? [$term] : [];
        $seen = [(int) ($term->term_id ?? 0)];
        $parent = (int) ($term->parent ?? 0);
        while ($parent > 0 && !in_array($parent, $seen, true)) {
            $seen[] = $parent;
            $p = get_term($parent);
            if (!$p) {
                break;
            }
            array_unshift($chain, $p);
            $parent = (int) ($p->parent ?? 0);
        }
        return $chain;
    }

    public static function render(array $ctx = []): string {
        $trail = self::trail($ctx);
        if (count($trail) < 2) {
            return '';
        }
        $html = '<nav class="tqk-wpseo-breadcrumb" aria-label="breadcrumb"><ol>';
        $last = count($trail) - 1;
        foreach ($trail as $i => [$label, $url]) {
            $html .= $i === $last
                ? sprintf('<li aria-current="page">%s</li>', esc_html($label))
                : sprintf('<li><a href="%s">%s</a></li>', esc_url($url), esc_html($label));
        }
        return $html . '</ol></nav>';
    }
}

function tqk_wpseo_breadcrumb(array $ctx = []): void {
    echo Breadcrumb::render($ctx); // phpcs:ignore
}
