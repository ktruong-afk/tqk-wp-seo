<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/** S1 — Frontend output on wp_head. Schema (S3) hooks in via filter. */
class Frontend {

    public static function hooks(): void {
        add_action('wp_head', [self::class, 'head'], 1);
        add_filter('pre_get_document_title', [self::class, 'document_title'], 999);
        // Core canonical would duplicate ours.
        remove_action('wp_head', 'rel_canonical');
        // Robots go through core's single tag, never a second one of ours.
        add_filter('wp_robots', [Robots::class, 'filter'], 999);
    }

    public static function document_title(string $title): string {
        $data = Data::for(Context::resolve());
        return ($data['title'] ?? '') !== '' ? (string) $data['title'] : $title;
    }

    public static function head(): void {
        $ctx = Context::resolve();
        $data = Data::for($ctx);
        $data = apply_filters('tqk_wpseo_data', $data, $ctx);

        // Page-source banner, like other SEO plugins print. On Pro it carries
        // the license fingerprint (from the Pro add-on), not the key.
        echo "
<!-- " . esc_html(Pro::banner()) . " -->
"; // phpcs:ignore
        echo Meta::render($data); // phpcs:ignore
        echo Canonical::render($data); // phpcs:ignore
        echo OpenGraph::render($data, ($ctx['type'] ?? '') === 'singular' ? 'article' : 'website'); // phpcs:ignore
        echo (string) apply_filters('tqk_wpseo_schema_html', '', $ctx, $data); // phpcs:ignore
        echo "<!-- / TQK WP SEO -->
"; // phpcs:ignore
    }
}
