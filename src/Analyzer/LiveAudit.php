<?php
namespace TQK_WPSEO\Analyzer;

defined('ABSPATH') || exit;

/**
 * Live Audit: the browser opens every queued page in a real iframe, checks
 * head tags + schema + responsive at 4 viewports, snapshots the full page,
 * then moves on by itself. This class owns the queue, the per-page tracking
 * and the stored results, so a run of many hours survives a closed tab, a
 * crash or a lost connection, and resumes exactly where it stopped.
 *
 * Two modes, because they answer different questions:
 *   - sample: every page (hand-built, each can break alone) + ONE post per
 *     other post type (shared template: one post shows whether the tags,
 *     fields and schema of that template are sound).
 *   - all: every post of ONE chosen post type, in ID order, until done. For
 *     sites that wrote for years without an SEO plugin, where each post has
 *     to be checked one by one.
 *
 * The full list is taken once at start. Each page then carries its own
 * status (pending / done / failed), so nothing is lost and nothing is redone.
 */
class LiveAudit {

    public const RUN = 'tqk_wpseo_live_audit';
    public const HOME = 'tqk_wpseo_live_audit_home';
    public const META = '_tqk_wpseo_live_audit';
    public const BATCH = 25;
    public const SHOT_MAX = 6 * 1024 * 1024;
    public const SHOT_KEYS = ['desktop', 'mobile'];

    /* One status char per queued page, index-aligned with 'ids': a 10k-post
     * run is a 10 KB string, and any crash leaves an exact record. */
    public const PENDING = '.';
    public const DONE = 'd';
    public const FAILED = 'f';

    public static function hooks(): void {
        // Pages rendered for the audit iframe: no admin bar, no audit FAB,
        // otherwise both would sit in every snapshot and shift the layout.
        if (self::in_frame()) {
            add_filter('show_admin_bar', '__return_false');
        }
    }

    /** True when the current front-end request is an audit iframe load. */
    public static function in_frame(): bool {
        return !is_admin() && isset($_GET['tqk_rs']) && \TQK_WPSEO\Admin\Access::can();
    }

    /** @return array<string,array{label:string,count:int}> Public post types with published counts. */
    public static function types(): array {
        $out = [];
        foreach ((array) get_post_types(['public' => true]) as $pt) {
            $pt = (string) $pt;
            if ($pt === 'attachment') {
                continue;
            }
            $obj = get_post_type_object($pt);
            // Builders' internal types (blocks, templates) have no editing
            // screen: not content people write, nothing to audit.
            if ($obj && isset($obj->show_ui) && !$obj->show_ui) {
                continue;
            }
            $label = (string) ($obj->labels->singular_name ?? $pt);
            $count = function_exists('wp_count_posts') ? (int) (wp_count_posts($pt)->publish ?? 0) : count(self::ids_of($pt));
            if ($count > 0) {
                $out[$pt] = ['label' => $label, 'plural' => (string) ($obj->labels->name ?? $label), 'count' => $count, 'taxonomies' => self::taxonomies_of($pt)];
            }
        }
        // Pages first, then the other types in their registration order.
        if (isset($out['page'])) {
            $out = ['page' => $out['page']] + $out;
        }
        return $out;
    }

    /** @return array<string,array{label:string,count:int}> Public taxonomies of a post type, with term counts. */
    public static function taxonomies_of(string $pt): array {
        $out = [];
        if (!function_exists('get_object_taxonomies')) {
            return $out;
        }
        foreach ((array) get_object_taxonomies($pt, 'objects') as $tax => $obj) {
            if (empty($obj->public) || $tax === 'post_format' || $tax === 'product_shipping_class' || $tax === 'product_visibility' || $tax === 'product_type') {
                continue;
            }
            // Terms with posts only: an empty term has no archive page worth auditing.
            $n = function_exists('wp_count_terms') ? wp_count_terms(['taxonomy' => $tax, 'hide_empty' => true]) : 0;
            $out[(string) $tax] = ['label' => (string) ($obj->labels->name ?? $tax), 'count' => is_numeric($n) ? (int) $n : 0];
        }
        return $out;
    }

