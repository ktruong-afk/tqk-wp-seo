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
            'sitemap' => [__('Sitemap & robots.txt', 'tqk-wpseo'), __('XML sitemap per post type, robots.txt editor, llms.txt.', 'tqk-wpseo'), true],
            'schema' => [__('Schema & Breadcrumb', 'tqk-wpseo'), __('JSON-LD @graph, templates per post type, BreadcrumbList.', 'tqk-wpseo'), true],
            'analyzer' => [__('Content Analyzer', 'tqk-wpseo'), __('Focus keyword, headings, images, links, readability + score 0-100.', 'tqk-wpseo'), true],
            'scanner' => [__('Site Scanner', 'tqk-wpseo'), __('Batch audits 50/job, dashboard snapshot, orphan reports. Needs Analyzer.', 'tqk-wpseo'), true],
            'analytics' => [__('Analytics (Google)', 'tqk-wpseo'), __('Search Console clicks/impressions/CTR/position.', 'tqk-wpseo'), true],
            'redirects' => [__('Redirects', 'tqk-wpseo'), __('301/302/307/410 manager, auto-slug, CSV import/export.', 'tqk-wpseo'), true],
            'monitor-404' => [__('404 Monitor', 'tqk-wpseo'), __('Miss logging + hits + referrer, one-click redirect.', 'tqk-wpseo'), true],
            'links' => [__('Link Tools', 'tqk-wpseo'), __('Internal graph, orphans, broken-link checker.', 'tqk-wpseo'), true],
            'image-seo' => [__('Image SEO', 'tqk-wpseo'), __('ALT/dimensions/weight audit, auto ALT fallback, lazy-load check.', 'tqk-wpseo'), true],
            'role-manager' => [__('Role Manager', 'tqk-wpseo'), __('Capability per role for SEO screens.', 'tqk-wpseo'), true],
            'instant-indexing' => [__('Instant Indexing', 'tqk-wpseo'), __('Google Indexing API + Bing URL submission on publish.', 'tqk-wpseo'), false],
            'local-seo' => [__('Local SEO', 'tqk-wpseo'), __('Business profile → LocalBusiness schema + NAP.', 'tqk-wpseo'), true],
            'woo' => [__('WooCommerce SEO', 'tqk-wpseo'), __('Product Offer/Review schema, product sitemap. Loads only with Woo.', 'tqk-wpseo'), true],
            'ai' => [__('Local AI', 'tqk-wpseo'), __('Ollama/LM Studio suggestions with preview-approve + business context.', 'tqk-wpseo'), true],
            'mcp' => [__('MCP Tools', 'tqk-wpseo'), __('REST tools for local AI agents (token auth).', 'tqk-wpseo'), true],
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
