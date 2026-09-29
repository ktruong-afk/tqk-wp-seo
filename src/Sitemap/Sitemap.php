<?php
namespace TQK_WPSEO\Sitemap;

defined('ABSPATH') || exit;

/**
 * S3 — XML sitemap (index + type maps) + robots.txt filter and editor storage.
 * Providers are filterable via tqk_wpseo_sitemap_items ($items, $type).
 */
class Sitemap {

    public const QUERY_VAR = 'tqk_wpseo_sitemap';
    public const SUBMIT_OPTION = 'tqk_wpseo_sitemap_submit';
    public const SUBMIT_HOOK = 'tqk_wpseo_sitemap_submit';

    public static function hooks(): void {
        // NOTE: this may run *during* init (via tqk_wpseo_init), when a nested
        // add_action('init') would silently never fire. Register directly then.
        if (function_exists('did_action') && did_action('init')) {
            self::rewrites();
        } else {
            add_action('init', [self::class, 'rewrites']);
        }
        add_action('template_redirect', [self::class, 'dispatch'], 1);
        add_action('save_post', [self::class, 'invalidate']);
        add_filter('robots_txt', [self::class, 'robots'], 10, 2);
        // One sitemap per site: core's /wp-sitemap.xml would list every URL
        // again (and defeat a private sitemap address).
        add_filter('wp_sitemaps_enabled', '__return_false');
        add_action('update_option_' . TQK_WPSEO_OPTION, [self::class, 'on_settings_change'], 10, 2);
        add_action(self::SUBMIT_HOOK, [self::class, 'submit']);
    }

    // ---------- private sitemap address ----------

    /**
     * Custom sitemap name ('' = the usual sitemap_index.xml). Obscurity only
     * hides the full URL list from people guessing /sitemap.xml; search
     * engines get it through Search Console / Bing directly.
     */
    public static function slug(): string {
        return self::clean_slug((string) \TQK_WPSEO\Admin\Settings::get('sitemap_slug', ''));
    }

