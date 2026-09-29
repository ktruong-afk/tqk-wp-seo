<?php
namespace TQK_WPSEO\Security;

defined('ABSPATH') || exit;

/**
 * Malware check of ONE page, on its real HTML, run with the editor's Scan.
 *
 * SEO malware usually hides from logged-in admins and shows itself only to
 * Googlebot or to people arriving from Google. So the page is fetched by the
 * server four times and the versions are compared:
 *   admin    — with the current user's login cookies (what the admin sees),
 *   visitor  — a normal browser, not logged in,
 *   referred — the same visitor arriving from a Google result,
 *   googlebot— Google's crawler.
 * Then every script of the visitor version is listed with where it comes
 * from (a plugin, the theme, core, a known service or an unknown domain), and
 * inline scripts are checked for obfuscation and referrer/device redirects.
 *
 * Read-only. Only the post's own URL is fetched (never a URL from the request).
 */
class PageCheck {

    public const UA_BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
    public const UA_GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /** Services commonly embedded on Vietnamese sites: listed, not flagged. */
    public const KNOWN = [
        'googletagmanager.com', 'google-analytics.com', 'gstatic.com', 'google.com', 'googleapis.com', 'googlesyndication.com',
        'googleadservices.com', 'doubleclick.net', 'recaptcha.net', 'youtube.com', 'ytimg.com', 'facebook.net', 'facebook.com',
        'zalo.me', 'zaloapp.com', 'zadn.vn', 'tiktok.com', 'ttwstatic.com', 'clarity.ms', 'hotjar.com', 'tawk.to', 'subiz.com',
        'subiz.net', 'fchat.vn', 'ahachat.com', 'jsdelivr.net', 'cloudflare.com', 'cloudflareinsights.com', 'unpkg.com',
        'jquery.com', 'bootstrapcdn.com', 'fontawesome.com', 'typekit.net', 'wp.com', 'wordpress.com', 'gravatar.com',
        'stripe.com', 'paypal.com', 'vnpay.vn', 'momo.vn', 'onesignal.com', 'cookieyes.com', 'elfsight.com', 'trustindex.io',
        'vimeo.com', 'sharethis.com', 'addthis.com', 'mailchimp.com', 'hubspot.com', 'intercom.io', 'crisp.chat', 'livechatinc.com',
    ];

    /** Script hosts that are always bad (in-browser miners, known injectors). */
    public const BAD = ['coinhive.com', 'coin-hive.com', 'crypto-loot.com', 'cryptoloot.pro', 'jsecoin.com', 'minero.cc', 'webminepool.com'];

    // ---------- pure checks (unit-tested) ----------

    public static function host_of(string $url, string $home_host): string {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        $h = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $h === '' ? strtolower($home_host) : $h;
    }

