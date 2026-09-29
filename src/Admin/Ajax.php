<?php
namespace TQK_WPSEO\Admin;

defined('ABSPATH') || exit;

use TQK_WPSEO\Redirect\Monitor404;
use TQK_WPSEO\Redirect\Redirects;

/**
 * All admin actions run over AJAX (single nonce `tqk-wpseo-ajax`).
 * Mutations append/remove rows or toast — pages never full-reload for actions.
 */
class Ajax {

    public const NONCE = 'tqk-wpseo-ajax';

    public static function hooks(): void {
        foreach (['wizard', 'redirect', 'four04', 'bulk', 'settings', 'robots', 'tools', 'report', 'links', 'mcp', 'module', 'gsc', 'gsc_history', 'schema_harvest', 'live_audit', 'ai_advisor', 'img_fix', 'import', 'security', 'license', 'sitemap_submit'] as $op) {
            add_action('wp_ajax_tqk_wpseo_' . $op, [self::class, 'route']);
        }
    }

    public static function route(): void {
        $action = (string) ($_POST['tqk_action'] ?? '');
        check_ajax_referer(self::NONCE);
        if (!\TQK_WPSEO\Admin\Access::can()) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        match ($action) {
            'wizard' => Wizard::ajax(),
            'redirect' => self::redirect(),
            'four04' => self::four04(),
            'bulk' => self::bulk(),
            'settings' => self::settings(),
            'robots' => self::robots(),
            'tools' => self::tools(),
            'report' => self::report(),
            'links' => self::links(),
            'mcp' => self::mcp(),
            'module' => self::module(),
            'gsc' => self::gsc(),
            'gsc_history' => self::gsc_history(),
            'schema_harvest' => self::schema_harvest(),
            'live_audit' => \TQK_WPSEO\Analyzer\LiveAudit::ajax(),
            'ai_advisor' => \TQK_WPSEO\AI\Advisor::ajax(),
            'img_fix' => \TQK_WPSEO\Analyzer\ImageFix::ajax(),
            'import' => \TQK_WPSEO\Tools\Importer::ajax(),
            'security' => \TQK_WPSEO\Security\Scan::ajax(),
            'license' => self::addon('license'),
            'sitemap_submit' => self::sitemap_submit(),
            default => wp_send_json_error(['message' => __('Unknown action', 'tqk-wpseo')]),
        };
    }

    /**
     * Routes handled by the Pro add-on (nonce already checked). Without the
     * add-on the action simply does not exist.
     */
    private static function addon(string $name): void {
        if (!has_action('tqk_wpseo_ajax_' . $name)) {
            wp_send_json_error(['message' => __('This needs the TQK WP SEO Pro add-on.', 'tqk-wpseo')]);
        }
        do_action('tqk_wpseo_ajax_' . $name);
        wp_send_json_error(['message' => __('Unknown action', 'tqk-wpseo')]);
    }

    private static function redirect(): void {
        $op = sanitize_key($_POST['op'] ?? '');
        if ($op === 'del') {
            Redirects::delete((int) ($_POST['id'] ?? 0));
            wp_send_json_success(['message' => __('Deleted.', 'tqk-wpseo')]);
        }
        if ($op === 'export') {
            $lines = ['source,target,type,hits'];
            foreach (Redirects::all(1000) as $r) {
                $lines[] = implode(',', [(string) $r->source, (string) $r->target, (string) $r->type, (string) $r->hits]);
            }
            wp_send_json_success(['csv' => implode("\n", $lines) . "\n"]);
        }
        $id = Redirects::add(
            sanitize_text_field(wp_unslash($_POST['source'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['target'] ?? '')),
            (int) ($_POST['type'] ?? 301)
        );
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid redirect (check URLs and type).', 'tqk-wpseo')]);
        }
        $row = Redirects::get(sanitize_text_field(wp_unslash($_POST['source'] ?? '')));
        wp_send_json_success(['message' => __('Redirect saved.', 'tqk-wpseo'), 'row' => Pages::redirect_row($row)]);
    }

    private static function four04(): void {
        Monitor404::delete((int) ($_POST['id'] ?? 0));
        wp_send_json_success(['message' => __('Deleted.', 'tqk-wpseo')]);
    }

