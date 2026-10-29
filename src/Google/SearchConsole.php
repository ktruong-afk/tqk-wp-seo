<?php
namespace TQK_WPSEO\Google;

defined('ABSPATH') || exit;

/**
 * Google Search Console (personal-use, free quota) + local Rank Tracker.
 * Stores property + API key + OAuth token in main settings.
 * Each sync snapshots top queries into a history table → position/click
 * trends with zero SaaS. Daily cron keeps it fresh while connected.
 */
class SearchConsole {

    public const DATA_OPTION = 'tqk_wpseo_gsc_data';
    public const CRON_HOOK = 'tqk_wpseo_gsc_daily';

    public static function hooks(): void {
        add_action(self::CRON_HOOK, [self::class, 'daily']);
        add_action('admin_init', [self::class, 'ensure_cron']);
    }

    public static function status(): array {
        $prop = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_property', ''));
        $token = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_token', ''));
        $data = get_option(self::DATA_OPTION, []);
        $data = is_array($data) ? $data : [];
        return [
            'connected' => $prop !== '' && $token !== '',
            'property' => $prop,
            'synced_at' => (string) ($data['synced_at'] ?? ''),
            'rows' => (array) ($data['rows'] ?? []),
            'totals' => (array) ($data['totals'] ?? []),
        ];
    }