    /** a-z, 0-9 and dashes, 6-40 chars, never a well-known sitemap name. */
    public static function clean_slug(string $s): string {
        $s = strtolower(trim($s));
        $s = (string) preg_replace('/\.xml$/', '', $s);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{4,38}[a-z0-9]$/', $s)) {
            return '';
        }
        return in_array($s, ['sitemap', 'sitemap_index', 'sitemap-index', 'wp-sitemap', 'sitemaps', 'sitemap-xml'], true) ? '' : $s;
    }

    public static function index_url(): string {
        $slug = self::slug();
        return home_url($slug !== '' ? '/' . $slug . '.xml' : '/sitemap_index.xml');
    }

    public static function map_url(string $type): string {
        $slug = self::slug();
        return home_url($slug !== '' ? '/' . $slug . '-' . $type . '.xml' : '/' . $type . '-sitemap.xml');
    }

    public static function xsl_url(): string {
        $slug = self::slug();
        return home_url($slug !== '' ? '/' . $slug . '.xsl' : '/sitemap.xsl');
    }

    /**
     * Private address → which sitemap ('' when the path is not one).
     * Matched on the path directly: no rewrite flush needed when renamed.
     */
    public static function match_private(string $path, string $slug): string {
        if ($slug === '') {
            return '';
        }
        $q = preg_quote($slug, '#');
        if (preg_match('#^/' . $q . '\.xml$#', $path)) {
            return 'index';
        }
        if (preg_match('#^/' . $q . '\.xsl$#', $path)) {
            return 'xsl';
        }
        if (preg_match('#^/' . $q . '-([a-z0-9_-]+)\.xml$#', $path, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Settings saved: a new address is announced, the old one withdrawn. */
    public static function on_settings_change($old, $new): void {
        $o = self::clean_slug((string) (is_array($old) ? ($old['sitemap_slug'] ?? '') : ''));
        $n = self::clean_slug((string) (is_array($new) ? ($new['sitemap_slug'] ?? '') : ''));
        if ($o === $n) {
            return;
        }
        self::invalidate();
        $old_url = home_url($o !== '' ? '/' . $o . '.xml' : '/sitemap_index.xml');
        update_option('tqk_wpseo_sitemap_previous', $old_url, false);
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 5, self::SUBMIT_HOOK);
        }
    }

    /**
     * Sends the sitemap to Google Search Console (API) and Bing Webmaster,
     * and withdraws the previous address from Search Console.
     * Google's old anonymous "ping" URL no longer exists (retired 2023).
     *
     * @return array{google:array, bing:array, url:string, at:int}
     */
    public static function submit(): array {
        $url = self::index_url();
        $old = (string) get_option('tqk_wpseo_sitemap_previous', '');
        $res = [
            'url' => $url,
            'at' => time(),
            'google' => \TQK_WPSEO\Google\SearchConsole::submit_sitemap($url, $old !== $url ? $old : ''),
            'bing' => \TQK_WPSEO\Indexing\Submitter::bing_sitemap($url),
        ];
        update_option(self::SUBMIT_OPTION, $res, false);
        delete_option('tqk_wpseo_sitemap_previous');
        return $res;
    }

    public static function invalidate(int $post_id = 0): void {
        delete_transient('tqk_wpseo_sitemap_index');
        foreach (array_keys(self::maps()) as $type) {
            delete_transient('tqk_wpseo_sitemap_' . $type);
        }
        delete_transient('tqk_wpseo_sitemap_news');
        delete_transient('tqk_wpseo_sitemap_video');
        delete_transient('tqk_wpseo_sitemap_xsl');
        delete_transient('tqk_wpseo_llms');
    }

    public static function rewrites(): void {
        add_rewrite_tag('%' . self::QUERY_VAR . '%', '([^&]+)');
        add_rewrite_rule('^sitemap_index\.xml$', 'index.php?' . self::QUERY_VAR . '=index', 'top');
        add_rewrite_rule('^([a-z0-9_-]+)-sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^sitemap\.xsl$', 'index.php?' . self::QUERY_VAR . '=xsl', 'top');
        add_rewrite_rule('^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=llms', 'top');
    }

    public static function dispatch(): void {
        $slug = self::slug();
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $private = self::match_private($path, $slug);
        if ($private !== '') {
            self::output($private);
            exit;
        }
        $which = get_query_var(self::QUERY_VAR);
        if ($which === '' || $which === false) {
            return;
        }
        if ($slug !== '' && $which !== 'llms') {
            // Renamed: the well-known addresses are an ordinary 404 page,
            // with no hint that a sitemap exists elsewhere.
            global $wp_query;
            if (isset($wp_query) && method_exists($wp_query, 'set_404')) {
                $wp_query->set_404();
            }
            status_header(404);
            return;
        }
        self::output((string) $which);
        exit;
    }

    public static function output(string $which): void {
        if (!\TQK_WPSEO\Core\Modules::is_active('sitemap')
            || !\TQK_WPSEO\Admin\Settings::get('sitemap_enabled', true)) {
            status_header(404);
            return;
        }
        if ($which === 'llms') {
            if (!headers_sent()) {
                header('Content-Type: text/markdown; charset=UTF-8');
            }
            status_header(200);
            echo self::render_llms(); // phpcs:ignore
            return;
        }
        if ($which === 'xsl') {
            if (!headers_sent()) {
                header('Content-Type: text/xsl; charset=UTF-8');
            }
            status_header(200);
            echo self::cached_xsl(); // phpcs:ignore
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/xml; charset=UTF-8');
        }
        status_header(200);
        if ($which === 'index') {
            echo self::cached_index(); // phpcs:ignore
            return;
        }
        if ($which === 'news' || $which === 'video') {
            echo self::cached_special($which); // phpcs:ignore
            return;
        }
        echo self::cached_map($which); // phpcs:ignore
    }

    public static function cached_index(): string {
        $cached = get_transient('tqk_wpseo_sitemap_index');
        if (is_string($cached)) {
            return $cached;
        }
        $xml = self::render_index();
        set_transient('tqk_wpseo_sitemap_index', $xml, 12 * HOUR_IN_SECONDS);
        return $xml;
    }

    public static function cached_map(string $type): string {
        $cached = get_transient('tqk_wpseo_sitemap_' . $type);
        if (is_string($cached)) {
            return $cached;
        }
        $xml = self::render_map($type);
        set_transient('tqk_wpseo_sitemap_' . $type, $xml, 12 * HOUR_IN_SECONDS);
        return $xml;
    }

    public static function cached_special(string $type): string {
        $cached = get_transient('tqk_wpseo_sitemap_' . $type);
        if (is_string($cached)) {
            return $cached;
        }
        $xml = $type === 'news' ? self::render_news() : self::render_video();
        set_transient('tqk_wpseo_sitemap_' . $type, $xml, 12 * HOUR_IN_SECONDS);
        return $xml;
    }

    public static function cached_xsl(): string {
        $cached = get_transient('tqk_wpseo_sitemap_xsl');
        if (is_string($cached)) {
            return $cached;
        }
        $xml = self::render_xsl();
        set_transient('tqk_wpseo_sitemap_xsl', $xml, 12 * HOUR_IN_SECONDS);
        return $xml;
    }

    /** llms.txt: site summary + key pages for AI agents. */
    public static function render_llms(): string {
        $cached = get_transient('tqk_wpseo_llms');
        if (is_string($cached)) {
            return $cached;
        }
        $lines = ['# ' . get_bloginfo('name'), '', (string) get_bloginfo('description'), ''];
        foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => ['publish'], 'numberposts' => 20]) as $p) {
            $lines[] = '- [' . $p->post_title . '](' . get_permalink($p) . ')';
        }
        if (self::slug() === '') {
            // A private sitemap address is never published anywhere.
            $lines[] = '';
            $lines[] = 'Sitemap: ' . self::index_url();
        }
        $out = implode("\n", $lines) . "\n";
        set_transient('tqk_wpseo_llms', $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }

    /** @return string[] Map key => label (respects saved selection). */
    public static function maps(): array {
        $all = [
            'post' => __('Posts', 'tqk-wpseo'),
            'page' => __('Pages', 'tqk-wpseo'),
            'category' => __('Categories', 'tqk-wpseo'),
            'post_tag' => __('Tags', 'tqk-wpseo'),
        ];
        $saved = get_option(TQK_WPSEO_OPTION, []);
        $sel = is_array($saved) && isset($saved['sitemap_maps']) ? (array) $saved['sitemap_maps'] : array_keys($all);
        return array_intersect_key($all, array_flip($sel));
    }

    /** @return array List of [loc, lastmod]. Respects per-post sitemap exclude. */
    public static function items(string $type): array {
        $items = [];
        if ($type === 'post' || $type === 'page') {
            foreach (get_posts(['post_type' => [$type], 'post_status' => ['publish'], 'numberposts' => 1000]) as $p) {
                if ((string) get_post_meta((int) $p->ID, '_tqk_wpseo_sitemap_exclude', true) === '1') {
                    continue;
                }
                $items[] = [(string) get_permalink($p), substr((string) ($p->post_modified ?? ''), 0, 10)];
            }
        } else {
            foreach (get_terms(['taxonomy' => [$type], 'hide_empty' => true]) as $t) {
                $items[] = [(string) get_term_link($t), ''];
            }
        }
        return (array) apply_filters('tqk_wpseo_sitemap_items', $items, $type);
    }

    public static function render_map(string $type): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url(self::xsl_url()) . '"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::items($type) as [$loc, $lastmod]) {
            $xml .= '  <url><loc>' . esc_url($loc) . '</loc>';
            if ($lastmod !== '') {
                $xml .= '<lastmod>' . esc_html($lastmod) . '</lastmod>';
            }
            $xml .= "</url>\n";
        }
        return $xml . '</urlset>';
    }

    public static function render_index(): string {        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url(self::xsl_url()) . '"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (array_keys(self::maps()) as $type) {
            $xml .= '  <sitemap><loc>' . esc_url(self::map_url($type)) . "</loc></sitemap>\n";
        }
        foreach (self::special_maps() as $type => $label) {
            $xml .= '  <sitemap><loc>' . esc_url(self::map_url($type)) . "</loc></sitemap>\n";
        }
        return $xml . '</sitemapindex>';
    }

    /** @return string[] Special maps (news/video) enabled in selection. */
    public static function special_maps(): array {
        $all = [
            'news' => __('News', 'tqk-wpseo'),
            'video' => __('Video', 'tqk-wpseo'),
        ];
        $saved = get_option(TQK_WPSEO_OPTION, []);
        $sel = is_array($saved) && isset($saved['sitemap_maps']) ? (array) $saved['sitemap_maps'] : [];
        return array_intersect_key($all, array_flip($sel));
    }

    public static function publication_name(): string {
        $biz = trim((string) \TQK_WPSEO\Admin\Settings::get('business_name', ''));
        return $biz !== '' ? $biz : (string) get_bloginfo('name');
    }

    private static function news_lang(): string {
        $loc = function_exists('get_locale') ? (string) get_locale() : 'en_US';
        $lang = strtolower(substr($loc, 0, 2));
        return $lang !== '' ? $lang : 'en';
    }

    /** @return array List of [loc, title, date]. Posts from the last 48h only. */
    public static function news_items(): array {
        $items = [];
        $cutoff = time() - 48 * 3600;
        foreach (get_posts(['post_type' => ['post'], 'post_status' => ['publish'], 'numberposts' => 200]) as $p) {
            if ((string) get_post_meta((int) $p->ID, '_tqk_wpseo_sitemap_exclude', true) === '1') {
                continue;
            }
            $ts = strtotime((string) ($p->post_date ?? ''));
            if ($ts === false || $ts < $cutoff) {
                continue;
            }
            $items[] = [(string) get_permalink($p), (string) $p->post_title, gmdate('Y-m-d', $ts)];
        }
        return (array) apply_filters('tqk_wpseo_sitemap_news_items', $items);
    }

    public static function render_news(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url(self::xsl_url()) . '"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";
        foreach (self::news_items() as [$loc, $title, $date]) {
            $xml .= '  <url><loc>' . esc_url($loc) . '</loc><news:news><news:publication>'
                . '<news:name>' . esc_html(self::publication_name()) . '</news:name>'
                . '<news:language>' . esc_html(self::news_lang()) . '</news:language>'
                . '</news:publication><news:publication_date>' . esc_html($date) . '</news:publication_date>'
                . '<news:title>' . esc_html($title) . '</news:title>'
                . "</news:news></url>\n";
        }
        return $xml . '</urlset>';
    }

    /**
     * @return array List of [loc, title, desc, thumb, player]. Scans content
     * for <video> and YouTube/Vimeo embeds.
     */
    public static function video_items(): array {
        $items = [];
        $pts = function_exists('get_post_types') ? array_values((array) get_post_types(['public' => true])) : ['post', 'page'];
        $pts = array_values(array_filter(array_unique(array_merge(['post', 'page'], $pts)), fn($t) => $t !== 'attachment'));
        foreach (get_posts(['post_type' => $pts, 'post_status' => ['publish'], 'numberposts' => 500]) as $p) {
            if ((string) get_post_meta((int) $p->ID, '_tqk_wpseo_sitemap_exclude', true) === '1') {
                continue;
            }
            $html = (string) ($p->post_content ?? '');
            foreach (self::extract_videos($html) as $v) {
                $items[] = [
                    (string) get_permalink($p),
                    (string) $p->post_title,
                    mb_substr((string) ($p->post_excerpt ?? $p->post_title), 0, 200),
                    (string) ($v['thumb'] ?? ''),
                    (string) ($v['player'] ?? ''),
                ];
            }
        }
        return (array) apply_filters('tqk_wpseo_sitemap_video_items', $items);
    }

    /** @return array List of [thumb, player] found in HTML. */
    public static function extract_videos(string $html): array {
        $out = [];
        if (preg_match_all('/<video\b[^>]*>(.*?)<\/video>|<video\b[^>]*src\s*=\s*["\']([^"\']+)["\'][^>]*>/is', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $src = $m[2] ?? '';
                if ($src === '' && preg_match('/<source\b[^>]*src\s*=\s*["\']([^"\']+)["\']/i', $m[1] ?? '', $sm)) {
                    $src = $sm[1];
                }
                if ($src !== '') {
                    $out[] = ['thumb' => '', 'player' => $src];
                }
            }
        }
        if (preg_match_all('/<iframe\b[^>]*src\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $html, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $m) {
                $src = $m[1];
                if (str_contains($src, 'youtube.com') || str_contains($src, 'youtu.be') || str_contains($src, 'vimeo.com')) {
                    $out[] = ['thumb' => '', 'player' => $src];
                }
            }
        }
        return $out;
    }

    public static function render_video(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . esc_url(self::xsl_url()) . '"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";
        foreach (self::video_items() as [$loc, $title, $desc, $thumb, $player]) {
            $xml .= '  <url><loc>' . esc_url($loc) . '</loc><video:video>'
                . '<video:title>' . esc_html(mb_substr($title, 0, 100)) . '</video:title>'
                . '<video:description>' . esc_html($desc) . '</video:description>';
            if ($thumb !== '') {
                $xml .= '<video:thumbnail_loc>' . esc_url($thumb) . '</video:thumbnail_loc>';
            }
            if ($player !== '') {
                $xml .= '<video:player_loc>' . esc_url($player) . '</video:player_loc>';
            }
            $xml .= "</video:video></url>\n";
        }
        return $xml . '</urlset>';
    }

    public static function render_xsl(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . '<xsl:output method="html" encoding="UTF-8"/>' . "\n"
            . '<xsl:template match="/"><html><head><title>' . esc_html__('XML Sitemap', 'tqk-wpseo') . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:900px;margin:40px auto;padding:0 20px;color:#0f172a}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #e2e8f0}th{color:#64748b;text-transform:uppercase;font-size:11px;letter-spacing:.06em}a{color:#4f46e5}</style>'
            . '</head><body><h1>' . esc_html__('XML Sitemap', 'tqk-wpseo') . '</h1>'
            . '<table><tr><th>URL</th></tr>'
            . '<xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap"><tr><td><a><xsl:attribute name="href"><xsl:value-of select="sitemap:loc"/></xsl:attribute><xsl:value-of select="sitemap:loc"/></a></td></tr></xsl:for-each>'
            . '<xsl:for-each select="sitemap:urlset/sitemap:url"><tr><td><a><xsl:attribute name="href"><xsl:value-of select="sitemap:loc"/></xsl:attribute><xsl:value-of select="sitemap:loc"/></a></td></tr></xsl:for-each>'
            . '</table></body></html></xsl:template></xsl:stylesheet>';
    }

    // ---------- robots.txt ----------

    public const ROBOTS_OPTION = 'tqk_wpseo_robots_txt';

    public static function default_robots(): string {
        return "User-agent: *\nDisallow:\n" . (self::slug() === '' ? "\nSitemap: " . self::index_url() . "\n" : '');
    }

    public static function robots(string $output, bool $public): string {
        if (!$public) {
            return "User-agent: *\nDisallow: /\n";
        }
        $custom = get_option(self::ROBOTS_OPTION, '');
        $out = is_string($custom) && trim($custom) !== '' ? $custom : $output;
        return self::robots_sitemap_line($out, self::slug(), self::index_url());
    }

    /**
     * Private address: remove every "Sitemap:" line (robots.txt is public).
     * Usual address: make sure ours is listed (core's line disappears with
     * core sitemaps turned off).
     */
    public static function robots_sitemap_line(string $robots, string $slug, string $index_url): string {
        $lines = preg_split('/\r\n|\n/', $robots) ?: [];
        $lines = array_values(array_filter($lines, static fn($l) => stripos(trim($l), 'sitemap:') !== 0));
        $out = rtrim(implode("\n", $lines));
        if ($slug === '') {
            $out .= "\n\nSitemap: " . $index_url;
        }
        return $out . "\n";
    }

    /** @return string[] Warnings (empty = valid). Blocks whole site = warning. */
    public static function validate_robots(string $content): array {
        $warnings = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (stripos($line, 'disallow:') === 0 && trim(substr($line, 9)) === '/') {
                $warnings[] = __('This blocks the entire site from search engines.', 'tqk-wpseo');
                break;
            }
        }
        if (stripos($content, 'user-agent:') === false) {
            $warnings[] = __('Missing User-agent directive.', 'tqk-wpseo');
        }
        return $warnings;
    }
}
