<?php
namespace TQK_WPSEO\Security;

defined('ABSPATH') || exit;

/**
 * Malware & SEO-spam scan, from an SEO point of view.
 *
 * Not a firewall and not a Wordfence replacement: it looks for the damage
 * cracked "Pro" plugins and themes typically bring, and that directly hurts
 * rankings or trust:
 *   1. core integrity     : every core file vs WordPress.org checksums,
 *                           plus unknown PHP files dropped into wp-admin / wp-includes;
 *   2. plugin integrity   : WordPress.org plugins vs their official checksums;
 *   3. heuristics         : premium plugins, themes, mu-plugins (no checksums
 *                           exist) scanned for obfuscation / backdoor patterns
 *                           and "nulled" markers; any PHP inside uploads;
 *   4. admin accounts     : administrators created recently;
 *   5. cloaking / spam    : a page fetched as Googlebot vs as a visitor, and
 *                           hidden gambling/pharma links in the HTML.
 *
 * Runs as small steps over AJAX (one plugin / one chunk per request) so
 * shared hosting never times out. Read-only: it never deletes or edits a file.
 */
class Scan {

    public const STATE = 'tqk_wpseo_security_scan';
    public const CHUNK = 400;
    public const MAX_READ = 2 * 1024 * 1024;

    /** Signatures: [id, regex, severity, label]. Tuned for low noise over coverage. */
    public static function signatures(): array {
        return [
            ['eval_decode', '/\beval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|rawurldecode|hex2bin)\s*\(/i', 'critical', __('eval() of decoded data (classic backdoor)', 'tqk-wp-seo')],
            ['assert_input', '/\bassert\s*\(\s*\$_(?:POST|GET|REQUEST|COOKIE)/i', 'critical', __('assert() on request input', 'tqk-wp-seo')],
            ['exec_input', '/\b(?:shell_exec|passthru|system|proc_open|popen|exec)\s*\(\s*\$_(?:POST|GET|REQUEST|COOKIE)/i', 'critical', __('Shell command from request input', 'tqk-wp-seo')],
            ['call_input', '/\$_(?:POST|GET|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(/i', 'critical', __('Function name taken from request input', 'tqk-wp-seo')],
            ['preg_e', '/preg_replace\s*\(\s*([\'"])(.).{0,200}\2[imsx]*e[imsx]*\1/i', 'critical', __('preg_replace /e code execution', 'tqk-wp-seo')],
            ['write_input', '/\b(?:file_put_contents|fwrite)\s*\([^;]{0,120}\$_(?:POST|GET|REQUEST)/i', 'warning', __('Writes request input to a file', 'tqk-wp-seo')],
            ['long_b64', '/(?:base64_decode|gzinflate)\s*\(\s*[\'"][A-Za-z0-9+\/=]{800,}/', 'warning', __('Large encoded payload decoded at runtime', 'tqk-wp-seo')],
            ['hex_blob', '/(?:\\\\x[0-9a-f]{2}){40,}/i', 'warning', __('Long hex-escaped string (obfuscation)', 'tqk-wp-seo')],
            ['create_admin', '/wp_(?:insert|create)_user\s*\([^;]{0,300}administrator/is', 'critical', __('Creates an administrator account in code', 'tqk-wp-seo')],
            ['nulled', '/\b(?:nulled\s+by|wplocker|null24|babiato|gpl\s*pirate|warez|cracked\s+by)\b/i', 'warning', __('"Nulled" / cracked distribution marker', 'tqk-wp-seo')],
        ];
    }

    /** Gambling / pharma / adult words seen in injected SEO spam (VI + EN). */
    public static function spam_words(): array {
        return ['casino', 'viagra', 'cialis', 'porn', 'sex', 'slot gacor', 'togel', 'judi', 'bet88', 'nhà cái', 'nha cai', 'cá cược', 'ca cuoc',
            'tài xỉu', 'tai xiu', 'bắn cá', 'ban ca', 'nổ hũ', 'no hu', 'lô đề', 'lo de', 'soi cầu', 'soi cau', 'game bài đổi thưởng', 'kèo nhà cái'];
    }

    // ---------- pure checks (unit-tested) ----------

    /** @return list<array{id:string,severity:string,label:string,line:int}> */
    public static function scan_code(string $code): array {
        $hits = [];
        foreach (self::signatures() as [$id, $re, $sev, $label]) {
            if (preg_match($re, $code, $m, PREG_OFFSET_CAPTURE)) {
                $hits[] = ['id' => $id, 'severity' => $sev, 'label' => $label, 'line' => substr_count(substr($code, 0, (int) $m[0][1]), "\n") + 1];
            }
        }
        return $hits;
    }

