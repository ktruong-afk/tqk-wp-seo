<?php
namespace TQK_WPSEO\Scanner;

defined('ABSPATH') || exit;

/**
 * S5 — Site scanner. Batched (50/job step) so large sites never time out.
 * Job in option; rows in tqk_wpseo_scan; summary + history for the dashboard.
 */
class Scanner {

    public const JOB_OPTION = 'tqk_wpseo_scan_job';
    public const SUMMARY_OPTION = 'tqk_wpseo_scan_summary';
    public const HISTORY_OPTION = 'tqk_wpseo_scan_history';
    public const BATCH = 50;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tqk_wpseo_scan';
    }

    public static function hooks(): void {
        add_action('wp_ajax_tqk_wpseo_scan', [self::class, 'ajax']);
    }

    /** @return array Job array. Modes: full (every page) or sample (1 per post type). Covers all public post types. */
    public static function start(string $mode = 'full'): array {
        $pts = function_exists('get_post_types') ? array_values((array) get_post_types(['public' => true])) : ['post', 'page'];
        $pts = array_values(array_unique(array_merge(['post', 'page'], $pts)));
        // Exclude attachments here (handled via media checks, not content scan).
        $pts = array_values(array_filter($pts, fn($t) => $t !== 'attachment'));
        $ids = get_posts([
            'post_type' => $pts, 'post_status' => ['publish'],
            'numberposts' => 5000, 'fields' => 'ids',
        ]);
        $ids = array_values(array_map('intval', (array) $ids));
        if ($mode === 'sample') {
            $ids = self::sample_ids($ids);
        }
        $job = [
            'id' => 'scan_' . gmdate('YmdHis'),
            'status' => 'running',
            'mode' => $mode === 'sample' ? 'sample' : 'full',
            'total' => count($ids),
            'done' => 0,
            'ids' => $ids,
            'started_at' => gmdate('c'),
        ];
        update_option(self::JOB_OPTION, $job);
        return $job;
    }

    /** One representative (latest) ID per post type + front page first. */
    public static function sample_ids(array $ids): array {
        $seen = [];
        $out = [];
        foreach ($ids as $id) {
            $pt = (string) get_post_type((int) $id);
            if (!isset($seen[$pt])) {
                $seen[$pt] = true;
                $out[] = (int) $id;
            }
        }
        $front = (int) get_option('page_on_front', 0);
        if ($front > 0 && !in_array($front, $out, true)) {
            array_unshift($out, $front);
        }
        return array_values($out);
    }

    /** @return array Job after processing one batch. */
    public static function step(): array {
        $job = get_option(self::JOB_OPTION, []);
        if (!is_array($job) || ($job['status'] ?? '') !== 'running') {
            return is_array($job) ? $job : ['status' => 'idle', 'total' => 0, 'done' => 0];
        }
        $batch = array_slice($job['ids'], (int) $job['done'], self::BATCH);
        foreach ($batch as $id) {
            self::scan_one((int) $id, (string) $job['id']);
        }
        $job['done'] = (int) $job['done'] + count($batch);
        if ($job['done'] >= (int) $job['total']) {
            $job['status'] = 'done';
            $job['finished_at'] = gmdate('Y-m-d H:i');
            self::finish($job);
        }
        update_option(self::JOB_OPTION, $job);
        return $job;
    }

    private static function scan_one(int $id, string $job_id): void {
        global $wpdb;
        $result = \TQK_WPSEO\Analyzer\Analyzer::analyze($id);
        $wpdb->insert(self::table(), [
            'job_id' => $job_id,
            'object_id' => $id,
            'object_type' => (string) get_post_type($id),
            'group_scores' => wp_json_encode($result['groups'] ?? []),
            'issues' => wp_json_encode($result['findings'] ?? []),
            'score' => (int) ($result['score'] ?? 0),
            'evaluated' => (int) ($result['evaluated'] ?? 0),
        ]);
    }

    private static function finish(array $job): void {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE job_id = %s',
            $job['id']
        ));
        $group_sums = [];
        $group_n = 0;
        $critical = 0;
        $warnings = 0;
        $evaluated_total = 0;
        $scores = [];
        $pages = [];
        foreach ($rows as $row) {
            $groups = json_decode((string) ($row->group_scores ?? '{}'), true) ?: [];
            foreach ($groups as $g => $s) {
                $group_sums[$g] = ($group_sums[$g] ?? 0) + (int) $s;
            }
            if ($groups !== []) {
                $group_n++;
            }
            $scores[] = (int) ($row->score ?? 0);
            $evaluated_total += (int) ($row->evaluated ?? 0);
            $issues = json_decode((string) ($row->issues ?? '[]'), true) ?: [];
            $crit = 0;
            $warn = 0;
            foreach ($issues as $f) {
                if (($f['severity'] ?? '') === 'critical') {
                    $critical++;
                    $crit++;
                } else {
                    $warnings++;
                    $warn++;
                }
            }
            $oid = (int) $row->object_id;
            $pages[] = [
                'object_id' => $oid,
                'title' => get_the_title($oid),
                'type' => (string) get_post_type($oid),
                'score' => (int) ($row->score ?? 0),
                'critical' => $crit,
                'warnings' => $warn,
                'url' => admin_url('post.php?post=' . $oid . '&action=edit'),
                'findings' => array_slice(array_values($issues), 0, 8),
            ];
        }
        usort($pages, fn($a, $b) => [$b['critical'], $b['warnings'], $a['score']] <=> [$a['critical'], $a['warnings'], $b['score']]);
        $groups = [];
        foreach ($group_sums as $g => $sum) {
            $groups[$g] = $group_n > 0 ? (int) round($sum / $group_n) : 0;
        }
        // Site-level snapshot (one place, persists until next scan or site save).
        $site = self::site_issues();
        foreach ($site as $f) {
            if (($f['severity'] ?? '') === 'critical') {
                $critical++;
            } else {
                $warnings++;
            }
        }
        $summary = [
            'job_id' => $job['id'],
            'mode' => $job['mode'] ?? 'full',
            'finished_at' => $job['finished_at'] ?? gmdate('c'),
            'health' => $scores === [] ? 0 : (int) round(array_sum($scores) / count($scores)),
            'critical' => $critical,
            'warnings' => $warnings,
            'passed' => max(0, $evaluated_total - $critical - $warnings),
            'groups' => $groups,
            'site' => array_values($site),
            'pages' => array_slice($pages, 0, 30),
        ];
        update_option(self::SUMMARY_OPTION, $summary);
        $history = get_option(self::HISTORY_OPTION, []);
        if (!is_array($history)) {
            $history = [];
        }
        array_unshift($history, [
            'job_id' => $job['id'],
            'finished_at' => $summary['finished_at'],
            'health' => $summary['health'],
            'critical' => $critical,
            'warnings' => $warnings,
        ]);
        update_option(self::HISTORY_OPTION, array_slice($history, 0, 5));
    }

    public static function summary(): array {
        $s = get_option(self::SUMMARY_OPTION, []);
        return is_array($s) ? $s : [];
    }

    /**
     * Minimalist site-level checks (schema/sitemap/robots/home).
     * Saved once per scan into summary['site'] so Dashboard fixes in one place.
     * @return array List of [id, group, severity, message, fix].
     */
    public static function site_issues(): array {
        $out = [];
        $settings = array_merge(\TQK_WPSEO\Core\Activator::default_settings(), (array) get_option(TQK_WPSEO_OPTION, []));
        if (empty($settings['sitemap_enabled'])) {
            $out[] = ['id' => 'site_sitemap_off', 'group' => 'technical', 'severity' => 'critical', 'message' => __('Sitemap is disabled.', 'tqk-wpseo'), 'fix' => 'sitemap'];
        } elseif (self::maps_empty()) {
            $out[] = ['id' => 'site_sitemap_empty', 'group' => 'technical', 'severity' => 'warning', 'message' => __('Sitemap has no URLs.', 'tqk-wpseo'), 'fix' => 'sitemap'];
        }
        $robots = is_string(get_option(\TQK_WPSEO\Sitemap\Sitemap::ROBOTS_OPTION, '')) ? (string) get_option(\TQK_WPSEO\Sitemap\Sitemap::ROBOTS_OPTION, '') : '';
        $robots = trim($robots) !== '' ? $robots : \TQK_WPSEO\Sitemap\Sitemap::default_robots();
        foreach (\TQK_WPSEO\Sitemap\Sitemap::validate_robots($robots) as $w) {
            $out[] = ['id' => 'site_robots', 'group' => 'technical', 'severity' => 'critical', 'message' => $w, 'fix' => 'robots'];
        }
        $map = is_array($settings['schema_map'] ?? null) ? $settings['schema_map'] : [];
        if ($map === [] || count(array_filter($map)) === 0) {
            $out[] = ['id' => 'site_schema_map', 'group' => 'schema', 'severity' => 'warning', 'message' => __('Schema templates not configured.', 'tqk-wpseo'), 'fix' => 'schema'];
        }
        if (trim((string) get_bloginfo('name')) === '' || trim((string) get_bloginfo('description')) === '') {
            $out[] = ['id' => 'site_home_meta', 'group' => 'content', 'severity' => 'warning', 'message' => __('Homepage title or tagline is empty.', 'tqk-wpseo'), 'fix' => 'home'];
        }
        return $out;
    }

    private static function maps_empty(): bool {
        $total = 0;
        foreach (\TQK_WPSEO\Sitemap\Sitemap::maps() as $type => $label) {
            $total += count(\TQK_WPSEO\Sitemap\Sitemap::items($type));
        }
        return $total === 0;
    }

    public static function history(): array {
        $h = get_option(self::HISTORY_OPTION, []);
        return is_array($h) ? $h : [];
    }

    public static function ajax(): void {
        check_ajax_referer('tqk-wpseo-scan');
        if (!\TQK_WPSEO\Admin\Access::can()) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        $op = sanitize_key($_POST['op'] ?? 'status');
        if (($op === 'start' || $op === 'step') && !\TQK_WPSEO\Core\Modules::is_active('analyzer')) {
            wp_send_json_error(['message' => __('Enable the Analyzer module first.', 'tqk-wpseo')]);
        }
        if ($op === 'start') {
            wp_send_json_success(self::start(sanitize_key($_POST['mode'] ?? 'full')));
        }
        if ($op === 'step') {
            wp_send_json_success(self::step());
        }
        if ($op === 'audit') {
            $result = \TQK_WPSEO\Analyzer\PageAudit::audit_url((string) ($_POST['url'] ?? ''));
            if (!($result['ok'] ?? false)) {
                wp_send_json_error(['message' => $result['error'] ?? 'error']);
            }
            wp_send_json_success(['html' => \TQK_WPSEO\Analyzer\PageAudit::report_html($result)]);
        }
        if ($op === 'set-alt') {
            $att_id = (int) ($_POST['attachment_id'] ?? 0);
            $alt = sanitize_text_field(wp_unslash($_POST['alt'] ?? ''));
            if ($att_id <= 0 || get_post_type($att_id) !== 'attachment') {
                wp_send_json_error(['message' => __('Post not found.', 'tqk-wpseo')]);
            }
            $alt === '' ? delete_post_meta($att_id, '_wp_attachment_image_alt') : update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
            wp_send_json_success(['message' => __('ALT saved.', 'tqk-wpseo')]);
        }
        wp_send_json_success([
            'job' => get_option(self::JOB_OPTION, []),
            'summary' => self::summary(),
        ]);
    }
}
