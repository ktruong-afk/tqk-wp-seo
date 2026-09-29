<?php
namespace TQK_WPSEO\Analyzer;

defined('ABSPATH') || exit;

/**
 * Rendered-HTML analyze: for users who can manage options, the rendered page
 * (shortcodes/builder output included) is analyzed on the fly and stored for
 * the metabox + dashboard. No floating badge (removed): the Audit website
 * popup is the single place. Visitors get zero overhead (no buffering at all).
 */
class Frontend {

    public static function hooks(): void {
        add_action('template_redirect', [self::class, 'maybe_buffer'], 0);
    }

    public static function maybe_buffer(): void {
        if (is_admin() || !is_singular()) {
            return;
        }
        if (!\TQK_WPSEO\Core\Modules::is_active('analyzer')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        // Live Audit loads each page 4 times (one per viewport): analyze once.
        if (isset($_GET['tqk_rs']) && $_GET['tqk_rs'] !== 'desktop') {
            return;
        }
        ob_start([self::class, 'inject']);
    }

    public static function inject(string $html): string {
        $id = (int) get_queried_object_id();
        if ($id <= 0) {
            return $html;
        }
        $main = self::main_html($html);
        // Kept for later analyses (after save, live in the editor): builder
        // pages have almost nothing in post_content. Capped at 400 KB.
        if (strlen($main) < 400000) {
            update_post_meta($id, Analyzer::RENDERED, wp_slash((string) wp_json_encode(['at' => time(), 'html' => $main])));
        }
        Analyzer::analyze($id, $main);
        return $html;
    }

    /** Extracts readable content: main/article minus header/footer/nav/aside. */
    public static function main_html(string $html): string {
        $dom = new \DOMDocument();
        set_error_handler(fn() => true);
        try {
            $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        } finally {
            restore_error_handler();
        }
        $xp = new \DOMXPath($dom);
        foreach ($xp->query('//header | //footer | //nav | //aside | //script | //style') as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (['//main', '//article', '//*[@id="content"]', '//body'] as $q) {
            $found = $xp->query($q);
            if ($found && $found->length > 0) {
                return $dom->saveHTML($found->item(0)) ?: '';
            }
        }
        return '';
    }
}
