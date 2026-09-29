<?php
namespace TQK_WPSEO\Schema;

defined('ABSPATH') || exit;

/** S3 — Assembles the graph per context. Hooks into Frontend via filter. */
class Schema {

    public static function hooks(): void {
        add_filter('tqk_wpseo_schema_html', [self::class, 'render_for'], 10, 3);
        // One Product node per page: WooCommerce prints its own JSON-LD
        // Product (without offers for "Liên hệ" items). Ours decides.
        add_filter('woocommerce_structured_data_product', '__return_empty_array', 99);
    }

    public static function render_for(string $html, array $ctx, array $data): string {
        return $html . self::build($ctx, $data)->render();
    }

    public static function build(array $ctx, array $data): Graph {
        $g = new Graph();
        $g->add(Nodes::organization())->add(Nodes::website());

        $type = $ctx['type'] ?? 'general';
        if ($type === 'singular') {
            $post = get_post((int) ($ctx['object_id'] ?? 0));
            $g->add(Nodes::webpage($data));
            if ($post) {
                $schema_type = self::type_for($post);
                $built = Builder::build((int) $post->ID, $schema_type);
                if ($built !== []) {
                    $g->add($built);
                } elseif ($schema_type === 'Product') {
                    $g->add(Nodes::product($post, $data));
                } elseif ($schema_type !== 'WebPage') {
                    // Builder refused (missing/invalid data) → fall back to a
                    // plain Article. Emitting the chosen type with none of its
                    // required data is what got ignored by search engines.
                    $g->add(Nodes::article($post, $data, 'Article'));
                }
            }
            $trail = \TQK_WPSEO\Core\Breadcrumb::trail($ctx);
            $g->add(Nodes::breadcrumb_list($trail, (string) ($data['canonical'] ?? home_url('/'))));
        } elseif ($type === 'home') {
            $g->add(Nodes::webpage($data));
        } elseif ($type === 'archive' && ($data['canonical'] ?? '') !== '') {
            // Category / tag / author / post-type archives: a listing page.
            $g->add(Nodes::webpage($data, 'CollectionPage'));
            $g->add(Nodes::breadcrumb_list(\TQK_WPSEO\Core\Breadcrumb::trail($ctx), (string) $data['canonical']));
        }
        return $g->prune_refs();
    }

    /**
     * Template resolution: per-post override → conditional rules →
     * schema_map[post_type] → default. Filterable via tqk_wpseo_schema_type.
     */
    public static function type_for($post): string {
        $id = (int) ($post->ID ?? 0);
        $override = $id > 0 ? (string) get_post_meta($id, '_tqk_wpseo_schema_type', true) : '';
        if ($override !== '') {
            return (string) apply_filters('tqk_wpseo_schema_type', $override, $post);
        }
        $pt = (string) ($post->post_type ?? 'post');
        foreach ((array) \TQK_WPSEO\Admin\Settings::get('schema_rules', []) as $rule) {
            if (!is_array($rule) || ($rule['post_type'] ?? '') !== $pt) {
                continue;
            }
            $tax = (string) ($rule['taxonomy'] ?? '');
            $term = (string) ($rule['term'] ?? '');
            if ($tax !== '' && $term !== '') {
                if (!function_exists('has_term') || !has_term($term, $tax, $post)) {
                    continue;
                }
            }
            return (string) apply_filters('tqk_wpseo_schema_type', (string) ($rule['schema_type'] ?? ''), $post);
        }
        $map = \TQK_WPSEO\Admin\Settings::get('schema_map', []);
        $type = is_array($map) ? ($map[$pt] ?? '') : '';
        if ($type === '') {
            $type = $pt === 'page' ? 'WebPage' : 'Article';
        }
        return (string) apply_filters('tqk_wpseo_schema_type', $type, $post);
    }
}