    /**
     * Hidden outbound links carrying spam words: display:none / visibility
     * hidden / font-size:0 / far off-screen wrappers around <a href=external>.
     *
     * @return string[] Offending link texts/hosts.
     */
    public static function hidden_spam_links(string $html, string $home_host): array {
        $found = [];
        $hidden = '(?:display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0|(?:left|top|text-indent)\s*:\s*-\d{3,}px|height\s*:\s*0[^.]|opacity\s*:\s*0[;"\'])';
        if (preg_match_all('/<(div|span|p|section)\b[^>]*style\s*=\s*["\'][^"\']*' . $hidden . '[^"\']*["\'][^>]*>(.{0,4000}?)<\/\1>/is', $html, $blocks)) {
            foreach ($blocks[2] as $inner) {
                foreach (self::spam_links_in($inner, $home_host) as $l) {
                    $found[] = $l;
                }
            }
        }
        return array_values(array_unique(array_slice($found, 0, 20)));
    }

    /** External links whose text or URL contains a spam word. */
    public static function spam_links_in(string $html, string $home_host): array {
        $out = [];
        if (!preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($m as $a) {
            $host = strtolower((string) parse_url($a[1], PHP_URL_HOST));
            if ($host === '' || $host === strtolower($home_host)) {
                continue;
            }
            $hay = mb_strtolower(strip_tags($a[2]) . ' ' . $a[1]);
            foreach (self::spam_words() as $w) {
                // Whole words only: "sex" must not hit "Essex" or "unisex".
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $hay)) {
                    $out[] = $host . ' — ' . mb_substr(trim(strip_tags($a[2])), 0, 60);
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Compares what Googlebot gets with what a visitor gets. Cloaking shows
     * as a different title, extra external links, or spam words only the bot
     * sees. Small natural differences (nonces, timestamps) are ignored.
     *
     * @return string[] Reasons; [] when both look the same.
     */
    public static function compare_cloak(string $as_bot, string $as_user, string $home_host): array {
        $title = static fn($h) => preg_match('/<title[^>]*>(.*?)<\/title>/is', $h, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
        $ext = static function ($h) use ($home_host) {
            $hosts = [];
            if (preg_match_all('/<a\b[^>]*href\s*=\s*["\']https?:\/\/([^\/"\'?#]+)/i', $h, $m)) {
                foreach ($m[1] as $host) {
                    $host = strtolower($host);
                    if ($host !== strtolower($home_host)) {
                        $hosts[$host] = true;
                    }
                }
            }
            return array_keys($hosts);
        };
        $why = [];
        if ($title($as_bot) !== $title($as_user)) {
            $why[] = sprintf(__('Googlebot gets another <title>: "%s"', 'tqk-wp-seo'), mb_substr($title($as_bot), 0, 80));
        }
        $only_bot = array_diff($ext($as_bot), $ext($as_user));
        if ($only_bot !== []) {
            $why[] = sprintf(__('Links only Googlebot sees: %s', 'tqk-wp-seo'), implode(', ', array_slice($only_bot, 0, 6)));
        }
        $bot_spam = self::spam_links_in($as_bot, $home_host);
        $user_spam = self::spam_links_in($as_user, $home_host);
        if (count($bot_spam) > count($user_spam)) {
            $why[] = __('Spam links (gambling/pharma) only in the Googlebot version.', 'tqk-wp-seo');
        }
        $len_b = strlen(strip_tags($as_bot));
        $len_u = max(1, strlen(strip_tags($as_user)));
        if (abs($len_b - $len_u) / $len_u > 0.5 && abs($len_b - $len_u) > 3000) {
            $why[] = sprintf(__('Googlebot text is %d%% different in length.', 'tqk-wp-seo'), (int) round(100 * abs($len_b - $len_u) / $len_u));
        }
        return $why;
    }

    /**
     * "Silence is golden" style stubs that plugins drop into their folders at
     * runtime: small and with no PHP code at all (no open tag, or only
     * comments after it). They cannot execute anything.
     */
    public static function is_harmless_stub(string $code): bool {
        if (strlen($code) > 2048) {
            return false;
        }
        if (strpos($code, '<?') === false) {
            return true;
        }
        $rest = (string) preg_replace('#^\s*<\?php#i', '', trim($code));
        $rest = (string) preg_replace('#/\*.*?\*/|//[^\n]*|\#[^\n]*#s', '', $rest);
        return trim(str_replace('?>', '', $rest)) === '';
    }

    /** PHP-executable files have no business in uploads. */
    public static function is_executable_name(string $file): bool {
        return (bool) preg_match('/\.(php\d?|phtml|phar|pht|phps)$/i', $file);
    }

    // ---------- run state ----------

    public static function state(): array {
        $s = get_option(self::STATE, []);
        return is_array($s) ? $s : [];
    }

    private static function save(array $s): void {
        update_option(self::STATE, $s, false);
    }

    /** Builds the task list. Each task is one short request. */
    public static function start(): array {
        $tasks = [];
        $core = self::core_checksums();
        $n = is_array($core) ? count($core) : 0;
        for ($i = 0; $i < $n; $i += self::CHUNK) {
            $tasks[] = 'core:' . $i;
        }
        $tasks[] = 'core_extra';
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (array_keys((array) get_plugins()) as $file) {
            $tasks[] = 'plugin:' . $file;
        }
        foreach ((array) wp_get_themes() as $slug => $t) {
            $tasks[] = 'theme:' . $slug;
        }
        $tasks[] = 'mu';
        $tasks[] = 'uploads';
        $tasks[] = 'users';
        foreach (self::cloak_urls() as $u) {
            $tasks[] = 'cloak:' . $u;
        }
        $s = ['tasks' => $tasks, 'i' => 0, 'findings' => [], 'started' => time(), 'core_ok' => is_array($core), 'verified' => [], 'unverified' => []];
        self::save($s);
        return $s;
    }

    /** Homepage + a few recent posts/pages: where injected spam usually lands. */
    public static function cloak_urls(): array {
        $urls = [home_url('/')];
        foreach ((array) get_posts(['post_type' => ['post', 'page', 'product'], 'post_status' => 'publish', 'numberposts' => 6, 'orderby' => 'date', 'order' => 'DESC']) as $p) {
            $urls[] = (string) get_permalink($p);
        }
        return array_values(array_unique(array_filter($urls)));
    }

    /** @return array<string,string>|null file => md5, cached one day. */
    public static function core_checksums(): ?array {
        global $wp_version;
        $locale = function_exists('get_locale') ? get_locale() : 'en_US';
        $key = 'tqk_wpseo_core_sums_' . md5($wp_version . $locale);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        foreach ([$locale, 'en_US'] as $loc) {
            $res = wp_remote_get('https://api.wordpress.org/core/checksums/1.0/?' . http_build_query(['version' => $wp_version, 'locale' => $loc]), ['timeout' => 20]);
            $json = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
            $sums = $json['checksums'] ?? null;
            if (is_array($sums) && isset($sums[$wp_version])) {
                $sums = $sums[$wp_version];
            }
            if (is_array($sums) && $sums !== []) {
                set_transient($key, $sums, DAY_IN_SECONDS);
                return $sums;
            }
        }
        return null;
    }

    private static function add(array &$s, string $severity, string $where, string $what): void {
        if (count($s['findings']) < 500) {
            $s['findings'][] = ['sev' => $severity, 'where' => $where, 'what' => $what];
        }
    }

    /** Runs the next task. @return array state */
    public static function step(): array {
        $s = self::state();
        if (!isset($s['tasks'][$s['i'] ?? 0])) {
            return $s;
        }
        $task = (string) $s['tasks'][$s['i']];
        [$kind, $arg] = array_pad(explode(':', $task, 2), 2, '');
        match ($kind) {
            'core' => self::task_core($s, (int) $arg),
            'core_extra' => self::task_core_extra($s),
            'plugin' => self::task_plugin($s, $arg),
            'theme' => self::task_dir($s, get_theme_root() . '/' . $arg, 'theme ' . $arg),
            'mu' => defined('WPMU_PLUGIN_DIR') ? self::task_dir($s, WPMU_PLUGIN_DIR, 'mu-plugins') : null,
            'uploads' => self::task_uploads($s),
            'users' => self::task_users($s),
            'cloak' => self::task_cloak($s, $arg),
            default => null,
        };
        $s['i'] = (int) $s['i'] + 1;
        if ($s['i'] >= count($s['tasks'])) {
            $s['finished'] = time();
        }
        self::save($s);
        return $s;
    }

    private static function task_core(array &$s, int $from): void {
        $sums = self::core_checksums();
        if (!is_array($sums)) {
            return;
        }
        foreach (array_slice($sums, $from, self::CHUNK, true) as $file => $md5) {
            if (str_starts_with($file, 'wp-content/')) {
                continue; // themes/plugins shipped with core are allowed to change
            }
            $path = ABSPATH . $file;
            if (!file_exists($path)) {
                continue;
            }
            if (md5_file($path) !== $md5) {
                self::add($s, 'critical', $file, __('Core file differs from the official WordPress release.', 'tqk-wp-seo'));
            }
        }
    }

    private static function task_core_extra(array &$s): void {
        $sums = self::core_checksums();
        if (!is_array($sums)) {
            self::add($s, 'info', 'WordPress', __('Official core checksums unavailable for this version/locale: core was not verified.', 'tqk-wp-seo'));
            return;
        }
        foreach (['wp-admin', 'wp-includes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ABSPATH . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = $dir . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen(ABSPATH . $dir) + 1));
                if (self::is_executable_name($rel) && !isset($sums[$rel])) {
                    self::add($s, 'critical', $rel, __('PHP file that is not part of WordPress core.', 'tqk-wp-seo'));
                }
            }
        }
    }

    private static function task_plugin(array &$s, string $file): void {
        $slug = dirname($file);
        $dir = WP_PLUGIN_DIR . '/' . ($slug === '.' ? '' : $slug);
        $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
        $version = (string) ($data['Version'] ?? '');
        $sums = null;
        if ($slug !== '.' && $version !== '') {
            $res = wp_remote_get('https://downloads.wordpress.org/plugin-checksums/' . rawurlencode($slug) . '/' . rawurlencode($version) . '.json', ['timeout' => 15]);
            if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
                $json = json_decode((string) wp_remote_retrieve_body($res), true);
                $sums = is_array($json['files'] ?? null) ? $json['files'] : null;
            }
        }
        $name = (string) ($data['Name'] ?? $slug);
        if ($slug === '.') {
            // Single-file plugin (e.g. hello.php): scan that file only, never
            // the whole plugins folder.
            $s['unverified'][] = $name;
            foreach (self::scan_code((string) @file_get_contents(WP_PLUGIN_DIR . '/' . $file, false, null, 0, self::MAX_READ)) as $h) {
                self::add($s, $h['severity'], 'plugin ' . $name . ': ' . $file . ':' . $h['line'], $h['label']);
            }
            return;
        }
        if ($sums === null) {
            // Premium / custom plugin: no official checksums, use heuristics.
            $s['unverified'][] = $name;
            self::task_dir($s, $dir, 'plugin ' . $name);
            return;
        }
        $s['verified'][] = $name;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if (!self::is_executable_name($rel) && !preg_match('/\.js$/i', $rel)) {
                continue;
            }
            if (!isset($sums[$rel])) {
                if (self::is_executable_name($rel) && !self::is_harmless_stub((string) @file_get_contents($f->getPathname(), false, null, 0, 4096))) {
                    self::add($s, 'critical', 'plugin ' . $name . ': ' . $rel, __('PHP file not in the official release of this plugin.', 'tqk-wp-seo'));
                }
                continue;
            }
            $md5 = $sums[$rel]['md5'] ?? '';
            $ok = is_array($md5) ? in_array(md5_file($f->getPathname()), $md5, true) : md5_file($f->getPathname()) === $md5;
            if (!$ok) {
                self::add($s, 'critical', 'plugin ' . $name . ': ' . $rel, __('File modified compared with the official release.', 'tqk-wp-seo'));
            }
        }
    }

    /** Heuristic scan of every PHP file under a folder. */
    private static function task_dir(array &$s, string $dir, string $label): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $n = 0;
        $self = realpath(__FILE__);
        foreach ($it as $f) {
            if (!self::is_executable_name($f->getFilename()) || $f->getSize() > self::MAX_READ || ++$n > 3000) {
                continue;
            }
            if (realpath($f->getPathname()) === $self) {
                continue; // this file lists the signatures themselves
            }
            $code = (string) @file_get_contents($f->getPathname());
            foreach (self::scan_code($code) as $h) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
                self::add($s, $h['severity'], $label . ': ' . $rel . ':' . $h['line'], $h['label']);
            }
        }
    }