    /**
     * Quick-fix save: single-place edit per URL. Save → re-analyze that ID only
     * → patch summary snapshot → UI marks finish. No full rescan unless requested.
     */
    private static function bulk(): void {
        $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        $n = 0;
        $fresh = [];
        foreach ($rows as $key => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $id = (int) $key;
            if ($id <= 0 && isset($fields['url'])) {
                $id = (int) url_to_postid((string) $fields['url']);
            }
            if ($id <= 0 || !get_post($id) || !current_user_can('edit_post', $id)) {
                continue;
            }
            $map = [
                'title' => '_tqk_wpseo_title',
                'desc' => '_tqk_wpseo_description',
                'keyword' => '_tqk_wpseo_focus_keyword',
                'secondary' => '_tqk_wpseo_secondary_keywords',
                'canonical' => '_tqk_wpseo_canonical',
                'robots' => '_tqk_wpseo_robots',
                'og_title' => '_tqk_wpseo_og_title',
                'og_description' => '_tqk_wpseo_og_description',
                'og_image' => '_tqk_wpseo_og_image',
                'schema' => '_tqk_wpseo_schema_type',
                'sitemap_exclude' => '_tqk_wpseo_sitemap_exclude',
            ];
            foreach ($map as $in => $meta) {
                if (!array_key_exists($in, $fields)) {
                    continue;
                }
                $raw = wp_unslash((string) $fields[$in]);
                if ($in === 'sitemap_exclude') {
                    $raw === '1' ? update_post_meta($id, $meta, '1') : delete_post_meta($id, $meta);
                    if ($raw === '1') {
                        \TQK_WPSEO\Sitemap\Sitemap::invalidate($id);
                    }
                    continue;
                }
                if ($in === 'canonical' || $in === 'og_image') {
                    $val = esc_url($raw);
                } elseif ($in === 'robots' && !array_key_exists($raw, \TQK_WPSEO\Admin\Metabox::robot_options())) {
                    continue;
                } elseif ($in === 'schema' && !array_key_exists($raw, \TQK_WPSEO\Admin\Metabox::schema_options())) {
                    continue;
                } else {
                    $val = sanitize_text_field($raw);
                }
                $val === '' ? delete_post_meta($id, $meta) : update_post_meta($id, $meta, $val);
            }
            \TQK_WPSEO\Core\Data::invalidate_for_post($id);
            delete_post_meta($id, '_tqk_wpseo_audit_cache');
            // Incremental: analyze only this ID, no full scan.
            $result = \TQK_WPSEO\Analyzer\Analyzer::analyze($id);
            $crit = 0;
            $warn = 0;
            foreach ((array) ($result['findings'] ?? []) as $f) {
                if (($f['severity'] ?? '') === 'critical') {
                    $crit++;
                } else {
                    $warn++;
                }
            }
            $fresh[$id] = [
                'score' => (int) ($result['score'] ?? 0),
                'critical' => $crit,
                'warnings' => $warn,
                'findings' => array_slice(array_values((array) ($result['findings'] ?? [])), 0, 8),
            ];
            $n++;
        }
        if ($fresh !== []) {
            self::patch_summary($fresh);
        }
        wp_send_json_success(['message' => sprintf(__('Saved %d posts.', 'tqk-wpseo'), $n), 'saved' => $n, 'fresh' => $fresh]);
    }

    /** Patch dashboard snapshot in place so saved cards finish without full rescan. */
    private static function patch_summary(array $fresh): void {
        $summary = \TQK_WPSEO\Scanner\Scanner::summary();
        if ($summary === [] || !isset($summary['pages'])) {
            return;
        }
        foreach ((array) $summary['pages'] as $i => $p) {
            $oid = (int) ($p['object_id'] ?? 0);
            if (isset($fresh[$oid])) {
                $summary['pages'][$i]['score'] = $fresh[$oid]['score'];
                $summary['pages'][$i]['critical'] = $fresh[$oid]['critical'];
                $summary['pages'][$i]['warnings'] = $fresh[$oid]['warnings'];
                $summary['pages'][$i]['findings'] = $fresh[$oid]['findings'];
            }
        }
        $crit = 0;
        $warn = 0;
        $scores = [];
        foreach ((array) $summary['pages'] as $p) {
            $crit += (int) ($p['critical'] ?? 0);
            $warn += (int) ($p['warnings'] ?? 0);
            $scores[] = (int) ($p['score'] ?? 0);
        }
        foreach ((array) ($summary['site'] ?? []) as $f) {
            if (($f['severity'] ?? '') === 'critical') {
                $crit++;
            } else {
                $warn++;
            }
        }
        $summary['critical'] = $crit;
        $summary['warnings'] = $warn;
        $summary['health'] = $scores === [] ? 0 : (int) round(array_sum($scores) / count($scores));
        update_option(\TQK_WPSEO\Scanner\Scanner::SUMMARY_OPTION, $summary);
    }

    private static function settings(): void {
        $input = is_array($_POST['settings'] ?? null) ? wp_unslash($_POST['settings']) : [];
        update_option(TQK_WPSEO_OPTION, Settings::sanitize($input));
        // Site-level save → refresh site snapshot only.
        self::refresh_site_snapshot();
        \TQK_WPSEO\Admin\Access::sync();
        wp_send_json_success(['message' => __('Settings saved.', 'tqk-wpseo')]);
    }

