<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

/**
 * Setup wizard: hidden admin page shown once after activation.
 * Fully AJAX: first paint is server HTML, every Continue/Skip swaps the
 * step container in place — no page reload. Steps: welcome → business →
 * templates → indexability → sitemap → AI → finish. Every step skippable.
 */
class Wizard {

    public const SLUG = 'tqk-wpseo-wizard';
    public const PENDING = 'tqk_wpseo_wizard_pending';

    public static function hooks(): void {
        add_action('admin_menu', [self::class, 'register']);
        add_action('admin_init', [self::class, 'maybe_redirect']);
    }

    public static function register(): void {
        add_submenu_page(
            \TQK_WPSEO\Admin\Menu::TOP_SLUG,
            __('Setup Wizard', 'tqk-wpseo'),
            __('Setup Wizard', 'tqk-wpseo'),
            \TQK_WPSEO\Admin\Access::CAP,
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function maybe_redirect(): void {
        if (!get_option(self::PENDING, false)) {
            return;
        }
        if (!\TQK_WPSEO\Admin\Access::can()) {
            return;
        }
        if (($_GET['page'] ?? '') === self::SLUG) {
            return;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        wp_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    /** @return array Step number => title. */
    public static function steps(): array {
        return [
            1 => __('Welcome', 'tqk-wpseo'),
            2 => __('Business', 'tqk-wpseo'),
            3 => __('Templates', 'tqk-wpseo'),
            4 => __('Indexability', 'tqk-wpseo'),
            5 => __('Sitemap', 'tqk-wpseo'),
            6 => __('Local AI', 'tqk-wpseo'),
            7 => __('Finish', 'tqk-wpseo'),
        ];
    }

    public static function render(): void {
        if (!\TQK_WPSEO\Admin\Access::can()) {
            View::page(__('Setup Wizard', 'tqk-wpseo'), View::empty_state(
                __('Permission denied', 'tqk-wpseo'),
                __('You need the manage_options capability to view this page.', 'tqk-wpseo')
            ));
            return;
        }
        $step = min(7, max(1, (int) ($_GET['step'] ?? 1)));
        $body = '<div class="tqk-wpseo-wizard" id="tqk-wpseo-wizard" data-step="' . $step . '">'
            . '<div id="tqk-wpseo-wizard-progress">' . self::progress($step) . '</div>'
            . '<div id="tqk-wpseo-wizard-body">' . self::step_html($step) . '</div>'
            . '</div>';
        View::page(__('Setup Wizard', 'tqk-wpseo'), $body);
    }

    /** AJAX: save current step fields, return next step HTML. */
    public static function ajax(): void {
        $step = min(7, max(1, (int) ($_POST['step'] ?? 1)));
        $skip = !empty($_POST['skip']);
        if (!$skip) {
            self::save_step($step, $_POST);
        }
        if ($step >= 7) {
            delete_option(self::PENDING);
            wp_send_json_success(['done' => true, 'html' => self::finish_html()]);
        }
        $next = $step + 1;
        wp_send_json_success(['done' => false, 'step' => $next, 'progress' => self::progress($next), 'html' => self::step_html($next)]);
    }

    private static function save_step(int $step, array $input): void {
        $settings = array_merge(\TQK_WPSEO\Core\Activator::default_settings(), (array) get_option(TQK_WPSEO_OPTION, []));
        if ($step === 2) {
            foreach (['business_name', 'business_industry', 'business_audience', 'business_tone', 'business_phone', 'business_address', 'business_hours'] as $k) {
                if (isset($input[$k])) {
                    $settings[$k] = sanitize_text_field(wp_unslash((string) $input[$k]));
                }
            }
            if (isset($input['business_description'])) {
                $settings['business_description'] = sanitize_textarea_field(wp_unslash((string) $input['business_description']));
            }
        }
        if ($step === 3) {
            foreach (['title_template', 'description_template'] as $k) {
                if (isset($input[$k])) {
                    $settings[$k] = sanitize_text_field(wp_unslash((string) $input[$k]));
                }
            }
        }
        if ($step === 4) {
            foreach (['noindex_attachments', 'noindex_search', 'noindex_author', 'noindex_tag'] as $k) {
                $settings[$k] = !empty($input[$k]);
            }
        }
        if ($step === 5) {
            $settings['sitemap_enabled'] = !empty($input['sitemap_enabled']);
            $maps = [];
            $in_maps = is_array($input['sitemap_maps'] ?? null) ? $input['sitemap_maps'] : [];
            foreach (['post', 'page', 'category', 'post_tag'] as $m) {
                if (!empty($in_maps[$m])) {
                    $maps[] = $m;
                }
            }
            $settings['sitemap_maps'] = $maps === [] ? ['post', 'page'] : $maps;
        }
        if ($step === 6) {
            $provider = sanitize_text_field(wp_unslash((string) ($input['ai_provider'] ?? 'none')));
            $settings['ai_provider'] = in_array($provider, ['none', 'ollama', 'lmstudio'], true) ? $provider : 'none';
            if (isset($input['ai_endpoint'])) {
                $settings['ai_endpoint'] = sanitize_text_field(wp_unslash((string) $input['ai_endpoint']));
            }
            if (isset($input['ai_model'])) {
                $settings['ai_model'] = sanitize_text_field(wp_unslash((string) $input['ai_model']));
            }
        }
        update_option(TQK_WPSEO_OPTION, $settings);
    }

    private static function progress(int $step): string {
        $html = '<ol class="tqk-wpseo-steps">';
        foreach (self::steps() as $n => $label) {
            $cls = $n < $step ? 'is-done' : ($n === $step ? 'is-now' : '');
            $html .= sprintf('<li class="%s"><span>%d</span>%s</li>', esc_attr($cls), $n, esc_html($label));
        }
        return $html . '</ol>';
    }

    private static function form_open(int $step): string {
        return sprintf(
            '<form data-tqk-wizard="%d"><input type="hidden" name="_ajax_nonce" value="%s">',
            $step,
            esc_attr(wp_create_nonce(Ajax::NONCE))
        );
    }

    private static function nav(int $step): string {
        $next = $step >= 7 ? __('Finish', 'tqk-wpseo') : __('Continue', 'tqk-wpseo');
        $html = sprintf(
            '<p class="tqk-wpseo-wizard-nav"><button type="submit" class="button button-primary button-large">%s</button> ',
            esc_html($next)
        );
        if ($step < 7) {
            $html .= sprintf(
                '<button type="button" class="button button-large" data-tqk-wizard-skip="%d">%s</button>',
                $step,
                esc_html__('Skip', 'tqk-wpseo')
            );
        }
        return $html . '</p></form>';
    }

    private static function step_html(int $step): string {
        $s = array_merge(\TQK_WPSEO\Core\Activator::default_settings(), (array) get_option(TQK_WPSEO_OPTION, []));
        switch ($step) {
            case 2:
                return self::form_open(2)
                    . '<h2>' . esc_html__('Your business', 'tqk-wpseo') . '</h2>'
                    . '<p>' . esc_html__('Tell us your industry so Local AI writes in the right context. Skippable.', 'tqk-wpseo') . '</p>'
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="business_name" value="%s" placeholder="%s"></label></p>', esc_html__('Business name', 'tqk-wpseo'), esc_attr((string) $s['business_name']), esc_attr__('VD: Nha khoa An Khang', 'tqk-wpseo'))
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="business_industry" value="%s" placeholder="%s"></label></p>', esc_html__('Industry', 'tqk-wpseo'), esc_attr((string) $s['business_industry']), esc_attr__('VD: Nha khoa, Bat dong san, Thoi trang', 'tqk-wpseo'))
                    . sprintf('<p><label><strong>%s</strong><br><textarea class="large-text" rows="2" name="business_description">%s</textarea></label></p>', esc_html__('What do you do?', 'tqk-wpseo'), esc_html((string) $s['business_description']))
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="business_audience" value="%s"></label></p>', esc_html__('Target customers', 'tqk-wpseo'), esc_attr((string) $s['business_audience']))
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="business_tone" value="%s"></label></p>', esc_html__('Writing tone', 'tqk-wpseo'), esc_attr((string) $s['business_tone']))
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="business_phone" value="%s"></label></p>', esc_html__('Phone', 'tqk-wpseo'), esc_attr((string) ($s['business_phone'] ?? '')))
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="business_address" value="%s"></label></p>', esc_html__('Address', 'tqk-wpseo'), esc_attr((string) ($s['business_address'] ?? '')))
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="business_hours" value="%s"></label></p>', esc_html__('Opening hours', 'tqk-wpseo'), esc_attr((string) ($s['business_hours'] ?? '')))
                    . self::nav(2);
            case 3:
                return self::form_open(3)
                    . '<h2>' . esc_html__('Templates', 'tqk-wpseo') . '</h2>'
                    . '<p>' . esc_html__('Fallbacks used when a post has no custom SEO title or description.', 'tqk-wpseo') . '</p>'
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="title_template" value="%s"></label></p>', esc_html__('Title template', 'tqk-wpseo'), esc_attr((string) $s['title_template']))
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="description_template" value="%s"></label></p>', esc_html__('Description template', 'tqk-wpseo'), esc_attr((string) $s['description_template']))
                    . self::nav(3);
            case 4:
                $html = self::form_open(4)
                    . '<h2>' . esc_html__('Indexability', 'tqk-wpseo') . '</h2>'
                    . '<p>' . esc_html__('Choose what search engines may index. Attachments and search pages are hidden by default.', 'tqk-wpseo') . '</p>';
                foreach (['noindex_attachments' => __('Noindex attachments', 'tqk-wpseo'), 'noindex_search' => __('Noindex search pages', 'tqk-wpseo'), 'noindex_author' => __('Noindex author archives', 'tqk-wpseo'), 'noindex_tag' => __('Noindex tag archives', 'tqk-wpseo')] as $k => $label) {
                    $html .= View::switch($k, !empty($s[$k]), $label);
                }
                return $html . self::nav(4);
            case 5:
                $html = self::form_open(5)
                    . '<h2>' . esc_html__('Sitemap', 'tqk-wpseo') . '</h2>';
                $html .= View::switch('sitemap_enabled', !empty($s['sitemap_enabled']), __('Enable sitemap', 'tqk-wpseo'));
                foreach (['post' => __('Posts', 'tqk-wpseo'), 'page' => __('Pages', 'tqk-wpseo'), 'category' => __('Categories', 'tqk-wpseo'), 'post_tag' => __('Tags', 'tqk-wpseo')] as $m => $label) {
                    $html .= View::switch('sitemap_maps[' . $m . ']', in_array($m, (array) $s['sitemap_maps'], true), $label);
                }
                return $html . self::nav(5);
            case 6:
                $providers = ['none' => __('None', 'tqk-wpseo'), 'ollama' => 'Ollama', 'lmstudio' => 'LM Studio'];
                $opts = '';
                foreach ($providers as $v => $label) {
                    $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($v), $v === $s['ai_provider'] ? ' selected' : '', esc_html($label));
                }
                return self::form_open(6)
                    . '<h2>' . esc_html__('Local AI', 'tqk-wpseo') . '</h2>'
                    . '<p>' . esc_html__('Optional. Suggestions run against your own machine — no API fees. You can skip this entirely.', 'tqk-wpseo') . '</p>'
                    . sprintf('<p><label><strong>%s</strong><br><select name="ai_provider">%s</select></label></p>', esc_html__('Provider', 'tqk-wpseo'), $opts)
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="ai_endpoint" value="%s"></label></p>', esc_html__('Endpoint', 'tqk-wpseo'), esc_attr((string) $s['ai_endpoint']))
                    . sprintf('<p><label><strong>%s</strong><br><input type="text" class="large-text" name="ai_model" value="%s"></label></p>', esc_html__('Model', 'tqk-wpseo'), esc_attr((string) $s['ai_model']))
                    . self::nav(6);
            case 7:
                return self::form_open(7)
                    . '<h2>' . esc_html__('Ready', 'tqk-wpseo') . '</h2>'
                    . '<p>' . esc_html__('Everything is configured. Run your first scan to fill the dashboard.', 'tqk-wpseo') . '</p>'
                    . self::nav(7);
            default:
                return self::form_open(1)
                    . '<h2>' . esc_html__('Welcome to TQK WP SEO', 'tqk-wpseo') . '</h2>'
                    . '<p>' . esc_html__('A personal SEO engine: offline-first, no fees, local AI optional. Seven quick steps and you are done.', 'tqk-wpseo') . '</p>'
                    . self::nav(1);
        }
    }

    private static function finish_html(): string {
        return '<div class="tqk-wpseo-empty"><span class="tqk-wpseo-empty-ico">'
            . '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>'
            . '</span><h2>' . esc_html__('Setup complete', 'tqk-wpseo') . '</h2>'
            . '<p>' . esc_html__('Your site is ready. Open the dashboard or run the first scan now.', 'tqk-wpseo') . '</p>'
            . '<p><a class="tqk-btn tqk-btn-primary" href="' . esc_url(admin_url('admin.php?page=tqk-wpseo')) . '">' . esc_html__('Go to Dashboard', 'tqk-wpseo') . '</a> '
            . '<a class="tqk-btn tqk-btn-outline" href="' . esc_url(admin_url('admin.php?page=tqk-wpseo-site-analysis')) . '">' . esc_html__('Run first scan', 'tqk-wpseo') . '</a></p></div>';
    }
}
