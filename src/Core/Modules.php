<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * Modules: every feature set toggles independently.
 * Core (menu, assets, meta boxes fields, frontend base) is always on.
 * Disabling a module unhooks its features AND hides its menu page.
 */
class Modules {

    public const OPTION = 'tqk_wpseo_modules';

    /** @return array Slug => [title, description, default]. */
    public static function all(): array {
        return [
            'sitemap' => [__('Sitemap & robots.txt', 'tqk-wp-seo'), __('XML sitemap per post type, robots.txt editor, llms.txt.', 'tqk-wp-seo'), true],
            'schema' => [__('Schema & Breadcrumb', 'tqk-wp-seo'), __('JSON-LD @graph, templates per post type, BreadcrumbList.', 'tqk-wp-seo'), true],
            'analyzer' => [__('Content Analyzer', 'tqk-wp-seo'), __('Focus keyword, headings, images, links, readability + score 0-100.', 'tqk-wp-seo'), true],
            'scanner' => [__('Site Scanner', 'tqk-wp-seo'), __('Batch audits 50/job, dashboard snapshot, orphan reports. Needs Analyzer.', 'tqk-wp-seo'), true],
            'analytics' => [__('Analytics (Google)', 'tqk-wp-seo'), __('Search Console clicks/impressions/CTR/position.', 'tqk-wp-seo'), true],
            'redirects' => [__('Redirects', 'tqk-wp-seo'), __('301/302/307/410 manager, auto-slug, CSV import/export.', 'tqk-wp-seo'), true],
            'monitor-404' => [__('404 Monitor', 'tqk-wp-seo'), __('Miss logging + hits + referrer, one-click redirect.', 'tqk-wp-seo'), true],
            'links' => [__('Link Tools', 'tqk-wp-seo'), __('Internal graph, orphans, broken-link checker.', 'tqk-wp-seo'), true],
            'image-seo' => [__('Image SEO', 'tqk-wp-seo'), __('ALT/dimensions/weight audit, auto ALT fallback, lazy-load check.', 'tqk-wp-seo'), true],
            'role-manager' => [__('Role Manager', 'tqk-wp-seo'), __('Capability per role for SEO screens.', 'tqk-wp-seo'), true],
            'instant-indexing' => [__('Instant Indexing', 'tqk-wp-seo'), __('Google Indexing API + Bing URL submission on publish.', 'tqk-wp-seo'), false],
            'local-seo' => [__('Local SEO', 'tqk-wp-seo'), __('Business profile → LocalBusiness schema + NAP.', 'tqk-wp-seo'), true],
            'woo' => [__('WooCommerce SEO', 'tqk-wp-seo'), __('Product Offer/Review schema, product sitemap. Loads only with Woo.', 'tqk-wp-seo'), true],
            'ai' => [__('Local AI', 'tqk-wp-seo'), __('Ollama/LM Studio suggestions with preview-approve + business context.', 'tqk-wp-seo'), true],
            'mcp' => [__('MCP Tools', 'tqk-wp-seo'), __('REST tools for local AI agents (token auth).', 'tqk-wp-seo'), true],
        ];
    }

    /** @return array Slug => bool states (saved over defaults). */
    public static function states(): array {
        $saved = get_option(self::OPTION, []);
        $out = [];
        foreach (self::all() as $slug => [$title, $desc, $default]) {
            $out[$slug] = is_array($saved) && array_key_exists($slug, $saved) ? (bool) $saved[$slug] : (bool) $default;
        }
        return $out;
    }

    public static function is_active(string $slug): bool {
        $states = self::states();
        return $states[$slug] ?? false;
    }

    /** @return string[] Active module slugs. */
    public static function active(): array {
        return array_keys(array_filter(self::states()));
    }

    /**
     * Toggle a module. Enabling scanner also enables analyzer (dependency).
     *
     * @return array New states.
     */
    public static function set(string $slug, bool $on): array {
        $states = self::states();
        if (!array_key_exists($slug, $states)) {
            return $states;
        }
        $states[$slug] = $on;
        if ($on && $slug === 'scanner') {
            $states['analyzer'] = true;
        }
        update_option(self::OPTION, $states);
        return $states;
    }
}