    private static function matches(string $host, array $list): bool {
        foreach ($list as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every <script src>, with its origin: plugin:{slug}, theme:{slug}, core,
     * site (other local file), known (a common service), bad, or unknown.
     *
     * @return list<array{src:string,host:string,origin:string,kind:string}>
     */
    public static function scripts(string $html, string $home_host): array {
        $out = [];
        if (!preg_match_all('/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            return [];
        }
        $home = strtolower((string) preg_replace('/^www\./', '', $home_host));
        foreach (array_unique($m[1]) as $src) {
            $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
            $host = self::host_of($src, $home_host);
            $bare = (string) preg_replace('/^www\./', '', $host);
            if ($bare === $home) {
                $path = (string) parse_url($src, PHP_URL_PATH);
                if (preg_match('#/wp-content/plugins/([^/]+)/#', $path, $pm)) {
                    $origin = 'plugin:' . $pm[1];
                } elseif (preg_match('#/wp-content/themes/([^/]+)/#', $path, $tm)) {
                    $origin = 'theme:' . $tm[1];
                } elseif (preg_match('#/wp-(?:includes|admin)/#', $path)) {
                    $origin = 'core';
                } elseif (preg_match('#/wp-content/uploads/#', $path)) {
                    $origin = 'uploads';
                } else {
                    $origin = 'site';
                }
                // Scripts are never uploads: a .js in uploads is a planted file.
                $kind = $origin === 'uploads' ? 'unknown' : 'local';
            } elseif (self::matches($bare, self::BAD)) {
                $origin = $bare;
                $kind = 'bad';
            } elseif (self::matches($bare, self::KNOWN)) {
                $origin = $bare;
                $kind = 'known';
            } else {
                $origin = $bare;
                $kind = 'unknown';
            }
            $out[] = ['src' => mb_substr($src, 0, 300), 'host' => $bare, 'origin' => $origin, 'kind' => $kind];
        }
        return $out;
    }

    /**
     * Suspicious patterns in inline JavaScript.
     *
     * @return list<array{id:string,severity:string}>
     */
    public static function inline_flags(string $js): array {
        $f = [];
        $add = static function (string $id, string $sev) use (&$f) {
            $f[] = ['id' => $id, 'severity' => $sev];
        };
        $redirect = '/(?:window\.|document\.|top\.)?location(?:\.href)?\s*=|location\.(?:replace|assign)\s*\(/';
        if (preg_match('/\beval\s*\(\s*(?:atob|unescape|decodeURIComponent|String\.fromCharCode)\s*\(/', $js)) {
            $add('eval_decode', 'critical');
        } elseif (preg_match('/\beval\s*\(/', $js) && preg_match('/\b(?:atob|unescape)\s*\(/', $js)) {
            $add('eval_decode', 'critical');
        }
        if (preg_match('/String\.fromCharCode\s*\(\s*(?:\d{2,3}\s*,\s*){20,}/', $js)) {
            $add('charcode', 'critical');
        }
        if (preg_match('/document\.write\s*\(\s*(?:unescape\s*\(|atob\s*\(|["\']\s*<\s*script)/i', $js)) {
            $add('write_script', 'critical');
        }
        if (preg_match('/document\.referrer/', $js) && preg_match($redirect, $js)) {
            $add('referrer_redirect', 'critical');
        }
        if (preg_match('/navigator\.userAgent/', $js) && preg_match($redirect, $js) && preg_match('/(?:android|iphone|mobile|bot|google)/i', $js)) {
            $add('device_redirect', 'warning');
        }
        if (preg_match('/(?:\\\\x[0-9a-f]{2}){30,}/i', $js)) {
            $add('hex', 'warning');
        }
        if (preg_match('/["\'][A-Za-z0-9+\/]{400,}={0,2}["\']/', $js) && !preg_match('/data:(?:image|font|application\/font)/', $js)) {
            $add('base64', 'warning');
        }
        if (preg_match('/createElement\s*\(\s*["\']script["\']\s*\)/', $js) && preg_match('/\.src\s*=\s*(?:atob|unescape|String\.fromCharCode|[^;]{0,40}\+\s*["\'])/', $js)) {
            $add('built_src', 'warning');
        }
        foreach (self::BAD as $d) {
            if (stripos($js, $d) !== false) {
                $add('miner', 'critical');
                break;
            }
        }
        return $f;
    }

    /**
     * Inline <script> blocks worth a look (JSON data blocks are skipped).
     *
     * @return list<array{index:int,size:int,flags:array,sample:string}>
     */
    public static function inline_scripts(string $html): array {
        $out = [];
        if (!preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($m as $i => $s) {
            if (preg_match('/\bsrc\s*=/i', $s[1]) || preg_match('/type\s*=\s*["\']?(?:application\/(?:ld\+)?json|text\/template|text\/x-template)/i', $s[1])) {
                continue;
            }
            $js = trim($s[2]);
            if ($js === '') {
                continue;
            }
            $flags = self::inline_flags($js);
            $out[] = ['index' => $i, 'size' => strlen($js), 'flags' => $flags, 'sample' => mb_substr(preg_replace('/\s+/', ' ', $js), 0, 160)];
        }
        return $out;
    }

    /** Hidden iframes pointing elsewhere (a classic drive-by injection). */
    public static function hidden_iframes(string $html, string $home_host): array {
        $out = [];
        if (!preg_match_all('/<iframe\b[^>]*>/i', $html, $m)) {
            return [];
        }
        foreach ($m[0] as $tag) {
            if (!preg_match('/src\s*=\s*["\']([^"\']+)/i', $tag, $sm)) {
                continue;
            }
            $host = self::host_of($sm[1], $home_host);
            $hidden = preg_match('/(?:width|height)\s*=\s*["\']?[01]["\'\s>]/i', $tag)
                || preg_match('/style\s*=\s*["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden|(?:width|height)\s*:\s*[01]px)/i', $tag);
            if ($hidden && $host !== strtolower($home_host)) {
                $out[] = $host;
            }
        }
        return array_values(array_unique($out));
    }

    private static function ucfirst(string $s): string {
        return function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1) : ucfirst($s);
    }

    /** External link hosts of a page (other than the site itself). */
    private static function link_hosts(string $html, string $home_host): array {
        $hosts = [];
        $home = strtolower((string) preg_replace('/^www\./', '', $home_host));
        if (preg_match_all('/<a\b[^>]*href\s*=\s*["\']https?:\/\/([^\/"\'?#:]+)/i', $html, $m)) {
            foreach ($m[1] as $h) {
                $h = strtolower((string) preg_replace('/^www\./', '', $h));
                if ($h !== $home) {
                    $hosts[$h] = true;
                }
            }
        }
        return array_keys($hosts);
    }

    /**
     * What page A shows that page B does not: another title, extra outbound
     * link domains, spam links, a very different amount of text.
     *
     * @return string[] Reasons, [] when they match.
     */
    public static function diff(string $a, string $b, string $home_host, bool $full = true): array {
        $why = [];
        $title = static fn($h) => preg_match('/<title[^>]*>(.*?)<\/title>/is', $h, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
        if ($full && $title($a) !== $title($b)) {
            $why[] = sprintf(__('another title: "%s"', 'tqk-wpseo'), mb_substr($title($a), 0, 80));
        }
        $extra = array_diff(self::link_hosts($a, $home_host), self::link_hosts($b, $home_host));
        $extra = array_values(array_filter($extra, static fn($h) => !self::matches($h, self::KNOWN)));
        if ($extra !== []) {
            $why[] = sprintf(__('extra links to %s', 'tqk-wpseo'), implode(', ', array_slice($extra, 0, 6)));
        }
        if (count(Scan::spam_links_in($a, $home_host)) > count(Scan::spam_links_in($b, $home_host))) {
            $why[] = __('gambling / pharma links', 'tqk-wpseo');
        }
        if ($full) {
            $la = strlen(trim(strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $a))));
            $lb = max(1, strlen(trim(strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $b)))));
            if (abs($la - $lb) / $lb > 0.5 && abs($la - $lb) > 3000) {
                $why[] = sprintf(__('%d%% more or less text', 'tqk-wpseo'), (int) round(100 * abs($la - $lb) / $lb));
            }
        }
        return $why;
    }

