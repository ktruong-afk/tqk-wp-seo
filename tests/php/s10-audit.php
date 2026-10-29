<?php
// S10 tests: rendered-HTML analyze, live badge, page-audit bot. Run: php tests/php/s10-audit.php
require __DIR__ . '/stubs.php';
require dirname(__DIR__, 2) . '/tqk-wp-seo.php';

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

use TQK_WPSEO\Analyzer\Analyzer;
use TQK_WPSEO\Analyzer\Frontend;
use TQK_WPSEO\Analyzer\PageAudit;

call_user_func($GLOBALS['__activation']);
do_action('init');
$GLOBALS['__options']['tqk_wpseo_settings'] = TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__users'][3] = ['display_name' => 'Khai', 'user_nicename' => 'khai'];
$GLOBALS['__posts'][81] = [
    'ID' => 81, 'post_title' => 'Audit Me', 'post_content' => '<p>raw</p>', 'post_excerpt' => 'Rendered audit excerpt here today.',
    'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'audit-me',
    'post_author' => 3, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-02',
];
$GLOBALS['__urls'] = ['https://example.test/audit-me' => 81];

// 1. HTML override beats raw post_content.
$r = Analyzer::analyze(81, '<html><body><h1>Rendered H1 Here</h1><p>' . str_repeat('Rendered words here. ', 110) . '</p></body></html>');
$ids = array_column($r['findings'], 'id');
check('override removes missing_h1', !in_array('missing_h1', $ids, true));
check('override removes thin', !in_array('thin_content', $ids, true));

// 2. main_html strips chrome, prefers main.
$main = Frontend::main_html('<html><body><header>H</header><nav>N</nav><main><h1>T</h1></main><footer>F</footer></body></html>');
check('main extracted, chrome stripped', str_contains($main, '<h1>T</h1>') && !str_contains($main, '<nav>'));

// 3. Inject analyzes rendered HTML + stores, no floating badge.
$GLOBALS['__queried_object_id'] = 81;
$out = Frontend::inject('<html><body><h1>Hi</h1></body></html>');
check('no floating badge', !str_contains($out, 'tqk-wpseo-live'));
check('badge stored analysis', Analyzer::stored(81) !== null);

// 4. Head parser.
$head = PageAudit::parse_head('<html><head><title>My Title Here</title>'
    . '<meta name="description" content="Desc here"><link rel="canonical" href="https://example.test/x">'
    . '<meta property="og:title" content="OG"><meta property="og:image" content="https://example.test/i.jpg">'
    . '</head><body></body></html>');
check('head parsed', $head['title'] === 'My Title Here' && $head['description'] === 'Desc here'
    && $head['canonical'] === 'https://example.test/x' && $head['og_image'] === 'https://example.test/i.jpg');

// 5. Full bot audit on crafted HTML.
$GLOBALS['__postmeta_map'][81] = ['_tqk_wpseo_focus_keyword' => 'audit me'];
$GLOBALS['__http_queue'][] = ['headers' => ['content-length' => '600000'], 'code' => 200];
$full = '<html><head><title>Audit Me</title><meta name="description" content="audit me today please">'
    . '<link rel="canonical" href="https://example.test/audit-me">'
    . '<meta property="og:title" content="OG"><meta property="og:description" content="OGD">'
    . '<meta property="og:image" content="https://example.test/i.jpg"></head>'
    . '<body><h1>Audit Me</h1><p>' . str_repeat('audit me words here. ', 60) . '</p>'
    . '<img src="https://example.test/i.jpg" alt="audit me pic" width="800" height="600" loading="lazy"></body></html>';
$rep = PageAudit::audit_html('https://example.test/audit-me', $full);
check('bot audit ok', ($rep['ok'] ?? false) === true && ($rep['post_id'] ?? 0) === 81);
check('bot images detail', count($rep['images_detail'] ?? []) === 1 && ($rep['images_detail'][0]['weight'] ?? '') !== '—');
check('bot heavy flagged', in_array('heavy_images', array_column($rep['findings'], 'id'), true));
check('bot report html', str_contains(PageAudit::report_html($rep), 'Images (1)'));

