<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

/** S5 — Settings registration + sanitization. Form lives in Pages::settings(). */
class Settings {

    public static function register(): void {
        add_action('admin_init', [self::class, 'on_admin_init']);
    }

    public static function on_admin_init(): void {
        register_setting('tqk_wpseo', TQK_WPSEO_OPTION, ['sanitize_callback' => [self::class, 'sanitize']]);
    }

    /** @return array Sanitized settings. Merges over SAVED values so partial forms never wipe other cards. */
    public static function sanitize($input): array {
        $input = is_array($input) ? $input : [];
        $defaults = \TQK_WPSEO\Core\Activator::default_settings();
        $saved = get_option(TQK_WPSEO_OPTION, []);
        $out = array_merge($defaults, is_array($saved) ? $saved : []);
        foreach (['title_template', 'description_template', 'ai_provider', 'ai_endpoint', 'ai_model', 'business_name', 'business_industry', 'business_description', 'business_audience', 'business_tone', 'business_phone', 'business_address', 'business_hours', 'social_default_image', 'gsc_property', 'gsc_api_key', 'gsc_token', 'bing_api_key', 'gemini_api_key', 'gemini_model', 'report_brand_name', 'report_brand_logo', 'report_brand_contact'] as $k) {
            if (isset($input[$k])) {
                $val = $k === 'business_description'
                    ? sanitize_textarea_field(wp_unslash((string) $input[$k]))
                    : (in_array($k, ['social_default_image', 'report_brand_logo'], true)
                        ? esc_url(wp_unslash((string) $input[$k]))
                        : sanitize_text_field(wp_unslash((string) $input[$k])));
                // Secret fields render masked ('********'), so the mask itself
                // or an empty submission means "keep the stored value".
                if (\TQK_WPSEO\Core\Secrets::is_secret($k) && !empty($saved[$k]) && ($val === '' || preg_match('/^\*+$/', $val))) {
                    continue;
                }
                if (\TQK_WPSEO\Core\Secrets::is_secret($k) && preg_match('/^\*+$/', $val)) {
                    $val = '';
                }
                $out[$k] = $val;
            }
        }
        foreach (['noindex_attachments', 'noindex_search', 'noindex_author', 'noindex_tag', 'social_use_content_image'] as $k) {
            if (array_key_exists($k, $input)) {
                $out[$k] = !empty($input[$k]);
            }
        }
        if (array_key_exists('sitemap_enabled', $input) || array_key_exists('sitemap_maps', $input)) {
            $out['sitemap_enabled'] = !empty($input['sitemap_enabled']);
        }
        foreach (['indexing_google', 'indexing_bing', 'mcp_direct_write'] as $k) {
            if (array_key_exists($k, $input)) {
                $out[$k] = !empty($input[$k]);
            }
        }
        if (array_key_exists('sitemap_maps', $input)) {
            $maps = [];
            $allowed_maps = array_merge(['post', 'page', 'category', 'post_tag', 'news', 'video'], function_exists('get_post_types') ? array_keys((array) get_post_types(['public' => true])) : []);
            foreach ($allowed_maps as $m) {
                if (!empty($input['sitemap_maps'][$m] ?? '')) {
                    $maps[] = $m;
                }
            }
            $out['sitemap_maps'] = $maps === [] ? ['post', 'page'] : array_values(array_unique($maps));
        }
        if (!in_array($out['ai_provider'], ['none', 'ollama', 'lmstudio', 'gemini'], true)) {
            $out['ai_provider'] = 'none';
        }
        if (!\TQK_WPSEO\AI\GeminiProvider::valid_model(strtolower((string) $out['gemini_model']))) {
            $out['gemini_model'] = $defaults['gemini_model'];
        }
        $out['gemini_model'] = strtolower((string) $out['gemini_model']);
        if (array_key_exists('product_no_price', $input)) {
            $out['product_no_price'] = ($input['product_no_price'] ?? '') === 'no_offers' ? 'no_offers' : 'skip';
        }
        if (array_key_exists('sitemap_slug', $input)) {
            $out['sitemap_slug'] = \TQK_WPSEO\Sitemap\Sitemap::clean_slug((string) wp_unslash($input['sitemap_slug']));
        }
        if (array_key_exists('audit_keep_days', $input)) {
            $out['audit_keep_days'] = in_array((int) $input['audit_keep_days'], [1, 7, 30], true) ? (int) $input['audit_keep_days'] : 1;
        }
        if (array_key_exists('report_locale', $input)) {
            $out['report_locale'] = in_array($input['report_locale'], ['vi', 'en_US'], true) ? $input['report_locale'] : '';
        }
        if (array_key_exists('report_brand_color', $input)) {
            $c = sanitize_hex_color((string) $input['report_brand_color']);
            $out['report_brand_color'] = $c ?: $defaults['report_brand_color'];
        }
        foreach (['ai_rpm' => [1, 1000], 'ai_rpd' => [1, 100000]] as $k => [$min, $max]) {
            if (array_key_exists($k, $input)) {
                $out[$k] = max($min, min($max, (int) $input[$k]));
            }
        }
        $allowed_types = ['Article', 'BlogPosting', 'WebPage', 'Product', 'Course', 'FAQPage', 'HowTo', 'Recipe', 'Event'];
        if (array_key_exists('schema_map', $input)) {
            $map = [];
            $in_map = is_array($input['schema_map'] ?? null) ? $input['schema_map'] : [];
            $pts = function_exists('get_post_types') ? array_keys((array) get_post_types(['public' => true])) : ['post', 'page', 'product'];
            $pts = array_values(array_unique(array_merge(['post', 'page', 'product'], $pts)));
            foreach ($pts as $pt) {
                $t = sanitize_text_field(wp_unslash((string) ($in_map[$pt] ?? '')));
                $map[$pt] = in_array($t, $allowed_types, true) ? $t : ($defaults['schema_map'][$pt] ?? 'WebPage');
            }
            $out['schema_map'] = $map;
        }
        if (array_key_exists('schema_rules', $input)) {
            $rules = [];
            $in_rules = is_array($input['schema_rules'] ?? null) ? $input['schema_rules'] : [];
            foreach (array_slice($in_rules, 0, 20) as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $pt = sanitize_key((string) ($r['post_type'] ?? ''));
                $type = sanitize_text_field(wp_unslash((string) ($r['schema_type'] ?? '')));
                if ($pt === '' || !in_array($type, $allowed_types, true)) {
                    continue;
                }
                $rules[] = [
                    'post_type' => $pt,
                    'taxonomy' => sanitize_key((string) ($r['taxonomy'] ?? '')),
                    'term' => sanitize_text_field(wp_unslash((string) ($r['term'] ?? ''))),
                    'schema_type' => $type,
                ];
            }
            $out['schema_rules'] = array_values($rules);
        }
        if (array_key_exists('seo_roles', $input)) {
            $roles = [];
            $in_roles = is_array($input['seo_roles'] ?? null) ? $input['seo_roles'] : [];
            foreach (['editor', 'author', 'contributor'] as $r) {
                $roles[$r] = !empty($in_roles[$r]);
            }
            $out['seo_roles'] = $roles;
        }
        // Secrets are encrypted at rest; plaintext never reaches wp_options.
        return \TQK_WPSEO\Core\Secrets::encrypt_settings($out);
    }

    public static function get(string $key, $default = null) {
        $all = get_option(TQK_WPSEO_OPTION, []);
        if (is_array($all) && array_key_exists($key, $all)) {
            if (\TQK_WPSEO\Core\Secrets::is_secret($key)) {
                return \TQK_WPSEO\Core\Secrets::decrypt((string) $all[$key]);
            }
            return $all[$key];
        }
        $defaults = \TQK_WPSEO\Core\Activator::default_settings();
        return $default ?? ($defaults[$key] ?? null);
    }
}
