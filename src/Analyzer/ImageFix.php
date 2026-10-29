<?php
namespace TQK_WPSEO\Analyzer;

defined('ABSPATH') || exit;

/**
 * Image optimizer, fed by Live Audit's per-viewport image measurements.
 *
 *   measure (browser, 4 viewports) → analyze (here) → generate files
 *   (TQK WP SEO Pro add-on) → person activates → served to visitors.
 *
 * The free plugin measures, explains the manual steps and keeps serving
 * files already made (removing Pro never breaks a site). Writing new files
 * is done by the add-on through the tqk_wpseo_image_generate filter.
 *
 * Builder-agnostic by design, three serving paths:
 *   1. <img> through core: Flatsome, Elementor, Gutenberg, WooCommerce all
 *      print images via wp_get_attachment_image() or the content filter, which
 *      end in wp_calculate_image_srcset / _sizes. Hooking those covers them.
 *   2. Output-buffer fallback: raw <img> without srcset gets one; an image
 *      that shows a different crop per screen is wrapped in <picture>.
 *   3. Background images: an override stylesheet per breakpoint, targeting the
 *      element's id or Elementor's .elementor-element-* class, so it works
 *      when the builder keeps the background in its own CSS file. Inline
 *      style backgrounds without such a hook are rewritten in place.
 *
 * Scope: a page ({id}), or "site" for images repeated on several audited
 * pages with the same boxes (logo, header, footer): made once, served on
 * every page including archives.
 *
 * Hard rules:
 *   - the original upload is never modified (og:image and Google Discover
 *     keep the large file; new sizes only ever ADD choices);
 *   - crop only to what visitors actually see (object-fit: cover region);
 *   - nothing reaches visitors until the person activates it.
 */
class ImageFix {

    public const META = '_tqk_wpseo_img_fix';
    public const SITE_OPTION = 'tqk_wpseo_img_fix_site';
    public const DIR = 'audit-pics';
    public const VPS = ['desktop' => 1440, 'laptop' => 1280, 'tablet' => 768, 'mobile' => 375];
    /** Media query per measured viewport band. */
    public const MEDIA = [
        'mobile' => '(max-width: 767px)',
        'tablet' => '(min-width: 768px) and (max-width: 1279px)',
        'laptop' => '(min-width: 1280px) and (max-width: 1439px)',
        'desktop' => '(min-width: 1440px)',
    ];
    public const MAX_IMAGES = 30;
    /** Image measurements from the post editor's Scan (JSON {at, images}). */
    public const EDITOR_META = '_tqk_wpseo_scan_images';

    private static array $plans = [];
    private static ?array $site_keys = null;

    // ---------- input ----------

    /** Whitelist of the browser's image measurements. */
    public static function clean_images(array $list): array {
        $int = static fn($v) => max(0, min(20000, (int) $v));
        $str = static fn($v, int $max) => is_scalar($v) ? mb_substr(sanitize_text_field((string) $v), 0, $max) : '';
        $url = static function ($v) {
            $v = is_scalar($v) ? trim((string) $v) : '';
            return preg_match('#^(https?://|/)[^\s"\'<>]{1,500}$#i', $v) ? $v : '';
        };
        $out = [];
        foreach (array_slice($list, 0, 60) as $im) {
            $im = (array) $im;
            $src = $url($im['src'] ?? '');
            if ($src === '') {
                continue;
            }
            $vp = [];
            foreach (array_keys(self::VPS) as $k) {
                $v = (array) ($im['vp'][$k] ?? []);
                if ($v === []) {
                    continue;
                }
                $vp[$k] = [
                    'w' => $int($v['w'] ?? 0), 'h' => $int($v['h'] ?? 0),
                    'nw' => $int($v['nw'] ?? 0), 'nh' => $int($v['nh'] ?? 0),
                    'fit' => $str($v['fit'] ?? '', 20), 'pos' => $str($v['pos'] ?? '', 40),
                    'cur' => $url($v['cur'] ?? ''),
                ];
            }
            $out[] = [
                'kind' => ($im['kind'] ?? '') === 'bg' ? 'bg' : 'img',
                'src' => $src,
                'att' => $int($im['att'] ?? 0),
                'srcset' => !empty($im['srcset']),
                'wdesc' => !empty($im['wdesc']),
                'sizes' => $str($im['sizes'] ?? '', 200),
                'inline' => !empty($im['inline']),
                'sel' => self::clean_selector((string) ($im['sel'] ?? '')),
                'vp' => $vp,
            ];
        }
        return $out;
    }

    /**
     * Only two selector shapes are accepted for background overrides: an id,
     * or Elementor's per-element class. Anything else could hit other
     * elements, so it is refused.
     */
    public static function clean_selector(string $sel): string {
        $sel = trim($sel);
        return preg_match('/^(#[A-Za-z][\w-]{0,80}|\.elementor-element-[a-z0-9]{3,20})$/', $sel) ? $sel : '';
    }

    /** Attachment ID from the builder's class, else from the URL (size suffix stripped). */
    public static function resolve_att(array $im): int {
        if ((int) ($im['att'] ?? 0) > 0) {
            return (int) $im['att'];
        }
        if (!function_exists('attachment_url_to_postid')) {
            return 0;
        }
        $src = (string) $im['src'];
        $id = (int) attachment_url_to_postid($src);
        if ($id > 0) {
            return $id;
        }
        $base = (string) preg_replace('/-(\d+x\d+|scaled)(\.[a-z0-9]+)$/i', '$2', $src);
        return $base !== $src ? (int) attachment_url_to_postid($base) : 0;
    }