    /** @return array [ok, message, rows, totals] */
    /**
     * Submits a sitemap to Search Console (sitemaps.submit) and removes the
     * previous address (sitemaps.delete). Needs the property + an OAuth token
     * with the "webmasters" scope (read-only tokens can sync but not submit).
     *
     * @return array{ok:bool, message:string}
     */
    public static function submit_sitemap(string $sitemap_url, string $old_url = ''): array {
        $prop = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_property', ''));
        $token = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_token', ''));
        if ($prop === '' || $token === '') {
            return ['ok' => false, 'message' => __('Search Console is not connected: add the property and OAuth token, or submit the sitemap by hand in Search Console.', 'tqk-wp-seo')];
        }
        $base = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($prop) . '/sitemaps/';
        $args = ['timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Length' => '0']];
        $res = wp_remote_request($base . rawurlencode($sitemap_url), $args + ['method' => 'PUT']);
        if (is_wp_error($res)) {
            return ['ok' => false, 'message' => __('Could not reach Google API.', 'tqk-wp-seo')];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            $json = json_decode((string) wp_remote_retrieve_body($res), true);
            $msg = is_array($json) && isset($json['error']['message']) ? (string) $json['error']['message'] : sprintf('HTTP %d', $code);
            return ['ok' => false, 'message' => 'Google: ' . mb_substr($msg, 0, 200)];
        }
        if ($old_url !== '' && $old_url !== $sitemap_url) {
            wp_remote_request($base . rawurlencode($old_url), $args + ['method' => 'DELETE']);
        }
        return ['ok' => true, 'message' => __('Sitemap submitted to Google Search Console.', 'tqk-wp-seo')];
    }

    public static function sync(): array {
        $prop = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_property', ''));
        $token = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_token', ''));
        if ($prop === '' || $token === '') {
            return ['ok' => false, 'error' => __('Add property + OAuth token first (guide below).', 'tqk-wp-seo')];
        }
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($prop) . '/searchAnalytics/query';
        $body = wp_json_encode([
            'startDate' => gmdate('Y-m-d', time() - 28 * 86400),
            'endDate' => gmdate('Y-m-d'),
            'dimensions' => ['query'],
            'rowLimit' => 20,
        ]);
        $res = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => $body,
        ]);
        if (is_wp_error($res)) {
            return ['ok' => false, 'error' => __('Could not reach Google API.', 'tqk-wp-seo')];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $json = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !is_array($json)) {
            $msg = is_array($json) && isset($json['error']['message']) ? (string) $json['error']['message'] : __('Google API error (check token/property).', 'tqk-wp-seo');
            return ['ok' => false, 'error' => $msg];
        }
        $rows = [];
        $clicks = 0;
        $impr = 0;
        foreach ((array) ($json['rows'] ?? []) as $r) {
            $q = (string) (($r['keys'] ?? [])[0] ?? '');
            $c = (int) ($r['clicks'] ?? 0);
            $i = (int) ($r['impressions'] ?? 0);
            $ctr = (float) ($r['ctr'] ?? 0);
            $pos = (float) ($r['position'] ?? 0);
            $clicks += $c;
            $impr += $i;
            $rows[] = ['query' => $q, 'clicks' => $c, 'impressions' => $i, 'ctr' => $ctr, 'position' => $pos];
        }
        $data = ['synced_at' => gmdate('Y-m-d H:i'), 'rows' => $rows, 'totals' => ['clicks' => $clicks, 'impressions' => $impr]];
        update_option(self::DATA_OPTION, $data);
        self::snapshot($rows);
        return ['ok' => true, 'message' => __('Synced from Google.', 'tqk-wp-seo'), 'rows' => $rows, 'totals' => $data['totals']];
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tqk_wpseo_gsc';
    }

    /** Stores today's snapshot (replaces same-day rows → re-sync is idempotent). */
    public static function snapshot(array $rows): void {
        global $wpdb;
        $table = self::table();
        $day = gmdate('Y-m-d');
        $wpdb->delete($table, ['day' => $day]);
        foreach ($rows as $r) {
            $q = trim((string) ($r['query'] ?? ''));
            if ($q === '') {
                continue;
            }
            $wpdb->insert($table, [
                'day' => $day,
                'query' => mb_substr($q, 0, 255),
                'clicks' => (int) ($r['clicks'] ?? 0),
                'impressions' => (int) ($r['impressions'] ?? 0),
                'ctr' => (float) ($r['ctr'] ?? 0),
                'position' => (float) ($r['position'] ?? 0),
            ]);
        }
    }

    /** @return string[] Distinct tracked queries (newest first). */
    public static function queries(int $limit = 50): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT DISTINCT query FROM ' . self::table() . ' ORDER BY query ASC LIMIT ' . max(1, $limit)
        );
        $out = [];
        foreach ((array) $rows as $r) {
            $q = (string) ($r->query ?? '');
            if ($q !== '') {
                $out[] = $q;
            }
        }
        return $out;
    }

    /** @return array Rows for a query: [day, clicks, impressions, ctr, position]. */
    public static function history(string $query, int $days = 30): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT day, clicks, impressions, ctr, position FROM ' . self::table()
            . ' WHERE query = %s ORDER BY day DESC LIMIT %d',
            $query,
            max(1, $days)
        ));
        $out = [];
        foreach ((array) $rows as $r) {
            $out[] = [
                'day' => (string) ($r->day ?? ''),
                'clicks' => (int) ($r->clicks ?? 0),
                'impressions' => (int) ($r->impressions ?? 0),
                'ctr' => (float) ($r->ctr ?? 0),
                'position' => (float) ($r->position ?? 0),
            ];
        }
        return $out;
    }

    /** Daily cron: sync + snapshot while connected, then re-arm tomorrow. */
    public static function daily(): void {
        if (!\TQK_WPSEO\Core\Modules::is_active('analytics')) {
            return;
        }
        self::sync();
        self::ensure_cron();
    }

    public static function ensure_cron(): void {
        $prop = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_property', ''));
        $token = trim((string) \TQK_WPSEO\Admin\Settings::get('gsc_token', ''));
        if (($prop === '' || $token === '') || !function_exists('wp_next_scheduled')) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK) && function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 86400, self::CRON_HOOK);
        }
    }

    /** @return string Rank-history table for a query (with deltas vs previous day). */
    public static function history_html(string $query): string {
        $rows = self::history($query);
        if ($rows === []) {
            return '<p>' . esc_html__('No history for this query yet — Sync daily builds it.', 'tqk-wp-seo') . '</p>';
        }
        $html = '';
        $prev = null;
        foreach ($rows as $r) {
            $dpos = $prev === null ? '' : sprintf('%+.1f', (float) ($prev['position'] ?? 0) - (float) $r['position']);
            $dclk = $prev === null ? '' : sprintf('%+d', (int) $r['clicks'] - (int) ($prev['clicks'] ?? 0));
            $html .= sprintf(
                '<tr><td>%s</td><td>%.1f %s</td><td>%d %s</td><td>%d</td><td>%.1f%%</td></tr>',
                esc_html($r['day']),
                (float) $r['position'],
                esc_html($dpos),
                (int) $r['clicks'],
                esc_html($dclk),
                (int) $r['impressions'],
                ((float) $r['ctr']) * 100
            );
            $prev = $r;
        }
        return '<div class="tqk-wpseo-table-wrap"><div class="table-responsive"><table class="tqk-wpseo-table"><thead><tr>'
            . '<th>' . esc_html__('Day', 'tqk-wp-seo') . '</th><th>' . esc_html__('Position (Δ)', 'tqk-wp-seo') . '</th>'
            . '<th>' . esc_html__('Clicks (Δ)', 'tqk-wp-seo') . '</th><th>' . esc_html__('Impressions', 'tqk-wp-seo') . '</th><th>CTR</th>'
            . '</tr></thead><tbody>' . $html . '</tbody></table></div></div>';
    }

    public static function table_html(): string {
        $st = self::status();
        if ($st['rows'] === []) {
            return '<p>' . esc_html__('No performance data yet — connect above then Sync.', 'tqk-wp-seo') . '</p>';
        }
        $rows = '';
        foreach ($st['rows'] as $r) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%d</td><td>%d</td><td>%.1f%%</td><td>%.1f</td></tr>',
                esc_html((string) ($r['query'] ?? '')),
                (int) ($r['clicks'] ?? 0),
                (int) ($r['impressions'] ?? 0),
                ((float) ($r['ctr'] ?? 0)) * 100,
                (float) ($r['position'] ?? 0)
            );
        }
        return '<div class="tqk-wpseo-table-wrap"><div class="table-responsive"><table class="tqk-wpseo-table"><thead><tr>'
            . '<th>' . esc_html__('Query', 'tqk-wp-seo') . '</th><th>' . esc_html__('Clicks', 'tqk-wp-seo') . '</th>'
            . '<th>' . esc_html__('Impressions', 'tqk-wp-seo') . '</th><th>CTR</th><th>' . esc_html__('Position', 'tqk-wp-seo') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>'
            . '<p class="description">' . esc_html(sprintf(__('Total 28d: %d clicks · %d impressions · synced %s', 'tqk-wp-seo'), (int) ($st['totals']['clicks'] ?? 0), (int) ($st['totals']['impressions'] ?? 0), (string) ($st['synced_at'] ?? ''))) . '</p>';
    }
}