    /** Location of a redirect response, '' when the page answered itself. */
    public static function redirect_of(array $res): string {
        $code = (int) ($res['code'] ?? 0);
        return $code >= 300 && $code < 400 ? (string) ($res['location'] ?? '') : '';
    }

    // ---------- the check ----------

    /** Login cookies of the current request, to fetch the page as this admin. */
    private static function login_cookies(): string {
        $pairs = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($v) && preg_match('/^wordpress_(?:logged_in_|sec_|[0-9a-f]{32})/', (string) $k)) {
                $pairs[] = $k . '=' . rawurlencode(wp_unslash($v));
            }
        }
        return implode('; ', $pairs);
    }

    /** @return array{code:int,location:string,body:string,error:string} */
    private static function fetch(string $url, array $headers): array {
        $r = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
            'headers' => $headers + ['Cache-Control' => 'no-cache'],
        ]);
        if (is_wp_error($r)) {
            return ['code' => 0, 'location' => '', 'body' => '', 'error' => $r->get_error_message()];
        }
        $h = wp_remote_retrieve_headers($r);
        $loc = is_array($h) ? (string) ($h['location'] ?? '') : (string) ($h['location'] ?? '');
        return ['code' => (int) wp_remote_retrieve_response_code($r), 'location' => $loc, 'body' => (string) wp_remote_retrieve_body($r), 'error' => ''];
    }

    /** Runs the whole check for one post. */
    public static function run(int $post_id): array {
        $home_host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        $public = get_post_status($post_id) === 'publish';
        $url = $public ? (string) get_permalink($post_id) : (string) get_preview_post_link($post_id);
        $v = ['admin' => self::fetch($url, ['User-Agent' => self::UA_BROWSER, 'Cookie' => self::login_cookies()])];
        if ($public) {
            $v['visitor'] = self::fetch($url, ['User-Agent' => self::UA_BROWSER]);
            $v['referred'] = self::fetch($url, ['User-Agent' => self::UA_BROWSER, 'Referer' => 'https://www.google.com/']);
            $v['googlebot'] = self::fetch($url, ['User-Agent' => self::UA_GOOGLEBOT]);
        }
        return self::evaluate($v, $home_host, $public);
    }

    /**
     * Pure part of the check: compares fetched versions and audits scripts.
     *
     * @param array<string,array{code:int,location:string,body:string,error:string}> $v
     */
    public static function evaluate(array $v, string $home_host, bool $public): array {
        $checks = [];
        $add = static function (string $sev, string $msg) use (&$checks) {
            $checks[] = ['sev' => $sev, 'msg' => $msg];
        };
        $names = [
            'admin' => __('you (logged in)', 'tqk-wpseo'),
            'visitor' => __('a visitor', 'tqk-wpseo'),
            'referred' => __('a visitor coming from Google', 'tqk-wpseo'),
            'googlebot' => __('Googlebot', 'tqk-wpseo'),
        ];
        $base = $v['visitor'] ?? $v['admin'];

        if ($public) {
            // Redirects that only some audiences get.
            foreach (['referred', 'googlebot'] as $who) {
                $to = self::redirect_of($v[$who] ?? []);
                if ($to !== '' && $to !== self::redirect_of($base)) {
                    $sev = self::host_of($to, $home_host) !== strtolower($home_host) ? 'critical' : 'warning';
                    $add($sev, sprintf(__('Only %1$s is redirected, to %2$s', 'tqk-wpseo'), $names[$who], $to));
                }
            }
            // Content that differs by audience.
            foreach (['googlebot' => 'visitor', 'referred' => 'visitor', 'visitor' => 'admin'] as $a => $b) {
                if (empty($v[$a]['body']) || empty($v[$b]['body'])) {
                    continue;
                }
                // Against the admin version only links count: the admin page
                // legitimately carries the admin bar and builder tools.
                foreach (self::diff($v[$a]['body'], $v[$b]['body'], $home_host, $b !== 'admin') as $why) {
                    $add($b === 'admin' ? 'warning' : 'critical', sprintf(__('%1$s gets a different page than %2$s: %3$s', 'tqk-wpseo'), self::ucfirst($names[$a]), $names[$b], $why));
                }
            }
            // Scripts only some audiences get.
            $srcs = static fn($h) => array_column(self::scripts($h, $home_host), 'host', 'src');
            if (!empty($v['visitor']['body']) && !empty($v['admin']['body'])) {
                $extra = array_diff_key($srcs($v['visitor']['body']), $srcs($v['admin']['body']));
                $extra = array_filter($extra, static fn($h) => !self::matches($h, self::KNOWN) && $h !== strtolower((string) preg_replace('/^www\./', '', $home_host)));
                if ($extra !== []) {
                    // Warning, not critical: analytics are often switched off for admins.
                    $add('warning', sprintf(__('Scripts that visitors get but you (logged in) do not: %s', 'tqk-wpseo'), implode(', ', array_slice(array_unique(array_values($extra)), 0, 5))));
                }
            }
        }

        $html = (string) ($base['body'] ?? '');
        foreach (Scan::hidden_spam_links($html, $home_host) as $l) {
            $add('critical', sprintf(__('Hidden spam link: %s', 'tqk-wpseo'), $l));
        }
        foreach (self::hidden_iframes($html, $home_host) as $h) {
            $add('critical', sprintf(__('Hidden iframe loading %s', 'tqk-wpseo'), $h));
        }
        $scripts = self::scripts($html, $home_host);
        foreach ($scripts as $s) {
            if ($s['kind'] === 'bad') {
                $add('critical', sprintf(__('Crypto-mining / malicious script: %s', 'tqk-wpseo'), $s['host']));
            }
        }
        $unknown = array_values(array_unique(array_column(array_filter($scripts, static fn($s) => $s['kind'] === 'unknown'), 'origin')));
        $inline = self::inline_scripts($html);
        $labels = self::flag_labels();
        foreach ($inline as $s) {
            foreach ($s['flags'] as $fl) {
                $add($fl['severity'], sprintf(__('Inline script #%1$d: %2$s', 'tqk-wpseo'), $s['index'] + 1, $labels[$fl['id']] ?? $fl['id']));
            }
        }
        $crit = count(array_filter($checks, static fn($c) => $c['sev'] === 'critical'));
        return [
            'verdict' => $crit > 0 ? 'infected' : ($unknown !== [] || $checks !== [] ? 'review' : 'clean'),
            'checks' => $checks,
            'unknown' => $unknown,
            'scripts' => $scripts,
            'inline' => ['count' => count($inline), 'bytes' => array_sum(array_column($inline, 'size')), 'flagged' => array_values(array_filter($inline, static fn($s) => $s['flags'] !== []))],
            'fetched' => array_map(static fn($r) => ['code' => (int) $r['code'], 'error' => (string) $r['error']], $v),
            'public' => $public,
        ];
    }

    public static function flag_labels(): array {
        return [
            'eval_decode' => __('runs decoded hidden code (eval + atob/unescape)', 'tqk-wpseo'),
            'charcode' => __('builds code from character numbers (obfuscation)', 'tqk-wpseo'),
            'write_script' => __('writes a hidden script into the page', 'tqk-wpseo'),
            'referrer_redirect' => __('redirects visitors depending on where they came from', 'tqk-wpseo'),
            'device_redirect' => __('redirects depending on the device or bot', 'tqk-wpseo'),
            'hex' => __('long hex-encoded text (obfuscation)', 'tqk-wpseo'),
            'base64' => __('large encoded blob', 'tqk-wpseo'),
            'built_src' => __('loads a script from an address it assembles or decodes', 'tqk-wpseo'),
            'miner' => __('crypto-mining code', 'tqk-wpseo'),
        ];
    }

    /** AJAX: run for one post the user can edit. */
    public static function ajax(): void {
        check_ajax_referer(\TQK_WPSEO\Admin\Ajax::NONCE);
        $id = (int) ($_POST['post_id'] ?? 0);
        if ($id <= 0 || !current_user_can('edit_post', $id)) {
            wp_send_json_error(['message' => __('Permission denied', 'tqk-wpseo')]);
        }
        $r = self::run($id);
        // Kept with the scan report, for AI agents reading it over MCP.
        \TQK_WPSEO\MCP\Reports::set_part($id, 'security', [
            'verdict' => $r['verdict'],
            'checks' => $r['checks'],
            'unknown_script_domains' => $r['unknown'],
            'scripts' => array_map(static fn($s) => $s['kind'] . ' · ' . $s['origin'], $r['scripts']),
            'inline' => ['count' => $r['inline']['count'], 'bytes' => $r['inline']['bytes'], 'flagged' => count($r['inline']['flagged'])],
        ]);
        wp_send_json_success($r);
    }
}