    /** Recompute site_issues into summary so site cards finish without full rescan. */
    private static function refresh_site_snapshot(): void {
        $summary = \TQK_WPSEO\Scanner\Scanner::summary();
        if ($summary === []) {
            return;
        }
        $site = \TQK_WPSEO\Scanner\Scanner::site_issues();
        $summary['site'] = array_values($site);
        $crit = 0;
        $warn = 0;
        foreach ((array) ($summary['pages'] ?? []) as $p) {
            $crit += (int) ($p['critical'] ?? 0);
            $warn += (int) ($p['warnings'] ?? 0);
        }
        foreach ($site as $f) {
            if (($f['severity'] ?? '') === 'critical') {
                $crit++;
            } else {
                $warn++;
            }
        }
        $summary['critical'] = $crit;
        $summary['warnings'] = $warn;
        update_option(\TQK_WPSEO\Scanner\Scanner::SUMMARY_OPTION, $summary);
    }

    private static function robots(): void {
        if (sanitize_key($_POST['op'] ?? '') === 'reset') {
            delete_option(\TQK_WPSEO\Sitemap\Sitemap::ROBOTS_OPTION);
            wp_send_json_success(['message' => __('robots.txt reset to default.', 'tqk-wpseo'), 'content' => \TQK_WPSEO\Sitemap\Sitemap::default_robots()]);
        }
        $content = sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''));
        update_option(\TQK_WPSEO\Sitemap\Sitemap::ROBOTS_OPTION, $content);
        self::refresh_site_snapshot();
        wp_send_json_success([
            'message' => __('robots.txt saved.', 'tqk-wpseo'),
            'warnings' => \TQK_WPSEO\Sitemap\Sitemap::validate_robots($content),
        ]);
    }

    private static function tools(): void {
        $op = sanitize_key($_POST['op'] ?? 'reset');
        if ($op === 'export') {
            wp_send_json_success([
                'message' => __('Settings exported.', 'tqk-wpseo'),
                'json' => wp_json_encode([
                    'plugin' => 'tqk-wpseo',
                    'version' => TQK_WPSEO_VERSION,
                    'settings' => \TQK_WPSEO\Core\Secrets::redact(array_merge(\TQK_WPSEO\Core\Activator::default_settings(), (array) get_option(TQK_WPSEO_OPTION, []))),
                    'modules' => \TQK_WPSEO\Core\Modules::states(),
                ]),
            ]);
        }
        if ($op === 'import') {
            $decoded = json_decode((string) ($_POST['payload'] ?? ''), true);
            if (!is_array($decoded)) {
                wp_send_json_error(['message' => __('Invalid settings file.', 'tqk-wpseo')]);
            }
            if (isset($decoded['settings']) && is_array($decoded['settings'])) {
                update_option(TQK_WPSEO_OPTION, Settings::sanitize($decoded['settings']));
            }
            if (isset($decoded['modules']) && is_array($decoded['modules'])) {
                foreach ($decoded['modules'] as $slug => $on) {
                    \TQK_WPSEO\Core\Modules::set(sanitize_key((string) $slug), !empty($on));
                }
            }
            \TQK_WPSEO\Admin\Access::sync();
            wp_send_json_success(['message' => __('Settings imported.', 'tqk-wpseo')]);
        }
        global $wpdb;
        foreach (\TQK_WPSEO\Core\Activator::table_names($wpdb->prefix) as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        delete_option(TQK_WPSEO_OPTION);
        delete_option(TQK_WPSEO_VERSION_OPTION);
        delete_option(\TQK_WPSEO\Scanner\Scanner::JOB_OPTION);
        delete_option(\TQK_WPSEO\Scanner\Scanner::SUMMARY_OPTION);
        delete_option(\TQK_WPSEO\Scanner\Scanner::HISTORY_OPTION);
        \TQK_WPSEO\Core\Activator::activate();
        wp_send_json_success(['message' => __('Plugin data reset and re-initialized.', 'tqk-wpseo')]);
    }

    private static function report(): void {
        wp_send_json_success(['html' => Pages::scan_report_html()]);
    }

    private static function links(): void {
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        if (sanitize_key($_POST['op'] ?? '') === 'rebuild') {
            $r = \TQK_WPSEO\Links\Engine::rebuild_batch($offset);
            wp_send_json_success($r + [
                'html' => $r['done'] ? Pages::orphans_html() : '',
                'message' => sprintf(__('Indexed %1$d / %2$d posts…', 'tqk-wpseo'), min($r['next'], $r['total']), $r['total']),
            ]);
        }
        [$checked, $broken, $next] = \TQK_WPSEO\Links\Engine::check_links($offset);
        $done = $checked < \TQK_WPSEO\Links\Engine::CHECK_BATCH;
        wp_send_json_success([
            'checked' => $checked,
            'broken' => $broken,
            'next' => $next,
            'done' => $done,
            'html' => $done ? Pages::broken_html() : '',
            'message' => sprintf(__('Checked %d link(s)…', 'tqk-wpseo'), $next),
        ]);
    }

    private static function sitemap_submit(): void {
        $r = \TQK_WPSEO\Sitemap\Sitemap::submit();
        wp_send_json_success([
            'google' => $r['google'],
            'bing' => $r['bing'],
            'message' => ($r['google']['ok'] ?? false) || ($r['bing']['ok'] ?? false)
                ? __('Sitemap submitted.', 'tqk-wpseo')
                : __('Not submitted — see the reasons below.', 'tqk-wpseo'),
        ]);
    }

    private static function mcp(): void {
        if (sanitize_key($_POST['op'] ?? '') === 'regen') {
            delete_option(\TQK_WPSEO\MCP\Server::TOKEN_OPTION);
            wp_send_json_success([
                'message' => __('Token regenerated.', 'tqk-wpseo'),
                'token' => \TQK_WPSEO\MCP\Server::token(),
            ]);
        }
        wp_send_json_error(['message' => __('Unknown action', 'tqk-wpseo')]);
    }

    private static function module(): void {
        $slug = sanitize_key($_POST['module'] ?? '');
        $on = !empty($_POST['on']);
        $states = \TQK_WPSEO\Core\Modules::set($slug, $on);
        if (!array_key_exists($slug, $states)) {
            wp_send_json_error(['message' => __('Unknown action', 'tqk-wpseo')]);
        }
        wp_send_json_success([
            'message' => $on ? __('Module enabled.', 'tqk-wpseo') : __('Module disabled.', 'tqk-wpseo'),
            'states' => $states,
        ]);
    }

    private static function gsc(): void {
        // Generic settings form posts nested settings[...] without op → treat as save.
        if (isset($_POST['settings']) && sanitize_key($_POST['op'] ?? '') !== 'sync') {
            $input = is_array($_POST['settings'] ?? null) ? wp_unslash($_POST['settings']) : [];
            $cur = (array) get_option(TQK_WPSEO_OPTION, []);
            update_option(TQK_WPSEO_OPTION, Settings::sanitize(array_merge($cur, $input)));
            wp_send_json_success(['message' => __('Google connection saved.', 'tqk-wpseo')]);
        }
        $op = sanitize_key($_POST['op'] ?? 'sync');
        if ($op === 'save') {
            $input = is_array($_POST['settings'] ?? null) ? wp_unslash($_POST['settings']) : [];
            $cur = (array) get_option(TQK_WPSEO_OPTION, []);
            update_option(TQK_WPSEO_OPTION, Settings::sanitize(array_merge($cur, $input)));
            wp_send_json_success(['message' => __('Google connection saved.', 'tqk-wpseo')]);
        }
        $r = \TQK_WPSEO\Google\SearchConsole::sync();
        if (!($r['ok'] ?? false)) {
            wp_send_json_error(['message' => $r['error'] ?? 'error']);
        }
        wp_send_json_success(['message' => $r['message'] ?? 'OK', 'html' => \TQK_WPSEO\Google\SearchConsole::table_html()]);
    }

    /**
     * Fills the metabox fields from post content. Read-only: it returns values
     * for the UI to drop into the form, never writes meta itself.
     */
    private static function schema_harvest(): void {
        $id = (int) ($_POST['post_id'] ?? 0);
        $type = sanitize_text_field(wp_unslash((string) ($_POST['schema_type'] ?? '')));
        if ($id <= 0 || !get_post($id) || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        $fields = \TQK_WPSEO\Schema\Builder::harvest($id, $type);
        if ($fields === []) {
            wp_send_json_error([
                'message' => __('Nothing found in the post content. Fill the fields in by hand.', 'tqk-wpseo'),
                'fields' => [],
            ]);
        }
        $keyed = [];
        foreach ($fields as $name => $value) {
            $keyed[(string) \TQK_WPSEO\Schema\Fields::key((string) $name)] = $value;
        }
        wp_send_json_success([
            'message' => __('Extracted from post content. Review before saving.', 'tqk-wpseo'),
            'fields' => $keyed,
        ]);
    }

    private static function gsc_history(): void {
        $q = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        if ($q === '') {
            wp_send_json_error(['message' => __('Pick a query first.', 'tqk-wpseo')]);
        }
        wp_send_json_success(['html' => \TQK_WPSEO\Google\SearchConsole::history_html($q)]);
    }
}
