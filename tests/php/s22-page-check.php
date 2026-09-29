<?php
// S22 tests: one-page malware check on real HTML (admin / visitor / from
// Google / Googlebot), script origins, inline script flags.
// Malicious samples are assembled from pieces so this file itself never
// matches the plugin's own file scanner.
// Run: php tests/php/s22-page-check.php
require __DIR__ . '/stubs.php';
require dirname(__DIR__, 2) . '/tqk-wpseo.php';

$fail = 0;
function check($name, $cond) {
    global $fail;
    if ($cond) {
        echo "ok - {$name}\n";
    } else {
        $fail++;
        echo "FAIL - {$name}\n";
    }
}

use TQK_WPSEO\Security\PageCheck;

$host = 'shop.vn';
$page = static fn(string $body, string $title = 'Shop') => '<html><head><title>' . $title . '</title>' . $body . '</head><body><p>Hello</p></body></html>';
$ok = static fn(string $html) => ['code' => 200, 'location' => '', 'body' => $html, 'error' => ''];

// ---------- script origins ----------
$html = '<script src="https://shop.vn/wp-content/plugins/woocommerce/assets/js/cart.js?ver=9"></script>'
    . '<script src="/wp-content/themes/flatsome/js/app.js"></script>'
    . '<script src="https://www.shop.vn/wp-includes/js/jquery/jquery.min.js"></script>'
    . '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
    . '<script src="https://sp.zalo.me/plugins/sdk.js"></script>'
    . '<script src="//cdn.evil-stats.xyz/a.js"></script>'
    . '<script src="https://shop.vn/wp-content/uploads/2026/01/x.js"></script>'
    . '<script src="https://coin' . 'hive.com/lib/c.min.js"></script>';
$s = PageCheck::scripts($html, $host);
$by = array_column($s, null, 'origin');
check('plugin script → plugin:slug', isset($by['plugin:woocommerce']) && $by['plugin:woocommerce']['kind'] === 'local');
check('theme script → theme:slug', isset($by['theme:flatsome']));
check('www. counts as this site, core detected', isset($by['core']));
check('Google / Zalo are known services', ($by['googletagmanager.com']['kind'] ?? '') === 'known' && ($by['sp.zalo.me']['kind'] ?? '') === 'known');
check('protocol-relative unknown domain flagged', ($by['cdn.evil-stats.xyz']['kind'] ?? '') === 'unknown');
check('a .js file in uploads is suspicious', ($by['uploads']['kind'] ?? '') === 'unknown');
check('crypto-miner host is bad', ($by['coin' . 'hive.com']['kind'] ?? '') === 'bad');

// ---------- inline flags ----------
$ids = static fn(string $js) => array_column(PageCheck::inline_flags($js), 'id');
check('eval of decoded text', in_array('eval_decode', $ids('ev' . 'al(at' . 'ob("YWxlcnQoMSk="))'), true));
check('referrer-based redirect', in_array('referrer_redirect', $ids('if(document.' . 'referrer.indexOf("google")>-1){window.location="https://x.test"}'), true));
check('char-code obfuscation', in_array('charcode', $ids('String.from' . 'CharCode(' . implode(',', array_fill(0, 25, '104')) . ')'), true));
check('document.write of a script tag', in_array('write_script', $ids('document.' . 'write("<scr" + "ipt src=x></script>")') , true) || in_array('write_script', $ids("document." . "write('<script src=x></script>')"), true));
check('normal analytics snippet is clean', $ids('window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());') === []);
check('JSON-LD blocks are not treated as scripts', PageCheck::inline_scripts('<script type="application/ld+json">{"@type":"Thing"}</script><script>var a=1;</script>')[0]['index'] === 1);

// ---------- hidden iframe ----------
check('hidden iframe to another host', PageCheck::hidden_iframes('<iframe src="https://bad.test/x" width="0" height="0"></iframe><iframe src="https://www.youtube.com/embed/1" width="560"></iframe>', $host) === ['bad.test']);

// ---------- evaluate: clean site ----------
$clean = $page('<script src="https://shop.vn/wp-content/plugins/woocommerce/a.js"></script>');
$r = PageCheck::evaluate(['admin' => $ok($clean), 'visitor' => $ok($clean), 'referred' => $ok($clean), 'googlebot' => $ok($clean)], $host, true);
check('same page for everyone → clean', $r['verdict'] === 'clean' && $r['checks'] === []);

// ---------- evaluate: redirect only for people coming from Google ----------
$r = PageCheck::evaluate([
    'admin' => $ok($clean), 'visitor' => $ok($clean),
    'referred' => ['code' => 302, 'location' => 'https://nha-cai.test/', 'body' => '', 'error' => ''],
    'googlebot' => $ok($clean),
], $host, true);
check('redirect only from Google → infected', $r['verdict'] === 'infected' && str_contains($r['checks'][0]['msg'], 'nha-cai.test'));

// ---------- evaluate: Googlebot gets spam links ----------
$spam = $page('<a href="https://bet.test/">nh' . 'à cái uy tín</a>', 'Nhà cái');
$r = PageCheck::evaluate(['admin' => $ok($clean), 'visitor' => $ok($clean), 'referred' => $ok($clean), 'googlebot' => $ok($spam)], $host, true);
check('Googlebot-only content → infected', $r['verdict'] === 'infected');

// ---------- evaluate: script hidden from the logged-in admin ----------
$withJs = $page('<script src="https://cdn.evil-stats.xyz/a.js"></script>');
$r = PageCheck::evaluate(['admin' => $ok($clean), 'visitor' => $ok($withJs), 'referred' => $ok($withJs), 'googlebot' => $ok($withJs)], $host, true);
check('script visitors get but admin does not → reported', (bool) array_filter($r['checks'], static fn($c) => str_contains($c['msg'], 'evil-stats')));
check('unknown domain listed for review', in_array('cdn.evil-stats.xyz', $r['unknown'], true) && $r['verdict'] !== 'clean');

// ---------- draft: only the admin version exists ----------
$r = PageCheck::evaluate(['admin' => $ok($clean)], $host, false);
check('draft page: script audit only, no comparison', $r['verdict'] === 'clean' && $r['public'] === false);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL S22 CHECKS PASSED\n";
exit($fail ? 1 : 0);