// 6. SSRF guard + mocked fetch.
$bad = PageAudit::audit_url('https://evil.test/x');
check('external rejected', ($bad['ok'] ?? true) === false);
$GLOBALS['__http_queue'][] = ['body' => $full];
$good = PageAudit::audit_url('https://example.test/audit-me');
check('same-host fetched', ($good['ok'] ?? false) === true && ($good['score'] ?? -1) >= 0);

// 7. Local image dims without network.
$png = sys_get_temp_dir() . '/tqk-test-1px.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$info = PageAudit::image_info($png);
check('intrinsic dims local', ($info['dims'] ?? '') === '1×1');
@unlink($png);

// 8. Scanner ajax audit op.
$GLOBALS['__http_queue'][] = ['body' => $full];
$_POST = ['op' => 'audit', 'url' => 'https://example.test/audit-me', '_ajax_nonce' => 'valid'];
try {
    \TQK_WPSEO\Scanner\Scanner::ajax();
    check('ajax audit returns', false);
} catch (WP_Test_Response $e) {
    check('ajax audit returns', ($e->payload['success'] ?? false) === true && str_contains($e->payload['data']['html'] ?? '', 'Score:'));
}

// 9. Site Analysis page has audit tool.
ob_start();
\TQK_WPSEO\Admin\Pages::site_analysis();
check('audit tool on page', str_contains((string) ob_get_clean(), 'tqk-audit-run'));

// 10. The old floating "Audit website" button on the front end is gone:
// pages are checked from the editor (Scan) and from Live Audit.
check('no front-end scan button any more', !class_exists('TQK_WPSEO\Analyzer\Frontscan'));

// 11. set-alt op saves attachment ALT.
$GLOBALS['__posts'][99] = [
    'ID' => 99, 'post_title' => 'pic', 'post_content' => '', 'post_excerpt' => '',
    'post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'pic',
    'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01',
];
$GLOBALS['__attachments'][99] = 'https://example.test/wp-content/uploads/pic.jpg';
$_POST = ['op' => 'set-alt', 'attachment_id' => 99, 'alt' => 'A nice pic', '_ajax_nonce' => 'valid'];
try {
    \TQK_WPSEO\Scanner\Scanner::ajax();
    check('ajax set-alt saves', false);
} catch (WP_Test_Response $e) {
    check('ajax set-alt saves', ($e->payload['success'] ?? false) === true && get_post_meta(99, '_wp_attachment_image_alt', true) === 'A nice pic');
}
$_POST = ['op' => 'set-alt', 'attachment_id' => 81, 'alt' => 'Nope', '_ajax_nonce' => 'valid'];
try {
    \TQK_WPSEO\Scanner\Scanner::ajax();
    check('ajax set-alt rejects non-attachment', false);
} catch (WP_Test_Response $e) {
    check('ajax set-alt rejects non-attachment', ($e->payload['success'] ?? true) === false);
}

// 12. Report ALT editor buttons resolve attachments.
$GLOBALS['__attachments'][99] = 'https://example.test/wp-content/uploads/pic.jpg';
$rep2 = PageAudit::audit_html(
    'https://example.test/audit-me',
    '<html><head><title>T</title></head><body><h1>T</h1><img src="https://example.test/wp-content/uploads/pic.jpg"></body></html>'
);
check('report alt buttons', str_contains(PageAudit::report_html($rep2), 'data-tqk-set-alt="99"'));

// 12b. Report head-tag editors appear only when post is known.
$head_html = PageAudit::report_html($rep2);
check('report head editors', str_contains($head_html, 'data-tqk-post="81"') && str_contains($head_html, 'data-tqk-field="title"') && str_contains($head_html, 'data-tqk-field="robots"') && str_contains($head_html, 'data-tqk-save-head'));
check('head save buttons use WP classes', str_contains($head_html, 'class="button button-small" data-tqk-save-head'));
$rep_anon = PageAudit::audit_html(
    'https://example.test/nowhere-page',
    '<html><head><title>X</title></head><body><h1>X</h1></body></html>'
);
check('report head editors need post', !str_contains(PageAudit::report_html($rep_anon), 'data-tqk-save-head'));

echo $fail === 0 ? "\nALL AUDIT CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