    private static function task_uploads(array &$s): void {
        $up = wp_upload_dir();
        $dir = (string) ($up['basedir'] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!self::is_executable_name($f->getFilename())) {
                continue;
            }
            $code = $f->getSize() < 4096 ? (string) @file_get_contents($f->getPathname()) : '';
            // Our own and core's "Silence is golden" index stubs are fine.
            if ($code !== '' && self::is_harmless_stub($code)) {
                continue;
            }
            $rel = 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            self::add($s, 'critical', $rel, __('Executable PHP file inside uploads (uploads should only hold media).', 'tqk-wp-seo'));
        }
    }

    private static function task_users(array &$s): void {
        $admins = get_users(['role' => 'administrator', 'fields' => ['ID', 'user_login', 'user_registered', 'user_email']]);
        foreach ((array) $admins as $u) {
            $age = time() - (int) strtotime((string) $u->user_registered);
            if ($age < 30 * DAY_IN_SECONDS) {
                self::add($s, 'warning', 'user ' . $u->user_login, sprintf(__('Administrator created %d day(s) ago — make sure you know this account.', 'tqk-wp-seo'), (int) floor($age / DAY_IN_SECONDS)));
            }
        }
        self::add($s, 'info', __('Administrators', 'tqk-wp-seo'), sprintf(__('%d administrator account(s) on this site.', 'tqk-wp-seo'), count((array) $admins)));
    }

    private static function task_cloak(array &$s, string $url): void {
        $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        $get = static fn(string $ua) => wp_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'headers' => ['User-Agent' => $ua, 'Cache-Control' => 'no-cache']]);
        $bot = $get('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $user = $get('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36');
        if (is_wp_error($bot) || is_wp_error($user)) {
            return;
        }
        $hb = (string) wp_remote_retrieve_body($bot);
        $hu = (string) wp_remote_retrieve_body($user);
        foreach (self::compare_cloak($hb, $hu, $host) as $why) {
            self::add($s, 'critical', $url, __('Cloaking: ', 'tqk-wp-seo') . $why);
        }
        foreach (self::hidden_spam_links($hu, $host) as $l) {
            self::add($s, 'critical', $url, sprintf(__('Hidden spam link: %s', 'tqk-wp-seo'), $l));
        }
    }

    /** AJAX entry, routed from Admin\Ajax (nonce + capability already checked). */
    public static function ajax(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wp-seo')]);
        }
        $op = sanitize_key($_POST['op'] ?? '');
        $s = $op === 'start' ? self::start() : ($op === 'step' ? self::step() : self::state());
        wp_send_json_success([
            'i' => (int) ($s['i'] ?? 0),
            'total' => count((array) ($s['tasks'] ?? [])),
            'task' => (string) ($s['tasks'][$s['i'] ?? 0] ?? ''),
            'done' => !empty($s['finished']),
            'html' => !empty($s['finished']) ? self::report_html($s) : '',
        ]);
    }

    public static function report_html(array $s): string {
        $f = (array) ($s['findings'] ?? []);
        $count = static fn(string $sev) => count(array_filter($f, static fn($x) => $x['sev'] === $sev));
        $crit = $count('critical');
        $html = '<div class="tqk-sec-summary ' . ($crit > 0 ? 'is-bad' : 'is-good') . '"><strong>'
            . esc_html($crit > 0
                ? sprintf(__('%d critical sign(s) of compromise found.', 'tqk-wp-seo'), $crit)
                : __('No sign of compromise found.', 'tqk-wp-seo'))
            . '</strong> ' . esc_html(sprintf(__('%1$d warning(s). Verified against official checksums: %2$s. Heuristics only (no official checksums): %3$s.', 'tqk-wp-seo'),
                $count('warning'),
                ($s['core_ok'] ?? false ? 'WordPress core, ' : '') . (implode(', ', (array) ($s['verified'] ?? [])) ?: '—'),
                implode(', ', (array) ($s['unverified'] ?? [])) ?: '—'))
            . '</div>';
        if ($f === []) {
            return $html;
        }
        usort($f, static fn($a, $b) => ['critical' => 0, 'warning' => 1, 'info' => 2][$a['sev']] <=> ['critical' => 0, 'warning' => 1, 'info' => 2][$b['sev']]);
        $rows = [];
        foreach ($f as $x) {
            $rows[] = [$x['sev'] === 'critical' ? '✗ ' . __('Critical', 'tqk-wp-seo') : ($x['sev'] === 'warning' ? '⚠ ' . __('Warning', 'tqk-wp-seo') : 'ℹ'), $x['where'], esc_html($x['what'])];
        }
        return $html . \TQK_WPSEO\Admin\View::issue_table([__('Level', 'tqk-wp-seo'), __('Where', 'tqk-wp-seo'), __('What', 'tqk-wp-seo')], $rows)
            . '<p class="description">' . esc_html__('Read-only scan: nothing was changed or deleted. A critical finding in a cracked plugin or theme means: remove it and install the official version, then change all admin passwords.', 'tqk-wp-seo') . '</p>';
    }
}
