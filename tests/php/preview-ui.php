<?php
// UI preview: renders admin pages to static HTML for screenshots.
// Run: php tests/php/preview-ui.php   (needs Chrome for screenshots, separate step)
require __DIR__ . '/stubs.php';
require dirname(__DIR__, 2) . '/tqk-wpseo.php';

call_user_func($GLOBALS['__activation']);
do_action('init');

$GLOBALS['__posts'][41] = [
    'ID' => 41, 'post_title' => 'Alpha post about testing tools', 'post_content' => '<h1>Alpha post about testing tools</h1><p>' . str_repeat('Alpha content words here. ', 30) . '</p><p><a href="https://example.test/beta-page">beta</a></p>',
    'post_excerpt' => 'Alpha excerpt about testing tools here.', 'post_type' => 'post', 'post_status' => 'publish',
    'post_name' => 'alpha-post', 'post_author' => 3, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-02',
];
$GLOBALS['__posts'][42] = [
    'ID' => 42, 'post_title' => 'Beta page standing alone without friends', 'post_content' => '<h1>Beta page standing alone without friends</h1><p>' . str_repeat('Beta content words here. ', 30) . '</p>',
    'post_excerpt' => 'Beta excerpt standing alone here today.', 'post_type' => 'page', 'post_status' => 'publish',
    'post_name' => 'beta-page', 'post_author' => 3, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-02',
];
$GLOBALS['__users'][3] = ['display_name' => 'Khai', 'user_nicename' => 'khai'];
$GLOBALS['__options']['tqk_wpseo_settings'] = TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__urls'] = ['https://example.test/alpha-post' => 41, 'https://example.test/beta-page' => 42];
$GLOBALS['__posts'][99] = [
    'ID' => 99, 'post_title' => 'pic', 'post_content' => '', 'post_excerpt' => '',
    'post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'pic',
    'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01',
];
$GLOBALS['__attachments'][99] = 'https://example.test/noalt.jpg';

TQK_WPSEO\Redirect\Redirects::add('/old-page/', 'https://example.test/new/', 301);
TQK_WPSEO\Redirect\Monitor404::log('/missing-thing/', '');
TQK_WPSEO\Links\Engine::rebuild_for_post(41);
TQK_WPSEO\Scanner\Scanner::start();
TQK_WPSEO\Scanner\Scanner::step();

$out = 'C:\\Users\\KHAI\\AppData\\Local\\Temp\\opencode\\tqk-ui';
@mkdir($out, 0777, true);
copy(dirname(__DIR__, 2) . '/assets/css/tqk-wpseo-admin.css', $out . '/tqk-wpseo-admin.css');

function shot($name, $callback) {
    global $out;
    ob_start();
    $callback();
    $body = (string) ob_get_clean();
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<link rel="stylesheet" href="tqk-wpseo-admin.css">'
        . '<style>body{background:#f8fafc;padding:20px;font-family:Inter,ui-sans-serif,system-ui,sans-serif;}</style>'
        . '</head><body>' . $body . '</body></html>';
    file_put_contents($out . '/' . $name . '.html', $html);
    echo "wrote {$name}.html (" . strlen($html) . " bytes)\n";
}

shot('dashboard', fn() => TQK_WPSEO\Admin\Pages::dashboard());
shot('modules', fn() => TQK_WPSEO\Admin\Pages::modules());
shot('audit-report', function () {
    $GLOBALS['__urls']['https://example.test/demo'] = 41;
    $html = '<html><head><title>Demo Audit Page Title Here</title>'
        . '<meta name="description" content="Demo description for audit preview here today.">'
        . '<link rel="canonical" href="https://example.test/demo">'
        . '<meta property="og:title" content="OG"><meta property="og:description" content="OGD">'
        . '<meta property="og:image" content="https://example.test/i.jpg"></head>'
        . '<body><h1>Demo Audit Page</h1><p>' . str_repeat('Demo audit words here. ', 80) . '</p>'
        . '<img src="https://example.test/i.jpg" alt="demo pic" width="800" height="600" loading="lazy">'
        . '<img src="https://example.test/noalt.jpg"></body></html>';
    $rep = TQK_WPSEO\Analyzer\PageAudit::audit_html('https://example.test/demo', $html);
    echo '<div class="tqk-wpseo" style="max-width:900px">';
    echo TQK_WPSEO\Analyzer\PageAudit::report_html($rep);
    echo '</div>';
});
shot('site-analysis', fn() => TQK_WPSEO\Admin\Pages::site_analysis());
shot('redirects', fn() => TQK_WPSEO\Admin\Pages::redirects());
shot('monitor-404', fn() => TQK_WPSEO\Admin\Pages::monitor_404());
shot('links', fn() => TQK_WPSEO\Admin\Pages::links());
shot('settings', fn() => TQK_WPSEO\Admin\Pages::settings());
shot('metabox', function () {
    echo '<div style="max-width:720px;background:#fff;padding:20px;border:1px solid #ddd;">';
    TQK_WPSEO\Admin\Metabox::render((object) $GLOBALS['__posts'][41]);
    TQK_WPSEO\Analyzer\Analyzer::analyze(41);
    echo '<hr>';
    TQK_WPSEO\Analyzer\AnalysisBox::render((object) $GLOBALS['__posts'][41]);
    echo '</div>';
});
echo "OUT-DIR: {$out}\n";
