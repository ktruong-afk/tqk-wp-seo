<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

/**
 * SEO fields on category / tag / product-category edit screens: title,
 * description, focus keyword, robots, canonical. Stored as term meta with the
 * same keys as posts (_tqk_wpseo_*), read by Core\Data::archive(), and filled
 * by the Yoast / Rank Math importer.
 */
class TermFields {

    public const NONCE = 'tqk_wpseo_term';

    /** @return array<string,array{0:string,1:string}> meta key => [label, type] */
    public static function fields(): array {
        return [
            '_tqk_wpseo_title' => [__('SEO Title', 'tqk-wp-seo'), 'text'],
            '_tqk_wpseo_description' => [__('Meta description', 'tqk-wp-seo'), 'textarea'],
            '_tqk_wpseo_focus_keyword' => [__('Focus keyword', 'tqk-wp-seo'), 'text'],
            '_tqk_wpseo_robots' => [__('Robots', 'tqk-wp-seo'), 'robots'],
            '_tqk_wpseo_canonical' => [__('Canonical URL', 'tqk-wp-seo'), 'url'],
        ];
    }

    public static function hooks(): void {
        add_action('admin_init', [self::class, 'register']);
    }

    public static function register(): void {
        foreach ((array) get_taxonomies(['public' => true]) as $tax) {
            if ($tax === 'post_format') {
                continue;
            }
            add_action($tax . '_edit_form_fields', [self::class, 'render'], 10, 2);
            add_action('edited_' . $tax, [self::class, 'save'], 10, 2);
        }
    }

    public static function render($term, $taxonomy = ''): void {
        if (!Access::can()) {
            return;
        }
        $id = (int) ($term->term_id ?? 0);
        echo '<tr class="form-field"><th colspan="2"><h2 style="margin:12px 0 0">' . esc_html__('SEO (TQK)', 'tqk-wp-seo') . '</h2>';
        wp_nonce_field(self::NONCE, 'tqk_wpseo_term_nonce');
        echo '</th></tr>';
        foreach (self::fields() as $key => [$label, $type]) {
            $val = (string) get_term_meta($id, $key, true);
            echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            if ($type === 'textarea') {
                echo '<textarea id="' . esc_attr($key) . '" name="tqk_term[' . esc_attr($key) . ']" rows="3">' . esc_textarea($val) . '</textarea>';
            } elseif ($type === 'robots') {
                echo '<select id="' . esc_attr($key) . '" name="tqk_term[' . esc_attr($key) . ']">';
                foreach (Metabox::robot_options() as $v => $l) {
                    echo '<option value="' . esc_attr($v) . '"' . ($val === $v ? ' selected' : '') . '>' . esc_html($l) . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input type="' . ($type === 'url' ? 'url' : 'text') . '" id="' . esc_attr($key) . '" name="tqk_term[' . esc_attr($key) . ']" value="' . esc_attr($val) . '">';
            }
            if ($key === '_tqk_wpseo_title') {
                echo '<p class="description">' . esc_html__('Empty = site title template. Variables: %title%, %site_name%.', 'tqk-wp-seo') . '</p>';
            }
            echo '</td></tr>';
        }
    }

    /** @param array $input meta key => raw value */
    public static function clean(array $input): array {
        $out = [];
        foreach (self::fields() as $key => [, $type]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = (string) wp_unslash($input[$key]);
            $out[$key] = match ($type) {
                'textarea' => sanitize_textarea_field($raw),
                'url' => preg_match('#^https?://#i', trim($raw)) ? esc_url_raw(trim($raw)) : '',
                'robots' => array_key_exists($raw, Metabox::robot_options()) ? $raw : '',
                default => sanitize_text_field($raw),
            };
        }
        return $out;
    }

    public static function save(int $term_id, int $tt_id = 0): void {
        if (!isset($_POST['tqk_wpseo_term_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['tqk_wpseo_term_nonce'])), self::NONCE)) {
            return;
        }
        if (!current_user_can('edit_term', $term_id) || !Access::can()) {
            return;
        }
        foreach (self::clean(is_array($_POST['tqk_term'] ?? null) ? $_POST['tqk_term'] : []) as $key => $val) {
            $val === '' ? delete_term_meta($term_id, $key) : update_term_meta($term_id, $key, wp_slash($val));
        }
        \TQK_WPSEO\Core\Data::invalidate_for_term($term_id);
    }
}