    /** @return array{path:string,url:string,w:int,h:int}|null The untouched original upload. */
    public static function original(int $att): ?array {
        if ($att <= 0) {
            return null;
        }
        $path = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($att) : '';
        if ($path === '' || !file_exists($path)) {
            $path = (string) get_attached_file($att);
        }
        if ($path === '' || !file_exists($path)) {
            return null;
        }
        $size = @getimagesize($path);
        if (!is_array($size) || $size[0] <= 0) {
            return null;
        }
        return ['path' => $path, 'url' => (string) wp_get_attachment_url($att), 'w' => (int) $size[0], 'h' => (int) $size[1]];
    }

    // ---------- analysis (pure: no I/O, unit-testable) ----------

    /** "25% 80%" / "left top" / "50px 0" → [fx, fy] fractions (px treated as centre). */
    public static function position(string $pos): array {
        $map = ['left' => 0.0, 'top' => 0.0, 'center' => 0.5, 'right' => 1.0, 'bottom' => 1.0];
        $parts = preg_split('/\s+/', trim($pos)) ?: [];
        $f = [];
        foreach (array_slice($parts, 0, 2) as $p) {
            if (isset($map[$p])) {
                $f[] = $map[$p];
            } elseif (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $p, $m)) {
                $f[] = max(0, min(1, (float) $m[1] / 100));
            } else {
                $f[] = 0.5;
            }
        }
        return [$f[0] ?? 0.5, $f[1] ?? 0.5];
    }

    /**
     * Region of the ORIGINAL (ow×oh) visible in a bw×bh box with
     * object-fit:cover at object-position. @return int[] [x, y, w, h]
     */
    public static function cover_region(int $ow, int $oh, int $bw, int $bh, string $pos): array {
        $scale = max($bw / $ow, $bh / $oh);
        $vw = (int) min($ow, round($bw / $scale));
        $vh = (int) min($oh, round($bh / $scale));
        [$fx, $fy] = self::position($pos);
        return [(int) round(($ow - $vw) * $fx), (int) round(($oh - $vh) * $fy), $vw, $vh];
    }

    /** sizes attribute from measured widths: vw when the image is fluid, px when it sits in a fixed column. */
    public static function sizes_attr(array $vp): string {
        $bands = [['mobile', 767], ['tablet', 1279], ['laptop', 1439]];
        $parts = [];
        foreach ($bands as [$k, $max]) {
            if (!isset($vp[$k]['w'])) {
                continue;
            }
            $parts[] = '(max-width: ' . $max . 'px) ' . self::size_len((int) $vp[$k]['w'], self::VPS[$k]);
        }
        $parts[] = isset($vp['desktop']['w']) ? self::size_len((int) $vp['desktop']['w'], self::VPS['desktop']) : '100vw';
        return implode(', ', $parts);
    }

    public static function size_len(int $w, int $vw): string {
        $ratio = $w / max(1, $vw);
        return $ratio >= 0.5 ? max(1, (int) ceil($ratio * 100)) . 'vw' : $w . 'px';
    }

    private static function ceil10(float $w): int {
        return (int) (ceil($w / 10) * 10);
    }

    /**
     * Widths worth generating: each viewport's box at 1x and 2x (retina),
     * rounded up to 10 px, never above the source, near-duplicates merged.
     *
     * @return int[]
     */
    public static function target_widths(array $vp, int $max_w): array {
        $ws = [];
        foreach ($vp as $v) {
            foreach ([1, 2] as $dpr) {
                $w = self::ceil10((int) $v['w'] * $dpr);
                if ($w >= 40) {
                    $ws[] = min($w, $max_w);
                }
            }
        }
        sort($ws);
        $out = [];
        foreach (array_unique($ws) as $w) {
            if ($out === [] || $w > end($out) * 1.08) {
                $out[] = $w;
            }
        }
        return array_slice($out, -6);
    }

    /**
     * One image: what is wrong and what can be done automatically.
     *
     * @param array|null $orig ['w','h'] of the original upload.
     * @return array{issues:string[], fixable:bool, widths:int[], crop:?array, art:?array, bgw:array, sizes:string, need:int}
     *   crop: one visible region for every screen; art: [viewport => region]
     *   when each screen shows another region (<picture>); bgw: [viewport => width].
     */
    public static function analyze(array $im, ?array $orig): array {
        $vp = array_filter((array) ($im['vp'] ?? []), static fn($v) => (int) ($v['w'] ?? 0) > 0);
        $out = ['issues' => [], 'fixable' => false, 'widths' => [], 'crop' => null, 'art' => null, 'bgw' => [], 'sizes' => '', 'need' => 0];
        if ($vp === []) {
            return $out;
        }
        $need = 0;
        foreach ($vp as $k => $v) {
            $need = max($need, (int) $v['w'] * 2);
            // Served file far bigger than the box needs even on a retina screen.
            if ((int) $v['nw'] > 0 && (int) $v['nw'] > $v['w'] * 2 * 1.25 && (int) $v['nw'] - $v['w'] * 2 > 100) {
                $out['issues'][] = sprintf(__('%1$s: file %2$dpx wide for a %3$dpx box', 'tqk-wp-seo'), ucfirst((string) $k), (int) $v['nw'], (int) $v['w']);
            }
        }
        $out['need'] = $need;
        $served = strtolower((string) pathinfo((string) strtok((string) (reset($vp)['cur'] ?: $im['src']), '?#'), PATHINFO_EXTENSION));
        $legacy = in_array($served, ['jpg', 'jpeg', 'png', 'gif', 'bmp'], true);

        if ($im['kind'] === 'bg') {
            if ($orig !== null && $orig['w'] > $need * 1.25) {
                $out['issues'][] = sprintf(__('Background file %1$dpx wide for a %2$dpx box.', 'tqk-wp-seo'), $orig['w'], (int) round($need / 2));
            }
            if ($legacy) {
                $out['issues'][] = sprintf(__('Served as %s: convert to compressed WebP.', 'tqk-wp-seo'), strtoupper($served));
            }
            if ($out['issues'] === []) {
                return $out;
            }
            $hook = ($im['sel'] ?? '') !== '' || !empty($im['inline']);
            if ($orig === null) {
                return $out;
            }
            if (!$hook) {
                $out['issues'][] = __('Background set in a CSS file on an element without an id we can target: resize it by hand or in the builder.', 'tqk-wp-seo');
                return $out;
            }
            foreach ($vp as $k => $v) {
                $out['bgw'][$k] = min($orig['w'], self::ceil10((int) $v['w'] * 2));
            }
            $out['widths'] = array_values(array_unique($out['bgw']));
            sort($out['widths']);
            $out['fixable'] = true;
            return $out;
        }

        // Aspect ratio: the box vs the file.
        $ratios = [];
        $fits = [];
        foreach ($vp as $v) {
            $ratios[] = $v['w'] / max(1, $v['h']);
            $fits[] = (string) $v['fit'];
        }
        if ($orig !== null) {
            $or = $orig['w'] / max(1, $orig['h']);
            $off = max(array_map(static fn($r) => abs($r - $or) / $or, $ratios));
            if ($off > 0.04) {
                if (in_array('fill', $fits, true)) {
                    $out['issues'][] = __('Image is stretched (box ratio differs, object-fit: fill). Set object-fit: cover or use a matching image.', 'tqk-wp-seo');
                } elseif (count(array_unique($fits)) === 1 && $fits[0] === 'cover') {
                    $same = (max($ratios) - min($ratios)) / max(0.01, min($ratios)) < 0.03;
                    if ($same) {
                        $any = reset($vp);
                        $crop = self::cover_region($orig['w'], $orig['h'], (int) $any['w'], (int) $any['h'], (string) $any['pos']);
                        if (($crop[2] * $crop[3]) < 0.9 * $orig['w'] * $orig['h']) {
                            $out['crop'] = $crop;
                            $out['issues'][] = sprintf(__('Only %d%% of the file is visible (object-fit: cover): crop to the visible area.', 'tqk-wp-seo'), (int) round(100 * $crop[2] * $crop[3] / ($orig['w'] * $orig['h'])));
                        }
                    } else {
                        $art = [];
                        foreach ($vp as $k => $v) {
                            $art[$k] = self::cover_region($orig['w'], $orig['h'], (int) $v['w'], (int) $v['h'], (string) $v['pos']);
                        }
                        $out['art'] = $art;
                        $out['issues'][] = __('Each screen shows a different crop: serve one crop per screen with <picture>.', 'tqk-wp-seo');
                    }
                }
            }
        }
        if (empty($im['srcset'])) {
            $out['issues'][] = __('No srcset: every screen downloads the same file.', 'tqk-wp-seo');
        }
        if ($legacy) {
            $out['issues'][] = sprintf(__('Served as %s: convert to compressed WebP.', 'tqk-wp-seo'), strtoupper($served));
        }
        $sizes = self::sizes_attr($vp);
        $cur = trim((string) ($im['sizes'] ?? ''));
        if (!empty($im['srcset']) && !empty($im['wdesc']) && ($cur === '' || (preg_match('/^\(max-width:\s*\d+px\)\s*100vw,\s*\d+px$/', $cur) && isset($vp['desktop']) && $vp['desktop']['w'] < 0.6 * self::VPS['desktop']))) {
            $out['issues'][] = sprintf(__('sizes="%1$s" does not match the layout; measured: %2$s', 'tqk-wp-seo'), $cur === '' ? '—' : $cur, $sizes);
        }
        $out['sizes'] = $sizes;
        if ($orig !== null && $out['issues'] !== []) {
            if ($out['art'] !== null) {
                $out['widths'] = [];
                foreach ($out['art'] as $k => $r) {
                    foreach (self::art_widths((int) $vp[$k]['w'], (int) $r[2]) as $w) {
                        $out['widths'][] = $w;
                    }
                }
                $out['widths'] = array_values(array_unique($out['widths']));
                sort($out['widths']);
            } else {
                $src_w = $out['crop'] !== null ? $out['crop'][2] : $orig['w'];
                $out['widths'] = self::target_widths($vp, $src_w);
            }
            $out['fixable'] = $out['widths'] !== [];
        }
        return $out;
    }

    /** 1x and 2x of one screen's box, capped at the crop width. @return int[] */
    public static function art_widths(int $box_w, int $crop_w): array {
        return array_values(array_unique(array_filter([
            min($crop_w, self::ceil10($box_w)),
            min($crop_w, self::ceil10($box_w * 2)),
        ], static fn($w) => $w >= 40)));
    }

    /**
     * Free tier: the same analysis, written as steps a person can do by hand
     * (image editor + builder). Pro does exactly these steps automatically.
     *
     * @return string[]
     */
    public static function manual_steps(array $a, ?array $orig, string $kind): array {
        if ($a['issues'] === [] || $orig === null) {
            return [];
        }
        $steps = [];
        if ($a['crop'] !== null) {
            [$x, $y, $w, $h] = $a['crop'];
            $steps[] = sprintf(__('Crop the original to %1$d×%2$d starting at x=%3$d, y=%4$d (only that part is visible).', 'tqk-wp-seo'), $w, $h, $x, $y);
        }
        if (!empty($a['art'])) {
            foreach ($a['art'] as $k => [$x, $y, $w, $h]) {
                $steps[] = sprintf(__('%1$s: crop %2$d×%3$d at x=%4$d, y=%5$d.', 'tqk-wp-seo'), ucfirst((string) $k), $w, $h, $x, $y);
            }
        }
        if ($kind === 'bg' && !empty($a['bgw'])) {
            $parts = [];
            foreach ($a['bgw'] as $k => $w) {
                $parts[] = ucfirst((string) $k) . ' ' . $w . 'px';
            }
            $steps[] = sprintf(__('Export one file per screen: %s.', 'tqk-wp-seo'), implode(', ', $parts));
        } else {
            $widths = $a['widths'] !== [] ? $a['widths'] : [min($orig['w'], self::ceil10(max(1, $a['need'])))];
            $steps[] = count($widths) > 1
                ? sprintf(__('Export these widths: %s px (small ones for phones, the largest for retina desktops).', 'tqk-wp-seo'), implode(', ', $widths))
                : sprintf(__('Export at %d px wide.', 'tqk-wp-seo'), $widths[0]);
        }
        $steps[] = __('Save as WebP, quality about 75-80 (PNG with transparency also works as WebP).', 'tqk-wp-seo');
        if ($kind === 'img' && $a['sizes'] !== '') {
            $steps[] = sprintf(__('Upload them to the Media Library and use the image through the builder\'s image element so WordPress adds srcset; set sizes="%s" if the builder allows it.', 'tqk-wp-seo'), $a['sizes']);
        } else {
            $steps[] = __('Set the smaller files as the background per device in the builder (Elementor and Flatsome allow a different background image per device).', 'tqk-wp-seo');
        }
        return $steps;
    }

    // ---------- scopes: one page, or "site" for shared images ----------

    /** @return int|string A page ID, or "site". */
    public static function scope($raw) {
        return $raw === 'site' ? 'site' : max(0, (int) $raw);
    }

    private static function image_key(array $im): string {
        $att = self::resolve_att($im);
        return $im['kind'] . '|' . ($att > 0 ? 'a' . $att : self::norm((string) $im['src']));
    }

    /** @return array<int,array> page ID => image list, for every finished page of the current run. */
    private static function audited_images(): array {
        $run = LiveAudit::run();
        $out = [];
        foreach ((array) ($run['ids'] ?? []) as $i => $id) {
            if ((($run['st'] ?? '')[$i] ?? '') !== LiveAudit::DONE) {
                continue;
            }
            $rec = LiveAudit::record((int) $id, (string) $run['id']);
            if ($rec !== null && !empty($rec['images'])) {
                $out[(int) $id] = (array) $rec['images'];
            }
        }
        return $out;
    }

    /**
     * Images shared by 2+ audited pages with the same box at every screen
     * (±10%): header logo, footer badges, site-wide banners.
     *
     * @return array<string,array> key => merged image (vp = largest box seen)
     */
    public static function shared(array $by_page): array {
        $seen = [];
        foreach ($by_page as $page => $images) {
            foreach ($images as $im) {
                $seen[self::image_key($im)][$page] = $im;
            }
        }
        $out = [];
        foreach ($seen as $key => $pages) {
            if (count($pages) < 2) {
                continue;
            }
            $merged = reset($pages);
            $consistent = true;
            foreach ($pages as $im) {
                foreach ((array) $im['vp'] as $k => $v) {
                    $ref = (int) ($merged['vp'][$k]['w'] ?? 0);
                    if ($ref > 0 && abs((int) $v['w'] - $ref) > 0.1 * $ref) {
                        $consistent = false;
                        break 2;
                    }
                    if ($ref === 0 || (int) $v['w'] > $ref) {
                        $merged['vp'][$k] = $v;
                    }
                }
            }
            if ($consistent) {
                $merged['pages'] = count($pages);
                $out[$key] = $merged;
            }
        }
        return $out;
    }

    private static function shared_keys(): array {
        if (self::$site_keys === null) {
            self::$site_keys = self::shared(self::audited_images());
        }
        return self::$site_keys;
    }

    /**
     * Measured images of one page: from the editor's Scan when it is newer
     * than the page's Live Audit record, else from Live Audit.
     */
    public static function page_images(int $id): array {
        $run = LiveAudit::run();
        $rec = $id > 0 ? LiveAudit::record($id, (string) ($run['id'] ?? '')) : null;
        $ed = json_decode((string) get_post_meta($id, self::EDITOR_META, true), true);
        if (is_array($ed) && !empty($ed['images']) && (int) ($ed['at'] ?? 0) >= (int) ($rec['at'] ?? 0)) {
            return (array) $ed['images'];
        }
        return (array) ($rec['images'] ?? []);
    }

    /** Stores the editor Scan's merged image measurements (whitelisted). */
    public static function save_editor_images(int $id, array $images): int {
        $clean = self::clean_images($images);
        update_post_meta($id, self::EDITOR_META, wp_slash((string) wp_json_encode(['at' => time(), 'images' => $clean])));
        return count($clean);
    }

    /** @return array Image rows with issues for a scope. */
    public static function rows($scope): array {
        $scope = self::scope($scope);
        if ($scope === 'site') {
            $list = array_values(self::shared_keys());
        } else {
            $shared = self::shared_keys();
            $list = array_values(array_filter(self::page_images((int) $scope), static fn($im) => !isset($shared[self::image_key($im)])));
        }
        $rows = [];
        foreach ($list as $im) {
            $att = self::resolve_att($im);
            $orig = $im['kind'] === 'bg' ? self::bg_original((string) $im['src'], $att) : self::original($att);
            $a = self::analyze($im, $orig);
            if ($a['issues'] === []) {
                continue;
            }
            $rows[] = ['im' => $im, 'att' => $att, 'orig' => $orig, 'a' => $a];
        }
        return array_slice($rows, 0, self::MAX_IMAGES);
    }

    /** Background files: attachment original, else a file inside uploads. */
    private static function bg_original(string $src, int $att): ?array {
        if ($att > 0) {
            return self::original($att);
        }
        $path = self::url_to_path($src);
        $size = $path !== '' ? @getimagesize($path) : false;
        return is_array($size) ? ['path' => $path, 'url' => $src, 'w' => (int) $size[0], 'h' => (int) $size[1]] : null;
    }

    /** Local path for a URL inside uploads, '' otherwise (never outside uploads). */
    public static function url_to_path(string $url): string {
        if (!function_exists('wp_upload_dir')) {
            return '';
        }
        $up = wp_upload_dir();
        $base = (string) $up['baseurl'];
        $u = (string) preg_replace('#^https?:#i', '', $url);
        $b = (string) preg_replace('#^https?:#i', '', $base);
        if ($b === '' || strpos($u, $b . '/') !== 0) {
            return '';
        }
        $rel = rawurldecode(substr($u, strlen($b) + 1));
        if (strpos($rel, '..') !== false) {
            return '';
        }
        $path = (string) $up['basedir'] . '/' . strtok($rel, '?#');
        return file_exists($path) ? $path : '';
    }

    /** @param int|string $scope */
    public static function plan($scope): array {
        $scope = self::scope($scope);
        if (!isset(self::$plans[$scope])) {
            $raw = $scope === 'site' ? get_option(self::SITE_OPTION, '') : get_post_meta((int) $scope, self::META, true);
            $p = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            self::$plans[$scope] = is_array($p) ? $p : [];
        }
        return self::$plans[$scope];
    }

    /** Stores a scope's plan (the Pro add-on saves it after generating files). */
    public static function save_plan($scope, array $plan): void {
        $scope = self::scope($scope);
        self::$plans[$scope] = $plan;
        if ($scope === 'site') {
            $plan === [] ? delete_option(self::SITE_OPTION) : update_option(self::SITE_OPTION, (string) wp_json_encode($plan, JSON_UNESCAPED_SLASHES), false);
            return;
        }
        if ($plan === []) {
            delete_post_meta((int) $scope, self::META);
            return;
        }
        update_post_meta((int) $scope, self::META, wp_slash((string) wp_json_encode($plan, JSON_UNESCAPED_SLASHES)));
    }

    /** @return array{0:string,1:string} [path, url] of a scope's folder. */
    public static function dir($scope): array {
        if (!function_exists('wp_upload_dir')) {
            return ['', ''];
        }
        $up = wp_upload_dir();
        $scope = self::scope($scope);
        $rel = self::DIR . '/' . ($scope === 'site' ? 'site' : $scope . '-page');
        return [trailingslashit($up['basedir']) . $rel, trailingslashit($up['baseurl']) . $rel];
    }

    /** @param int|string $scope */
    public static function set_active($scope, bool $on): bool {
        $plan = self::plan($scope);
        if ($plan === []) {
            return false;
        }
        $plan['active'] = $on;
        self::save_plan($scope, $plan);
        return true;
    }

    /** Deletes a scope's generated files (only ours) and its plan. @param int|string $scope */
    public static function purge($scope): void {
        [$path] = self::dir($scope);
        if ($path !== '' && is_dir($path)) {
            foreach ((array) glob($path . '/*') as $f) {
                if (preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', (string) $f)) {
                    @wp_delete_file((string) $f);
                }
            }
            @wp_delete_file($path . '/index.php');
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            @rmdir($path);
        }
        self::save_plan($scope, []);
    }

    // ---------- serving (front end) ----------

    public static function hooks(): void {
        add_filter('wp_calculate_image_srcset', [self::class, 'filter_srcset'], 20, 5);
        add_filter('wp_calculate_image_sizes', [self::class, 'filter_sizes'], 20, 5);
        add_action('template_redirect', [self::class, 'maybe_buffer'], 1);
    }

    /**
     * Active entries for the request: site-wide ones everywhere, plus the
     * page's own on singular views (a page entry wins over a site one).
     */
    private static function active_plan(): array {
        if (is_admin()) {
            return [];
        }
        $site = self::plan('site');
        $out = !empty($site['active']) ? (array) ($site['images'] ?? []) : [];
        if (function_exists('is_singular') && is_singular()) {
            $page = self::plan((int) get_queried_object_id());
            if (!empty($page['active'])) {
                $out = array_merge($out, (array) ($page['images'] ?? []));
            }
        }
        return $out;
    }

    /** @return array|null srcset entry (plain <img>, not art-directed) for an attachment. */
    private static function entry_for(int $att): ?array {
        foreach (self::active_plan() as $e) {
            if ((int) ($e['att'] ?? 0) === $att && $att > 0 && ($e['kind'] ?? '') === 'img' && empty($e['art'])) {
                return $e;
            }
        }
        return null;
    }

    /** Adds our widths to core's srcset; a cropped image uses ONLY our files (same visible region). */
    public static function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        $e = self::entry_for((int) $attachment_id);
        if ($e === null) {
            return $sources;
        }
        $sources = !empty($e['crop']) || !is_array($sources) ? [] : $sources;
        foreach ((array) $e['files'] as $f) {
            $sources[(int) $f['w']] = ['url' => (string) $f['url'], 'descriptor' => 'w', 'value' => (int) $f['w']];
        }
        ksort($sources);
        return $sources;
    }

    public static function filter_sizes($sizes, $size, $image_src, $image_meta, $attachment_id) {
        $e = self::entry_for((int) $attachment_id);
        return $e !== null && !empty($e['sizes']) ? (string) $e['sizes'] : $sizes;
    }

    /** Output buffer only when the request has something active. */
    public static function maybe_buffer(): void {
        $plan = self::active_plan();
        if ($plan === []) {
            return;
        }
        ob_start(static fn(string $html) => self::rewrite($html, $plan));
    }

    /** Background override stylesheet, one media query per screen band. */
    public static function bg_css(array $plan): string {
        $css = '';
        foreach (self::MEDIA as $k => $mq) {
            $rules = '';
            foreach ($plan as $e) {
                $sel = self::clean_selector((string) ($e['sel'] ?? ''));
                if (($e['kind'] ?? '') !== 'bg' || $sel === '' || empty($e['bg'][$k])) {
                    continue;
                }
                $rules .= 'html body ' . $sel . '{background-image:url("' . esc_url((string) $e['bg'][$k]) . '")!important}';
            }
            if ($rules !== '') {
                $css .= '@media ' . $mq . '{' . $rules . '}';
            }
        }
        return $css;
    }

    /** <picture> sources for an art-directed image (one crop per screen band). */
    public static function picture_sources(array $e): string {
        $out = '';
        foreach (['mobile', 'tablet', 'laptop', 'desktop'] as $k) {
            $files = (array) ($e['art'][$k] ?? []);
            if ($files === []) {
                continue;
            }
            $set = implode(', ', array_map(static fn($f) => esc_url((string) $f['url']) . ' ' . (int) $f['w'] . 'w', $files));
            $out .= '<source media="' . esc_attr(self::MEDIA[$k]) . '" srcset="' . esc_attr($set) . '" sizes="' . esc_attr((string) ($e['art_sizes'][$k] ?? '100vw')) . '">';
        }
        return $out;
    }

    /**
     * Fallbacks after core's filters: srcset on raw <img>, <picture> for
     * art-directed images, inline-style backgrounds, and the background
     * override stylesheet. Attribute edits go through the core tag processor.
     */
    public static function rewrite(string $html, array $plan): string {
        $by_url = [];
        $art = [];
        $bgs = [];
        foreach ($plan as $e) {
            $files = (array) ($e['files'] ?? []);
            if ($files === []) {
                continue;
            }
            if (($e['kind'] ?? '') === 'bg') {
                if (self::clean_selector((string) ($e['sel'] ?? '')) === '' && !empty($e['inline'])) {
                    $bgs[(string) $e['src']] = (string) end($files)['url'];
                }
                continue;
            }
            if (!empty($e['art'])) {
                $art[self::norm((string) $e['src'])] = $e;
                continue;
            }
            $set = [];
            foreach ($files as $f) {
                $set[] = $f['url'] . ' ' . (int) $f['w'] . 'w';
            }
            $by_url[self::norm((string) $e['src'])] = ['srcset' => implode(', ', $set), 'sizes' => (string) $e['sizes']];
        }
        if (class_exists('WP_HTML_Tag_Processor') && ($by_url !== [] || $bgs !== [])) {
            $p = new \WP_HTML_Tag_Processor($html);
            while ($p->next_tag()) {
                if ($p->get_tag() === 'IMG' && $by_url !== []) {
                    $hit = $by_url[self::norm((string) $p->get_attribute('src'))] ?? null;
                    if ($hit !== null && $p->get_attribute('srcset') === null) {
                        $p->set_attribute('srcset', $hit['srcset']);
                        $p->set_attribute('sizes', $hit['sizes']);
                    }
                }
                if ($bgs !== []) {
                    $style = $p->get_attribute('style');
                    if (is_string($style) && stripos($style, 'url(') !== false) {
                        $new = $style;
                        foreach ($bgs as $from => $to) {
                            $new = str_replace($from, $to, $new);
                        }
                        if ($new !== $style) {
                            $p->set_attribute('style', $new);
                        }
                    }
                }
            }
            $html = $p->get_updated_html();
        }
        if ($art !== []) {
            // Wrap only <img> not already inside a <picture>.
            $html = (string) preg_replace_callback('/(<picture\b.*?<\/picture>)|<img\b[^>]*>/is', static function ($m) use ($art) {
                if (!empty($m[1])) {
                    return $m[0];
                }
                if (!preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $m[0], $s)) {
                    return $m[0];
                }
                $e = $art[self::norm(html_entity_decode($s[1]))] ?? null;
                return $e === null ? $m[0] : '<picture class="tqk-art">' . self::picture_sources($e) . $m[0] . '</picture>';
            }, $html);
        }
        $css = self::bg_css($plan) . ($art !== [] ? 'picture.tqk-art{display:contents}' : '');
        if ($css !== '') {
            $tag = '<style id="tqk-wpseo-img-fix">' . $css . '</style>';
            $pos = stripos($html, '</head>');
            $html = $pos === false ? $tag . $html : substr_replace($html, $tag, $pos, 0);
        }
        return $html;
    }

    private static function norm(string $u): string {
        return (string) preg_replace('#^https?:#i', '', strtok($u, '?#') ?: $u);
    }

    /**
     * Active plugins that also resize / convert / rewrite images. Two tools
     * rewriting the same <img> (srcset, WebP, lazy-load) fight each other.
     *
     * @return string[] Plugin names.
     */
    public static function conflicts(): array {
        $known = [
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'ewww-image-optimizer/ewww-image-optimizer.php' => 'EWWW Image Optimizer',
            'shortpixel-image-optimiser/wp-shortpixel.php' => 'ShortPixel',
            'imagify/imagify.php' => 'Imagify',
            'webp-express/webp-express.php' => 'WebP Express',
            'wp-smushit/wp-smush.php' => 'Smush',
            'optimole-wp/optimole-wp.php' => 'Optimole',
            'webp-converter-for-media/webp-converter-for-media.php' => 'Converter for Media',
            'jetpack/jetpack.php' => 'Jetpack (Site Accelerator)',
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'perfmatters/perfmatters.php' => 'Perfmatters',
            'robin-image-optimizer/robin-image-optimizer.php' => 'Robin Image Optimizer',
            'allow-webp-image/allow-webp-image.php' => 'Allow Webp Image',
        ];
        $active = (array) get_option('active_plugins', []);
        $out = [];
        foreach ($known as $file => $name) {
            if (in_array($file, $active, true)) {
                $out[] = $name;
            }
        }
        return (array) apply_filters('tqk_wpseo_image_conflicts', $out);
    }

    // ---------- admin report ----------

    public static function fmt_bytes(int $b): string {
        return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(0, (int) round($b / 1024)) . ' KB';
    }

    /** Pages of the current run with page-specific image issues: [id => count]. */
    public static function pages(): array {
        $out = [];
        foreach (array_keys(self::audited_images()) as $id) {
            if ($id <= 0) {
                continue;
            }
            $n = count(self::rows($id));
            if ($n > 0) {
                $out[$id] = $n;
            }
        }
        return $out;
    }

    /** Fix column: Pro shows what it will generate; free shows the manual steps. */
    private static function fix_cell(array $a, ?array $orig, string $kind, bool $pro): string {
        if ($pro) {
            if (!$a['fixable']) {
                return '<small>' . esc_html__('report only', 'tqk-wp-seo') . '</small>';
            }
            $what = $kind === 'bg'
                ? __('one file per screen', 'tqk-wp-seo') . ': ' . implode(', ', array_map(static fn($w) => $w . 'w', $a['widths']))
                : (!empty($a['art']) ? __('<picture>, one crop per screen', 'tqk-wp-seo') . ': ' : ($a['crop'] !== null ? __('crop + ', 'tqk-wp-seo') : ''))
                    . ($kind === 'img' ? implode(', ', array_map(static fn($w) => $w . 'w', $a['widths'])) : '');
            return '<small>' . esc_html($what) . '</small>';
        }
        $steps = self::manual_steps($a, $orig, $kind);
        return $steps === []
            ? '<small>' . esc_html__('report only', 'tqk-wp-seo') . '</small>'
            : '<ol class="tqk-img-steps">' . implode('', array_map(static fn($s) => '<li>' . esc_html($s) . '</li>', $steps)) . '</ol>';
    }

    /** @param int|string $scope */
    public static function page_html($scope): string {
        $scope = self::scope($scope);
        $plan = self::plan($scope);
        $pro = \TQK_WPSEO\Core\Pro::active();
        $rows = self::rows($scope);
        $html_rows = '';
        $has_art = false;
        foreach ($rows as $row) {
            $im = $row['im'];
            $a = $row['a'];
            $has_art = $has_art || !empty($a['art']);
            $boxes = [];
            foreach ((array) $im['vp'] as $k => $v) {
                $boxes[] = esc_html(ucfirst((string) $k)) . ' ' . (int) $v['w'] . '×' . (int) $v['h'];
            }
            $thumb = $im['kind'] === 'img' ? '<img src="' . esc_url((string) $im['src']) . '" alt="" loading="lazy">' : '<span class="tqk-img-bg">BG</span>';
            $html_rows .= '<tr><td class="tqk-img-thumb">' . $thumb . '</td>'
                . '<td><code>' . esc_html(basename((string) strtok((string) $im['src'], '?'))) . '</code><br><small>'
                . ($row['orig'] ? esc_html(sprintf(__('original %1$d×%2$d', 'tqk-wp-seo'), $row['orig']['w'], $row['orig']['h'])) : esc_html__('not in media library', 'tqk-wp-seo'))
                . (isset($im['pages']) ? '<br>' . esc_html(sprintf(__('on %d pages', 'tqk-wp-seo'), (int) $im['pages'])) : '')
                . '</small></td>'
                . '<td><small>' . implode('<br>', $boxes) . '</small></td>'
                . '<td><ul class="tqk-live-findings">' . implode('', array_map(static fn($i) => '<li class="is-warn">' . esc_html($i) . '</li>', $a['issues'])) . '</ul></td>'
                . '<td>' . self::fix_cell($a, $row['orig'], (string) $im['kind'], $pro) . '</td></tr>';
        }
        $state = $plan === []
            ? esc_html__('No files generated yet.', 'tqk-wp-seo')
            : esc_html(sprintf(
                __('%1$d image(s) ready · phone download %2$s → %3$s · %4$s', 'tqk-wp-seo'),
                count((array) ($plan['images'] ?? [])),
                self::fmt_bytes((int) ($plan['bytes_before'] ?? 0)),
                self::fmt_bytes((int) ($plan['bytes_after'] ?? 0)),
                !empty($plan['active']) ? __('ACTIVE on the site', 'tqk-wp-seo') : __('not active', 'tqk-wp-seo')
            ));
        $title = $scope === 'site'
            ? __('Site-wide images (logo, header, footer — on every page)', 'tqk-wp-seo')
            : (string) (get_post((int) $scope)->post_title ?? '#' . $scope);
        return '<details class="tqk-wpseo-pageissue tqk-img-page" data-page="' . esc_attr((string) $scope) . '"><summary class="tqk-wpseo-pageissue-head">'
            . esc_html($title) . ' <span class="tqk-rs-badge">' . count($rows) . '</span></summary>'
            . '<div class="tqk-wpseo-acc-body">'
            . '<p class="tqk-img-state">' . $state . '</p>'
            . ($has_art && $pro ? '<p class="description">' . esc_html__('Art-directed images are wrapped in <picture>. Check the page after activating: CSS written as "parent > img" no longer matches a wrapped image.', 'tqk-wp-seo') . '</p>' : '')
            . '<p class="tqk-la-buttons">'
            . ($pro
                ? '<button type="button" class="button button-primary" data-img-op="generate">' . esc_html__('Generate files', 'tqk-wp-seo') . '</button> '
                    . '<button type="button" class="button" data-img-op="activate"' . ($plan === [] || !empty($plan['active']) ? ' hidden' : '') . '>' . esc_html__('Activate on site', 'tqk-wp-seo') . '</button> '
                : '<span class="tqk-pro-note">' . esc_html__('Pro does these steps for you: resize, crop, compress, convert to WebP and serve through srcset, with one click.', 'tqk-wp-seo') . '</span> ')
            . '<button type="button" class="button" data-img-op="deactivate"' . (empty($plan['active']) ? ' hidden' : '') . '>' . esc_html__('Deactivate', 'tqk-wp-seo') . '</button> '
            . '<button type="button" class="button-link" data-img-op="purge"' . ($plan === [] ? ' hidden' : '') . '>' . esc_html__('Delete files', 'tqk-wp-seo') . '</button>'
            . '</p>'
            . '<div class="tqk-wpseo-table-wrap"><div class="table-responsive"><table class="tqk-wpseo-table"><thead><tr><th></th><th>' . esc_html__('Image', 'tqk-wp-seo') . '</th><th>' . esc_html__('Box per screen', 'tqk-wp-seo') . '</th><th>' . esc_html__('Issues', 'tqk-wp-seo') . '</th><th>' . esc_html__('Fix', 'tqk-wp-seo') . '</th></tr></thead><tbody>'
            . $html_rows . '</tbody></table></div></div></div></details>';
    }

    /** AJAX entry, routed from Admin\Ajax (nonce + capability already checked). */
    public static function ajax(): void {
        $scope = self::scope(sanitize_key((string) ($_POST['page_id'] ?? '')));
        $allowed = $scope === 'site' ? current_user_can('manage_options') : ($scope > 0 && current_user_can('edit_post', $scope));
        if (!$allowed) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wp-seo')]);
        }
        $op = sanitize_key($_POST['op'] ?? '');
        // Pro: making / switching on optimized files. Switching off and
        // deleting stay free, so nobody is ever stuck with files they paid for.
        if (in_array($op, ['generate', 'activate'], true) && !\TQK_WPSEO\Core\Pro::active()) {
            wp_send_json_error(['message' => __('Automatic image optimization is a Pro feature. The manual steps are listed under each image.', 'tqk-wp-seo')]);
        }
        $msg = '';
        if ($op === 'generate') {
            if (!current_user_can('upload_files')) {
                wp_send_json_error(['message' => __('Permission denied', 'tqk-wp-seo')]);
            }
            // Pro add-on: writes the files and saves the (inactive) plan.
            $r = apply_filters('tqk_wpseo_image_generate', null, $scope);
            if (!is_array($r)) {
                wp_send_json_error(['message' => __('Automatic image optimization is a Pro feature. The manual steps are listed under each image.', 'tqk-wp-seo')]);
            }
            $r += ['made' => 0, 'errors' => []];
            $msg = $r['errors'] !== [] ? implode(' ', array_unique($r['errors'])) : sprintf(__('%d file(s) generated. Check them, then activate.', 'tqk-wp-seo'), $r['made']);
        } elseif ($op === 'activate' || $op === 'deactivate') {
            self::set_active($scope, $op === 'activate');
            $msg = $op === 'activate' ? __('Active: visitors now get the new sizes.', 'tqk-wp-seo') : __('Deactivated.', 'tqk-wp-seo');
        } elseif ($op === 'purge') {
            self::purge($scope);
            $msg = __('Files deleted.', 'tqk-wp-seo');
        } else {
            wp_send_json_error(['message' => __('Unknown action', 'tqk-wp-seo')]);
        }
        wp_send_json_success(['message' => $msg, 'html' => self::page_html($scope)]);
    }
}
