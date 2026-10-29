<?php
namespace TQK_WPSEO\Indexing;

defined('ABSPATH') || exit;

/**
 * Instant Indexing: pings Google Indexing API + Bing URL Submission
 * when public posts publish. Personal-use, free quotas, no SaaS.
 * Google reuses the GSC OAuth token; Bing needs its own API key.
 */
class Submitter {

    public const LOG_OPTION = 'tqk_wpseo_indexing_log';

    public static function hooks(): void {
        add_action('save_post', [self::class, 'on_save'], 20, 2);
    }

    public static function on_save(int $post_id, $post = null): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!\TQK_WPSEO\Core\Modules::is_active('instant-indexing')) {
            return;
        }
        $p = is_object($post) ? $post : get_post($post_id);
        if (!$p || ($p->post_status ?? '') !== 'publish') {
            return;
        }
        $pt = (string) ($p->post_type ?? '');
        $public = function_exists('get_post_types') ? (array) get_post_types(['public' => true]) : ['post' => 1, 'page' => 1];
        if (!isset($public[$pt]) && !in_array($pt, (array) $public, true)) {
            return;
        }
        if ((string) get_post_meta($post_id, '_tqk_wpseo_sitemap_exclude', true) === '1') {
            return;
        }
        $robots = (string) get_post_meta($post_id, '_tqk_wpseo_robots', true);
        if (str_contains(strtolower($robots), 'noindex')) {
            return;
        }
        $url = (string) get_permalink($post_id);
        if ($url === '') {
            return;
        }
        self::submit($url);
    }

    /** @return array Engine => [ok, message]. Skipped engines omitted. */
    public static function submit(string $url): array {
        $out = [];
        if ((bool) \TQK_WPSEO\Admin\Settings::get('indexing_google', false)) {
            $out['google'] = self::google($url);
        }
        if ((bool) \TQK_WPSEO\Admin\Settings::get('indexing_bing', false)) {
            $out['bing'] = self::bing($url);
        }
        if ($out !== []) {
            self::log($url, $out);
        }
        return $out;
    }

    /** @return array [ok, message] */
    public static function google(string $url): array {
        $token = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_token', ''));
        if ($token === '') {
            return ['ok' => false, 'message' => __('Missing GSC OAuth token.', 'tqk-wp-seo')];
        }
        $res = wp_remote_post('https://indexing.googleapis.com/v3/urlNotifications:publish', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['url' => $url, 'type' => 'URL_UPDATED']),
        ]);
        if (is_wp_error($res)) {
            return ['ok' => false, 'message' => __('Could not reach Google.', 'tqk-wp-seo')];
        }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            return ['ok' => false, 'message' => __('Google rejected the URL (check token).', 'tqk-wp-seo')];
        }
        return ['ok' => true, 'message' => __('Google notified.', 'tqk-wp-seo')];
    }

    /** @return array [ok, message] */
    public static function bing(string $url): array {
        $key = trim((string) \TQK_WPSEO\Admin\Settings::get('bing_api_key', ''));
        if ($key === '') {
            return ['ok' => false, 'message' => __('Missing Bing API key.', 'tqk-wp-seo')];
        }
        $res = wp_remote_post('https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch?apikey=' . rawurlencode($key), [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['siteUrl' => home_url('/'), 'urlList' => [$url]]),
        ]);
        if (is_wp_error($res)) {
            return ['ok' => false, 'message' => __('Could not reach Bing.', 'tqk-wp-seo')];
        }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            return ['ok' => false, 'message' => __('Bing rejected the URL (check key).', 'tqk-wp-seo')];
        }
        return ['ok' => true, 'message' => __('Bing notified.', 'tqk-wp-seo')];
    }

    /**
     * Registers a sitemap with Bing Webmaster Tools (SubmitFeed). Uses the
     * same API key as URL submission. @return array [ok, message]
     */
    public static function bing_sitemap(string $sitemap_url): array {
        $key = trim((string) \TQK_WPSEO\Admin\Settings::get('bing_api_key', ''));
        if ($key === '') {
            return ['ok' => false, 'message' => __('Missing Bing API key.', 'tqk-wp-seo')];
        }
        $res = wp_remote_post('https://ssl.bing.com/webmaster/api.svc/json/SubmitFeed?apikey=' . rawurlencode($key), [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['siteUrl' => home_url('/'), 'feedUrl' => $sitemap_url]),
        ]);
        if (is_wp_error($res)) {
            return ['ok' => false, 'message' => __('Could not reach Bing.', 'tqk-wp-seo')];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            $json = json_decode((string) wp_remote_retrieve_body($res), true);
            $msg = is_array($json) ? (string) ($json['Message'] ?? $json['message'] ?? '') : '';
            return ['ok' => false, 'message' => $msg !== '' ? 'Bing: ' . mb_substr($msg, 0, 200) : sprintf(__('Bing refused the sitemap (HTTP %d).', 'tqk-wp-seo'), $code)];
        }
        return ['ok' => true, 'message' => __('Sitemap sent to Bing.', 'tqk-wp-seo')];
    }

    private static function log(string $url, array $out): void {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, ['at' => gmdate('Y-m-d H:i'), 'url' => $url, 'result' => $out]);
        update_option(self::LOG_OPTION, array_slice($log, 0, 20));
    }

    /** @return array Recent log rows (newest first). */
    public static function recent(): array {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }
}