    /**
     * Queue from the scope table: pages (all, free) + per post type either
     * nothing, one sample post (is the template sound?) or every post (Pro).
     *
     * @param array<string,string> $choices post type => none | one | all
     * @return int[]
     */
    public static function build_plan(array $choices, bool $pages = true): array {
        $types = self::types();
        $ids = [];
        if ($pages && get_option('show_on_front') !== 'page') {
            $ids[] = 0;
        }
        if ($pages && isset($types['page'])) {
            $ids = array_merge($ids, self::ids_of('page'));
        }
        foreach ($types as $pt => $t) {
            if ($pt === 'page') {
                continue;
            }
            $c = (string) ($choices[$pt] ?? 'one');
            if ($c === 'one') {
                $ids = array_merge($ids, self::ids_of($pt, 1));
            } elseif ($c === 'all') {
                $ids = array_merge($ids, self::ids_of($pt));
            }
        }
        // Taxonomy archives, queued as -term_id: one sample (the term with the
        // most posts: the archive people land on) or every non-empty term.
        foreach (self::taxonomies() as $tax => $t) {
            $c = (string) ($choices['tax:' . $tax] ?? 'one');
            if ($c === 'none') {
                continue;
            }
            $terms = get_terms([
                'taxonomy' => $tax,
                'hide_empty' => true,
                'orderby' => 'count',
                'order' => 'DESC',
                'number' => $c === 'one' ? 1 : 0,
                'fields' => 'ids',
            ]);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $tid = is_object($term) ? (int) ($term->term_id ?? 0) : (int) $term;
                if ($tid > 0) {
                    $ids[] = -$tid;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Public taxonomies of the audited post types, once each, with the post
     * types they belong to and their non-empty term count.
     *
     * @return array<string,array{label:string,count:int,for:string}>
     */
    public static function taxonomies(): array {
        $out = [];
        foreach (self::types() as $pt => $t) {
            foreach ((array) ($t['taxonomies'] ?? []) as $tax => $x) {
                if (!isset($out[$tax])) {
                    $out[$tax] = ['label' => (string) $x['label'], 'count' => (int) $x['count'], 'for' => (string) $t['plural']];
                } elseif (!str_contains($out[$tax]['for'], (string) $t['plural'])) {
                    $out[$tax]['for'] .= ', ' . $t['plural'];
                }
            }
        }
        return array_filter($out, static fn($x) => $x['count'] > 0);
    }

    /** Post types / taxonomies asked for "all" without Pro. @return string[] Their names. */
    public static function needs_pro(array $choices): array {
        if (\TQK_WPSEO\Core\Pro::active()) {
            return [];
        }
        $types = self::types();
        $taxes = self::taxonomies();
        $out = [];
        foreach ($choices as $key => $c) {
            if ($c !== 'all' || $key === 'page') {
                continue;
            }
            if (str_starts_with((string) $key, 'tax:') && isset($taxes[substr((string) $key, 4)])) {
                $out[] = $taxes[substr((string) $key, 4)]['label'];
            } elseif (isset($types[$key])) {
                $out[] = (string) $types[$key]['plural'];
            }
        }
        return $out;
    }

    /** @return int[] Published IDs of one type, oldest first (stable order). */
    private static function ids_of(string $type, int $limit = -1): array {
        return array_map('intval', (array) get_posts([
            'post_type' => $type,
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby' => 'ID',
            'order' => $limit === 1 ? 'DESC' : 'ASC',
            'fields' => 'ids',
            'suppress_filters' => true,
        ]));
    }

    /**
     * Queue as a flat ID list. 0 stands for the blog homepage when the front
     * page is not a static page (a static one is already in the page list).
     *
     * @return int[]
     */
    public static function build_ids(string $mode, string $type = ''): array {
        $types = self::types();
        if ($mode === 'all') {
            return isset($types[$type]) ? self::ids_of($type) : [];
        }
        $ids = [];
        if (get_option('show_on_front') !== 'page') {
            $ids[] = 0;
        }
        if (isset($types['page'])) {
            $ids = array_merge($ids, self::ids_of('page'));
        }
        foreach (array_keys($types) as $pt) {
            if ($pt !== 'page') {
                $ids = array_merge($ids, self::ids_of($pt, 1));
            }
        }
        return array_values(array_unique($ids));
    }

    public static function run(): array {
        $r = get_option(self::RUN, []);
        return is_array($r) ? $r : [];
    }

    private static function save_run(array $run): void {
        $run['updated'] = time();
        update_option(self::RUN, $run, false);
    }

    public static function start(string $mode, string $type, bool $snap, array $choices = [], bool $pages = true): array {
        $mode = in_array($mode, ['all', 'plan'], true) ? $mode : 'sample';
        $old = self::run();
        if (!empty($old['id'])) {
            self::purge_dir((string) $old['id']);
        }
        $ids = $mode === 'plan' ? self::build_plan($choices, $pages) : self::build_ids($mode, $type);
        $run = [
            'id' => strtolower(wp_generate_password(16, false, false)),
            'mode' => $mode,
            'type' => $mode === 'all' ? $type : '',
            'snap' => $snap,
            'ids' => $ids,
            'st' => str_repeat(self::PENDING, count($ids)),
            'status' => $ids === [] ? 'done' : 'running',
            'started' => time(),
        ];
        self::save_run($run);
        return $run;
    }

    /** @return array Public view of the run (no ID list). */
    public static function state(array $run): array {
        $st = (string) ($run['st'] ?? '');
        return [
            'id' => (string) ($run['id'] ?? ''),
            'mode' => (string) ($run['mode'] ?? ''),
            'type' => (string) ($run['type'] ?? ''),
            'snap' => !empty($run['snap']),
            'total' => strlen($st),
            'done' => substr_count($st, self::DONE),
            'failed' => substr_count($st, self::FAILED),
            'pending' => substr_count($st, self::PENDING),
            'status' => (string) ($run['status'] ?? ''),
            'started' => (int) ($run['started'] ?? 0),
            'updated' => (int) ($run['updated'] ?? 0),
        ];
    }

    /** Status char for one queued index ('' when out of range). */
    public static function status_of(array $run, int $index): string {
        $st = (string) ($run['st'] ?? '');
        return $index >= 0 && $index < strlen($st) ? $st[$index] : '';
    }

    private static function mark(array $run, int $index, string $status): array {
        $st = (string) $run['st'];
        $st[$index] = $status;
        $run['st'] = $st;
        if (strpos($st, self::PENDING) === false) {
            $run['status'] = 'done';
        }
        return $run;
    }

    /** Failed pages go back to the queue; done pages are never redone. */
    public static function retry_failed(array $run): array {
        $run['st'] = str_replace(self::FAILED, self::PENDING, (string) ($run['st'] ?? ''));
        if (strpos($run['st'], self::PENDING) !== false) {
            $run['status'] = 'running';
        }
        return $run;
    }

    /** @return array{id:int,url:string,title:string,type:string} */
    public static function item(int $id): array {
        if ($id === 0) {
            return ['id' => 0, 'url' => home_url('/'), 'title' => __('Homepage', 'tqk-wp-seo'), 'type' => 'home'];
        }
        if ($id < 0) {
            // Taxonomy archive (category, tag, product category…): -term_id.
            $t = get_term(-$id);
            $link = $t && !is_wp_error($t) ? get_term_link($t) : '';
            return [
                'id' => $id,
                'url' => is_string($link) ? $link : '',
                'title' => $t && !is_wp_error($t) ? (string) $t->name : '',
                'type' => $t && !is_wp_error($t) ? 'term: ' . (string) $t->taxonomy : 'term',
            ];
        }
        $p = get_post($id);
        return [
            'id' => $id,
            'url' => $p ? (string) get_permalink($id) : '',
            'title' => (string) ($p->post_title ?? ''),
            'type' => (string) ($p->post_type ?? ''),
        ];
    }

    /**
     * Next pending pages. A queued post deleted or unpublished since the list
     * was taken is marked failed right here, so it shows in the tracking
     * instead of silently vanishing.
     *
     * @return array{0:array,1:array} [items, run (possibly updated)]
     */
    public static function batch(array $run): array {
        $ids = (array) ($run['ids'] ?? []);
        $out = [];
        $changed = false;
        $i = strpos((string) ($run['st'] ?? ''), self::PENDING);
        while ($i !== false && count($out) < self::BATCH) {
            $it = self::item((int) $ids[$i]);
            if ($it['url'] === '' || ($it['id'] > 0 && get_post_status($it['id']) !== 'publish')) {
                $run = self::mark($run, $i, self::FAILED);
                $changed = true;
            } else {
                $it['index'] = $i;
                $out[] = $it;
            }
            $i = strpos((string) $run['st'], self::PENDING, $i + 1);
        }
        if ($changed) {
            self::save_run($run);
        }
        return [$out, $run];
    }

    /**
     * Whitelists the browser report: only known keys, bounded sizes, typed.
     * The client never shapes what lands in the database.
     */
    public static function clean_item(array $d): array {
        $str = static fn($v, int $max) => is_scalar($v) ? mb_substr(sanitize_text_field((string) $v), 0, $max) : '';
        $int = static fn($v, int $max = 100000) => max(0, min($max, (int) $v));

        $seo = (array) ($d['seo'] ?? []);
        $findings = [];
        foreach (array_slice((array) ($seo['findings'] ?? []), 0, 40) as $f) {
            $f = (array) $f;
            $sev = in_array($f['sev'] ?? '', ['critical', 'warning', 'pass'], true) ? $f['sev'] : 'warning';
            $findings[] = ['sev' => $sev, 'msg' => $str($f['msg'] ?? '', 200)];
        }
        $schema = [];
        foreach (array_slice((array) ($seo['schema'] ?? []), 0, 20) as $t) {
            $schema[] = $str($t, 60);
        }

        $vps = [];
        foreach (array_slice((array) ($d['vps'] ?? []), 0, 4) as $v) {
            $v = (array) $v;
            $vps[] = [
                'label' => $str($v['label'] ?? '', 30),
                'score' => $int($v['score'] ?? 0, 100),
                'error' => $str($v['error'] ?? '', 80),
                'overflow' => $int($v['overflow'] ?? 0),
                'tiny' => $int($v['tiny'] ?? 0),
                'tap' => $int($v['tap'] ?? 0),
                'dims' => $int($v['dims'] ?? 0),
                'over' => $int($v['over'] ?? 0),
            ];
        }

        $groups = [];
        foreach (array_slice((array) ($d['groups'] ?? []), 0, 8) as $g) {
            $g = (array) $g;
            $groups[] = [
                'root' => $str($g['root'] ?? '', 120),
                'reason' => $str($g['reason'] ?? '', 200),
                'count' => $int($g['count'] ?? 0),
                'vps' => $str($g['vps'] ?? '', 100),
            ];
        }

        $addr = [];
        foreach (array_slice((array) ($seo['addr'] ?? []), 0, 3) as $a) {
            $a = $str($a, 220);
            if ($a !== '') {
                $addr[] = $a;
            }
        }

        return [
            'seo' => ['score' => $int($seo['score'] ?? 0, 100), 'findings' => $findings, 'schema' => $schema, 'addr' => $addr],
            'vps' => $vps,
            'groups' => $groups,
            'images' => ImageFix::clean_images((array) ($d['images'] ?? [])),
            'error' => $str($d['error'] ?? '', 200),
        ];
    }

    /**
     * Decodes a snapshot data URL. JPEG only, size-capped, and the bytes must
     * really be a JPEG: the file type on disk is decided here, not by the client.
     */
    public static function decode_shot(string $data): ?string {
        $prefix = 'data:image/jpeg;base64,';
        if (strncmp($data, $prefix, strlen($prefix)) !== 0) {
            return null;
        }
        $b64 = substr($data, strlen($prefix));
        if (strlen($b64) > intdiv(self::SHOT_MAX * 4, 3) + 4) {
            return null;
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || strlen($bin) < 4 || strncmp($bin, "\xFF\xD8\xFF", 3) !== 0) {
            return null;
        }
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($bin);
            if (!is_array($info) || ($info['mime'] ?? '') !== 'image/jpeg') {
                return null;
            }
        }
        return $bin;
    }

    /**
     * Removes the files of an old run. Files now live in the private vault;
     * the old public folder (uploads/tqk-wpseo-audit) is cleaned too.
     */
    private static function purge_dir(string $run_id): void {
        \TQK_WPSEO\Core\Vault::delete_folder($run_id);
        if (!function_exists('wp_upload_dir') || !preg_match('/^[a-z0-9]{8,32}$/', $run_id)) {
            return;
        }
        $up = wp_upload_dir();
        $path = trailingslashit((string) $up['basedir']) . 'tqk-wpseo-audit/' . $run_id;
        if (!is_dir($path)) {
            return;
        }
        foreach (array_merge((array) glob($path . '/*.jpg'), (array) glob($path . '/*.json')) as $f) {
            @unlink((string) $f);
        }
        @unlink($path . '/index.php');
        @rmdir($path);
    }

    /** File name stem of a queued page: post ID, or 'home' for the blog homepage. */
    private static function stem(int $id): string {
        return $id === 0 ? 'home' : ($id < 0 ? 't' . (-$id) : (string) $id);
    }

    /** Writes one file into the run's private folder. @return string Vault key, '' on failure. */
    private static function write_file(string $run_id, string $name, string $bytes): string {
        if (!preg_match('/^[a-z0-9]{8,32}$/', $run_id) || !preg_match('/^[a-z0-9-]+\.(jpg|json)$/', $name)) {
            return '';
        }
        return \TQK_WPSEO\Core\Vault::write($run_id, $name, $bytes);
    }

    /** Link to a stored file (vault key or an older public URL), '' when it is gone. */
    public static function file_url(string $ref): string {
        if ($ref === '' || \TQK_WPSEO\Core\Vault::local($ref) === '') {
            return '';
        }
        return \TQK_WPSEO\Core\Vault::url($ref);
    }

    private static function write_shot(string $run_id, int $id, string $key, string $bin): string {
        return in_array($key, self::SHOT_KEYS, true) ? self::write_file($run_id, self::stem($id) . '-' . $key . '.jpg', $bin) : '';
    }

    /** Folder of the snapshots taken by the Scan button in the post editor. */
    public const EDITOR_RUN = 'editorscan';
    /** The editor's Scan pictures all 4 screens (for people and AI agents). */
    public const EDITOR_SHOT_KEYS = ['desktop', 'laptop', 'tablet', 'mobile'];

    /**
     * Snapshot from the editor's Scan: same checks as Live Audit, one file per
     * post and screen, replaced on every scan. @return string Vault key, '' on failure.
     */
    public static function save_editor_shot(int $id, string $key, string $data): string {
        $bin = self::decode_shot($data);
        if ($bin === null || $id <= 0 || !in_array($key, self::EDITOR_SHOT_KEYS, true)) {
            return '';
        }
        return self::write_file(self::EDITOR_RUN, $id . '-' . $key . '.jpg', $bin);
    }

    /** File path of an editor snapshot, '' when there is none. */
    public static function editor_shot_path(int $id, string $key): string {
        if (!in_array($key, self::EDITOR_SHOT_KEYS, true)) {
            return '';
        }
        return \TQK_WPSEO\Core\Vault::local(self::EDITOR_RUN . '/' . $id . '-' . $key . '.jpg');
    }

    /**
     * Stored fields checked against the schema builder's own rules. The DOM
     * shows what was emitted; this shows what the editor left empty.
     *
     * @return string[] Messages.
     */
    public static function field_errors(int $id): array {
        if ($id <= 0) {
            return [];
        }
        $post = get_post($id);
        if (!$post) {
            return [];
        }
        $out = [];
        foreach (\TQK_WPSEO\Schema\Validator::errors($id, \TQK_WPSEO\Schema\Schema::type_for($post)) as $e) {
            $out[] = (string) ($e['message'] ?? '');
        }
        if ((string) get_post_meta($id, '_tqk_wpseo_focus_keyword', true) === '') {
            $out[] = __('No focus keyword set.', 'tqk-wp-seo');
        }
        return $out;
    }

    /**
     * Stores one audited page and marks it done, or failed when the browser
     * could not load it. Idempotent: a repeated request for a page already
     * done is acknowledged, not stored twice.
     */
    public static function save(array $run, int $index, array $data, array $shots, bool $failed = false): array {
        $status = self::status_of($run, $index);
        if ($status === '') {
            return ['run' => $run, 'record' => null, 'error' => true];
        }
        if ($status === self::DONE) {
            return ['run' => $run, 'record' => null];
        }
        $id = (int) $run['ids'][$index];
        $rec = self::clean_item($data);
        if ($failed && $rec['error'] === '') {
            $rec['error'] = __('Page did not load.', 'tqk-wp-seo');
        }
        if (!$failed) {
            $rec['error'] = '';
            $rec['fields'] = self::field_errors($id);
            // Old (pre-July 2025) province or district in the page's address.
            foreach ((array) ($rec['seo']['addr'] ?? []) as $a) {
                foreach (\TQK_WPSEO\Local\Address::check((string) $a)['messages'] as $m) {
                    $rec['fields'][] = $m . ' — "' . mb_substr((string) $a, 0, 90) . '"';
                }
            }
            $rec['fields'] = array_values(array_unique($rec['fields']));
        }
        $rec['run'] = (string) $run['id'];
        $rec['at'] = time();
        $rec['shots'] = [];
        if (!$failed && !empty($run['snap'])) {
            foreach (self::SHOT_KEYS as $key) {
                $bin = self::decode_shot((string) ($shots[$key] ?? ''));
                $u = $bin === null ? '' : self::write_shot((string) $run['id'], $id, $key, $bin);
                if ($u !== '') {
                    $rec['shots'][$key] = $u;
                }
            }
        }
        $item = self::item($id);
        // Each page is cached as JSON: one file per page in the run folder
        // (readable/exportable without WordPress), and the same JSON in meta
        // so the report sorts from the database without touching the disk.
        $rec['json'] = self::write_file(
            (string) $run['id'],
            self::stem($id) . '.json',
            (string) wp_json_encode(['item' => $item, 'record' => $rec], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $json = (string) wp_json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($id === 0) {
            update_option(self::HOME, $json, false);
        } elseif ($id < 0) {
            update_term_meta(-$id, self::META, wp_slash($json)); // taxonomy archive
        } else {
            update_post_meta($id, self::META, wp_slash($json));
        }
        $run = self::mark($run, $index, $failed ? self::FAILED : self::DONE);
        self::save_run($run);
        return ['run' => $run, 'record' => $rec, 'item' => $item];
    }

    /**
     * Stores the page report shared with the editor and MCP: the browser's
     * measurements + plan, the server checklist, image rows, snapshots.
     */
    public static function store_report(int $id, array $client, array $rec): void {
        $checklist = [];
        foreach ((array) ((Analyzer::stored($id) ?? [])['findings'] ?? []) as $f) {
            if (in_array($f['severity'] ?? '', ['critical', 'warning'], true)) {
                $checklist[] = ['severity' => (string) $f['severity'], 'group' => (string) ($f['group'] ?? ''), 'message' => (string) ($f['message'] ?? '')];
            }
        }
        $client['source'] = 'site audit';
        $client['score'] = (int) ((Analyzer::stored($id) ?? [])['score'] ?? 0);
        $client['url'] = (string) get_permalink($id);
        $client['checklist'] = $checklist;
        $client['fields'] = (array) ($rec['fields'] ?? []);
        $client['images_to_optimize'] = count(ImageFix::rows($id));
        // The same image lines in the page's plan (editor + MCP read it).
        $lines = self::image_lines($id);
        if ($lines !== []) {
            $client['plan'] = (array) ($client['plan'] ?? []);
            $client['plan'][] = [
                'prio' => 2,
                'area' => 'images',
                'code' => 'images',
                'title' => sprintf(__('%d image(s) should be resized for each screen', 'tqk-wp-seo'), $client['images_to_optimize']),
                'how' => '',
                'css' => '',
                'list' => $lines,
                'fix' => ['type' => 'images', 'key' => \TQK_WPSEO\Core\Pro::active() ? '' : 'pro'],
            ];
            $client['image_details'] = $lines;
        }
        $client['shots'] = (array) ($rec['shots'] ?? []);
        // A security check done earlier for this page is kept.
        $old = \TQK_WPSEO\MCP\Reports::get($id);
        if ($old !== null && isset($old['security'])) {
            $client['security'] = $old['security'];
        }
        \TQK_WPSEO\MCP\Reports::save($id, $client);
    }

    /**
     * One line per image to fix on a page, worst screen first:
     * "hero.jpg — 2400px file in a 343px box on Mobile: resize (~690px)".
     *
     * @return string[] At most 3 lines + a "more" line.
     */
    public static function image_lines(int $id): array {
        $out = [];
        foreach (self::image_rows($id) as $r) {
            $out[] = $r['resize'] > 0
                ? sprintf(
                    __('%1$s — original %2$s, box %3$s on %4$s: resize to about %5$dpx', 'tqk-wp-seo'),
                    $r['name'], $r['orig'], $r['box'], $r['screen'], $r['resize']
                )
                : $r['name'] . ' — ' . implode(' ', $r['notes']);
        }
        $more = count($out) - 3;
        $out = array_slice($out, 0, 3);
        if ($more > 0) {
            $out[] = sprintf(__('+%d more image(s): see "Image optimizer" below.', 'tqk-wp-seo'), $more);
        }
        return $out;
    }

    /**
     * Images of a page that need work, spelled out: original size, the box it
     * is shown in (worst screen), the width to resize to (2x the box, sharp on
     * retina), and the other problems (no srcset, not WebP, stretched…).
     *
     * @return list<array{name:string,orig:string,box:string,screen:string,resize:int,notes:string[]}>
     */
    public static function image_rows(int $id): array {
        $out = [];
        if ($id < 0) {
            return $out; // archives: images belong to the posts listed there
        }
        foreach (ImageFix::rows($id) as $row) {
            $im = (array) $row['im'];
            $orig = $row['orig'];
            $worst = null;
            $widest = null;
            foreach ((array) ($im['vp'] ?? []) as $k => $v) {
                $w = (int) ($v['w'] ?? 0);
                if ($w <= 0) {
                    continue;
                }
                $nw = (int) ($v['nw'] ?? 0) ?: (int) ($orig['w'] ?? 0);
                if ($widest === null || $w > $widest[1]) {
                    $widest = [$k, $w, (int) ($v['h'] ?? 0), $nw, (int) ($v['nh'] ?? 0)];
                }
                if ($nw > $w * 2.5 && ($worst === null || $nw / $w > $worst[5])) {
                    $worst = [$k, $w, (int) ($v['h'] ?? 0), $nw, (int) ($v['nh'] ?? 0), $nw / $w];
                }
            }
            $at = $worst ?? $widest ?? ['', 0, 0, 0, 0];
            $notes = [];
            foreach ((array) $row['a']['issues'] as $issue) {
                // Per-screen size lines are summed up by the numbers above.
                if (!preg_match('/^(Desktop|Laptop|Tablet|Mobile): /', (string) $issue)) {
                    $notes[] = (string) $issue;
                }
            }
            $out[] = [
                'name' => basename((string) strtok((string) $im['src'], '?')),
                'orig' => $orig !== null ? $orig['w'] . '×' . $orig['h'] : ($at[3] > 0 ? $at[3] . '×' . $at[4] : '?'),
                'box' => $at[1] . '×' . $at[2],
                'screen' => ucfirst((string) $at[0]),
                'resize' => $worst !== null ? (int) $worst[1] * 2 : 0,
                'notes' => array_slice($notes, 0, 2),
            ];
        }
        return $out;
    }

    /** Images block of a report row: the list, then the one-click (Pro) button. */
    private static function images_block(int $id): string {
        $rows = self::image_rows($id);
        if ($rows === []) {
            return '';
        }
        $li = '';
        foreach (array_slice($rows, 0, 4) as $r) {
            $meta = esc_html(sprintf(__('Original: %s', 'tqk-wp-seo'), $r['orig'])) . ' · '
                . esc_html(sprintf(__('Box: %1$s (%2$s)', 'tqk-wp-seo'), $r['box'], $r['screen']))
                . ($r['resize'] > 0 ? ' · <strong>' . esc_html(sprintf(__('Resize to: %dpx', 'tqk-wp-seo'), $r['resize'])) . '</strong>' : '');
            $li .= '<li><span class="tqk-la-img-name">' . esc_html($r['name']) . '</span>'
                . '<span class="tqk-la-img-meta">' . $meta . '</span>'
                . ($r['notes'] !== [] ? '<span class="tqk-la-img-note">' . esc_html(implode(' · ', $r['notes'])) . '</span>' : '') . '</li>';
        }
        if (count($rows) > 4) {
            $li .= '<li class="tqk-la-img-more">' . esc_html(sprintf(__('+%d more image(s): see "Image optimizer" below.', 'tqk-wp-seo'), count($rows) - 4)) . '</li>';
        }
        $pro = \TQK_WPSEO\Core\Pro::active();
        $btn = $pro
            ? '<a class="button button-small tqk-la-img-btn" href="#tqk-img">' . esc_html__('Optimize these images automatically', 'tqk-wp-seo') . '</a>'
            : '<a class="button button-small tqk-la-img-btn is-pro" href="' . esc_url(\TQK_WPSEO\Core\Pro::url()) . '"><span class="tqk-la-pro-badge">PRO</span>' . esc_html__('Auto optimize · resize · convert to WebP', 'tqk-wp-seo') . '</a>';
        return '<div class="tqk-la-imgs"><div class="tqk-la-imgs-head">' . esc_html(sprintf(__('Images to optimize (%d)', 'tqk-wp-seo'), count($rows))) . '</div>'
            . '<ul>' . $li . '</ul>' . $btn . '</div>';
    }

    /** Sends every audited page with something to fix to the MCP inbox. */
    public static function push_all(array $run): int {
        $n = 0;
        $st = (string) ($run['st'] ?? '');
        foreach ((array) ($run['ids'] ?? []) as $i => $id) {
            if ((int) $id <= 0 || ($st[$i] ?? '') !== self::DONE) {
                continue;
            }
            $r = \TQK_WPSEO\MCP\Reports::get((int) $id);
            $todo = count(array_filter((array) ($r['plan'] ?? []), static fn($p) => (int) ($p['prio'] ?? 3) <= 2));
            if ($r !== null && $todo > 0) {
                \TQK_WPSEO\MCP\Reports::push((int) $id, __('From the site audit', 'tqk-wp-seo'));
                $n++;
            }
        }
        return $n;
    }

    /** @return array|null Cached record of an ID, only if it belongs to this run. */
    public static function record(int $id, string $run_id): ?array {
        $raw = $id === 0 ? get_option(self::HOME, '') : ($id < 0 ? get_term_meta(-$id, self::META, true) : get_post_meta($id, self::META, true));
        $rec = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($rec) && ($rec['run'] ?? '') === $run_id ? $rec : null;
    }

    public static function min_vp(array $rec): int {
        $scores = array_column((array) ($rec['vps'] ?? []), 'score');
        return $scores === [] ? 0 : (int) min($scores);
    }

    /** One report row: page, SEO, responsive, issues, snapshots. */
    public static function row_html(array $item, array $rec): string {
        $tone = static fn(int $s) => $s >= 80 ? 'is-good' : ($s >= 50 ? 'is-ok' : 'is-bad');
        $seo = (int) ($rec['seo']['score'] ?? 0);
        $rs = self::min_vp($rec);
        $title = (string) ($item['title'] !== '' ? $item['title'] : $item['url']);
        $schema = (array) ($rec['seo']['schema'] ?? []);
        $page = '<td><a href="' . esc_url((string) $item['url']) . '" target="_blank" rel="noopener">' . esc_html($title) . '</a>'
            . '<br><small>' . esc_html((string) $item['type']) . ($schema !== [] ? ' · ' . esc_html(implode(', ', $schema)) : '') . '</small></td>';

        if (!empty($rec['error'])) {
            return '<tr class="is-failed">' . $page . '<td>—</td><td>—</td>'
                . '<td><ul class="tqk-live-findings"><li class="is-crit">' . esc_html(sprintf(__('Could not audit: %s', 'tqk-wp-seo'), (string) $rec['error'])) . '</li></ul></td>'
                . '<td></td></tr>';
        }

        $issues = [];
        foreach ((array) ($rec['seo']['findings'] ?? []) as $f) {
            if (($f['sev'] ?? '') !== 'pass') {
                $issues[] = '<li class="' . (($f['sev'] ?? '') === 'critical' ? 'is-crit' : 'is-warn') . '">' . esc_html((string) $f['msg']) . '</li>';
            }
        }
        foreach ((array) ($rec['fields'] ?? []) as $m) {
            $issues[] = '<li class="is-warn">' . esc_html((string) $m) . '</li>';
        }
        foreach ((array) ($rec['groups'] ?? []) as $g) {
            $issues[] = '<li class="is-warn"><code>' . esc_html((string) $g['root']) . '</code> '
                . esc_html(sprintf(__('%1$d element(s) overflow — %2$s (%3$s)', 'tqk-wp-seo'), (int) $g['count'], (string) $g['reason'], (string) $g['vps'])) . '</li>';
        }
        $vps = [];
        foreach ((array) ($rec['vps'] ?? []) as $v) {
            $vps[] = '<span class="tqk-la-vp ' . $tone((int) $v['score']) . '" title="' . esc_attr(self::vp_why($v)) . '">'
                . esc_html((string) $v['label']) . ' · <b>' . (int) $v['score'] . '</b>/100</span>';
        }
        $images = self::images_block((int) ($item['id'] ?? 0));
        $shots = '';
        foreach ((array) ($rec['shots'] ?? []) as $key => $ref) {
            $u = self::file_url((string) $ref);
            if ($u === '') {
                continue; // pruned after the kept days
            }
            $shots .= '<a href="' . esc_url($u) . '" target="_blank" rel="noopener" class="tqk-la-shot tqk-la-shot-' . esc_attr((string) $key) . '">'
                . '<img loading="lazy" src="' . esc_url($u) . '" alt="' . esc_attr(sprintf(__('%1$s snapshot of %2$s', 'tqk-wp-seo'), (string) $key, $title)) . '"></a>';
        }
        $json = self::file_url((string) ($rec['json'] ?? ''));
        if ($json !== '') {
            $shots .= '<a class="tqk-la-json" href="' . esc_url($json) . '" target="_blank" rel="noopener">JSON</a>';
        }
        if ((int) ($item['id'] ?? 0) > 0 && \TQK_WPSEO\Core\Modules::is_active('mcp')) {
            $shots .= '<button type="button" class="button button-small tqk-la-push" data-push="' . (int) $item['id'] . '">' . esc_html__('Push to MCP', 'tqk-wp-seo') . '</button>';
        }

        return '<tr>' . $page
            . '<td><strong class="tqk-wpseo-score-num ' . $tone($seo) . '">' . $seo . '</strong></td>'
            . '<td><strong class="tqk-wpseo-score-num ' . $tone($rs) . '">' . $rs . '</strong><br>' . implode(' ', $vps) . '</td>'
            . '<td>' . ($issues === [] && $images === '' ? '<span class="tqk-rs-clean">' . esc_html__('No issues', 'tqk-wp-seo') . '</span>' : ($issues !== [] ? '<ul class="tqk-live-findings">' . implode('', $issues) . '</ul>' : '') . $images) . '</td>'
            . '<td class="tqk-la-shots">' . $shots . '</td>'
            . '</tr>';
    }

    /**
     * What the screen score (0-100) lost points for, as a tooltip: 100 minus
     * horizontal overflow (max 45), text under 12px (20), tap targets under
     * 44px (15), images without width/height (12), oversized images (8).
     */
    public static function vp_why(array $v): string {
        if (!empty($v['error'])) {
            return (string) $v['error'];
        }
        $parts = [];
        if ((int) ($v['overflow'] ?? 0) > 0) {
            $parts[] = sprintf(__('scrolls sideways by %dpx', 'tqk-wp-seo'), (int) $v['overflow']);
        }
        if ((int) ($v['tiny'] ?? 0) > 0) {
            $parts[] = sprintf(__('%d text(s) under 12px', 'tqk-wp-seo'), (int) $v['tiny']);
        }
        if ((int) ($v['tap'] ?? 0) > 0) {
            $parts[] = sprintf(__('%d button(s)/link(s) under 44px', 'tqk-wp-seo'), (int) $v['tap']);
        }
        if ((int) ($v['dims'] ?? 0) > 0) {
            $parts[] = sprintf(__('%d image(s) without width/height', 'tqk-wp-seo'), (int) $v['dims']);
        }
        if ((int) ($v['over'] ?? 0) > 0) {
            $parts[] = sprintf(__('%d oversized image(s)', 'tqk-wp-seo'), (int) $v['over']);
        }
        return __('Display score on this screen, out of 100.', 'tqk-wp-seo') . ' '
            . ($parts === [] ? __('Nothing to fix.', 'tqk-wp-seo') : __('Points lost for:', 'tqk-wp-seo') . ' ' . implode(', ', $parts) . '.');
    }

    public static function table_open(): string {
        return '<div class="tqk-wpseo-table-wrap"><div class="table-responsive"><table class="tqk-wpseo-table tqk-la-table"><thead><tr>'
            . '<th>' . esc_html__('Page', 'tqk-wp-seo') . '</th>'
            . '<th>' . esc_html__('SEO', 'tqk-wp-seo') . '</th>'
            . '<th title="' . esc_attr__('Display score per screen, out of 100: points are lost for sideways scrolling, tiny text, small buttons, images without size, oversized images.', 'tqk-wp-seo') . '">' . esc_html__('Display (/100)', 'tqk-wp-seo') . '</th>'
            . '<th>' . esc_html__('Issues', 'tqk-wp-seo') . '</th>'
            . '<th>' . esc_html__('Snapshot', 'tqk-wp-seo') . '</th>'
            . '</tr></thead><tbody id="tqk-la-rows">';
    }

    public static function table_close(): string {
        return '</tbody></table></div></div>';
    }

    /** Rows of the current run: failed first, then worst score; 50 per page. */
    public static function report_rows(array $run, int $page = 1): array {
        $st = (string) ($run['st'] ?? '');
        $rows = [];
        foreach ((array) ($run['ids'] ?? []) as $i => $id) {
            if (($st[$i] ?? self::PENDING) === self::PENDING) {
                continue;
            }
            $rec = self::record((int) $id, (string) ($run['id'] ?? ''))
                // Marked failed before anything was stored (deleted post).
                ?? ['error' => __('Page no longer published.', 'tqk-wp-seo')];
            $rows[] = [self::item((int) $id), $rec];
        }
        $rank = static fn(array $r) => (empty($r['error']) ? 1000 : 0) + (int) ($r['seo']['score'] ?? 0) + self::min_vp($r);
        usort($rows, static fn($a, $b) => $rank($a[1]) <=> $rank($b[1]));
        $pages = max(1, (int) ceil(count($rows) / 50));
        $page = max(1, min($pages, $page));
        $html = '';
        foreach (array_slice($rows, ($page - 1) * 50, 50) as [$item, $rec]) {
            $html .= self::row_html($item, $rec);
        }
        return ['html' => $html, 'page' => $page, 'pages' => $pages, 'count' => count($rows)];
    }

    /** AJAX entry, routed from Admin\Ajax (nonce + capability already checked). */
    public static function ajax(): void {
        $op = sanitize_key($_POST['op'] ?? '');
        if ($op === 'start') {
            $choices = [];
            foreach ((array) json_decode((string) wp_unslash($_POST['plan'] ?? ''), true) as $pt => $c) {
                // Keys: a post type, or "tax:{taxonomy}".
                if (preg_match('/^(tax:)?[a-z0-9_-]{1,40}$/', (string) $pt)) {
                    $choices[(string) $pt] = in_array($c, ['none', 'one', 'all'], true) ? $c : 'one';
                }
            }
            // Auditing every post of a type is Pro; one sample post is free.
            if (sanitize_key($_POST['mode'] ?? '') === 'all') {
                $choices = [sanitize_key((string) ($_POST['type'] ?? '')) => 'all'];
            }
            $locked = self::needs_pro($choices);
            if ($locked !== []) {
                wp_send_json_error(['message' => sprintf(__('Auditing every post of %s is a Pro feature. Choose "1 sample" or upgrade to Pro.', 'tqk-wp-seo'), implode(', ', $locked))]);
            }
            $run = self::start(
                sanitize_key($_POST['mode'] ?? 'sample'),
                sanitize_key($_POST['type'] ?? ''),
                !empty($_POST['snap']),
                $choices,
                ($_POST['pages'] ?? '1') !== '0'
            );
            [$items, $run] = self::batch($run);
            wp_send_json_success(['state' => self::state($run), 'items' => $items]);
        }
        $run = self::run();
        if ($run === [] || (string) ($_POST['run'] ?? '') !== (string) $run['id']) {
            wp_send_json_error(['message' => __('This audit run is no longer current. Reload the page.', 'tqk-wp-seo')]);
        }
        if ($op === 'next' || $op === 'retry') {
            if ($op === 'retry') {
                $run = self::retry_failed($run);
            } elseif (($run['status'] ?? '') === 'paused') {
                $run['status'] = 'running';
            }
            self::save_run($run);
            [$items, $run] = self::batch($run);
            wp_send_json_success(['state' => self::state($run), 'items' => $items]);
        }
        if ($op === 'pause') {
            if (($run['status'] ?? '') === 'running') {
                $run['status'] = 'paused';
                self::save_run($run);
            }
            wp_send_json_success(['state' => self::state($run)]);
        }
        if ($op === 'save') {
            $data = json_decode((string) wp_unslash($_POST['data'] ?? ''), true);
            $shots = [];
            foreach (self::SHOT_KEYS as $key) {
                $shots[$key] = (string) wp_unslash($_POST['shot_' . $key] ?? '');
            }
            $res = self::save($run, (int) ($_POST['index'] ?? -1), is_array($data) ? $data : [], $shots, !empty($_POST['failed']));
            if (!empty($res['error'])) {
                wp_send_json_error(['message' => __('Unknown page in this run, skipped.', 'tqk-wp-seo'), 'state' => self::state($res['run'])]);
            }
            // Same page report as the editor's Scan: the editor shows it and
            // MCP agents read it.
            $client = json_decode((string) wp_unslash($_POST['report'] ?? ''), true);
            if (is_array($client) && $res['record'] !== null && empty($res['record']['error']) && (int) ($res['item']['id'] ?? 0) > 0) {
                self::store_report((int) $res['item']['id'], $client, $res['record']);
            }
            wp_send_json_success([
                'state' => self::state($res['run']),
                'row' => $res['record'] !== null ? self::row_html($res['item'], $res['record']) : '',
            ]);
        }
        if ($op === 'report') {
            wp_send_json_success(self::report_rows($run, (int) ($_POST['page'] ?? 1)));
        }
        if ($op === 'mcp_push_all') {
            $n = self::push_all($run);
            wp_send_json_success(['message' => sprintf(__('%d page(s) with issues sent to the AI app.', 'tqk-wp-seo'), $n)]);
        }
        wp_send_json_error(['message' => __('Unknown action', 'tqk-wp-seo')]);
    }
}
