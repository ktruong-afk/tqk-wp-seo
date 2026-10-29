<?php
// S5 tests: redirects, 404, links, scanner, bulk, settings, dashboard. Run: php tests/php/s5-technical.php
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

use TQK_WPSEO\Links\Engine as LinkEngine;
use TQK_WPSEO\Redirect\Monitor404;
use TQK_WPSEO\Redirect\Redirects;
use TQK_WPSEO\Scanner\Scanner;

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
$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__urls'] = ['https://example.test/alpha-post' => 41, 'https://example.test/beta-page' => 42];

// Redirects.
check('redirect added', Redirects::add('/old-page/', 'https://example.test/new/', 301) > 0);
check('redirect loop rejected', Redirects::add('/loop/', '/loop/', 301) === 0);
check('bad type rejected', Redirects::add('/x/', '/y/', 999) === 0);
check('redirect matched normalized', Redirects::get('/old-page') !== null);
$_SERVER['REQUEST_URI'] = '/old-page/?utm_source=x';
try {
    Redirects::handle();
    check('redirect fires', false);
} catch (WP_Test_Response $e) {
    check('redirect fires', $e->payload === ['redirect' => 'https://example.test/new/', 'status' => 301]);
}
$_SERVER['REQUEST_URI'] = '/nope/';
Redirects::handle();
check('no match passes through', true);

// 404 monitor.
$GLOBALS['__cond'] = ['is_404' => true];
$_SERVER['REQUEST_URI'] = '/missing-thing/';
$_SERVER['HTTP_REFERER'] = 'https://example.test/';
Monitor404::maybe_log();
Monitor404::maybe_log();
check('404 logged with hits=2', Monitor404::all()[0]->hits === 2);
$GLOBALS['__cond'] = [];

// Links engine.
check('links rebuilt count', LinkEngine::rebuild_for_post(41) === 1);
check('orphan detection', LinkEngine::orphan_ids() === [41]);
$GLOBALS['__options']['page_on_front'] = 41;
check('front page excluded from orphans', LinkEngine::orphan_ids() === []);
unset($GLOBALS['__options']['page_on_front']);
check('incoming/outgoing counts', LinkEngine::counts(42) === [1, 0]);

// Menu links count as incoming.
$GLOBALS['__posts'][71] = ['ID' => 71, 'post_title' => 'Menu item', 'post_content' => '', 'post_excerpt' => '', 'post_type' => 'nav_menu_item', 'post_status' => 'publish', 'post_name' => '', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
$GLOBALS['__postmeta_map'][71] = ['_menu_item_url' => 'https://example.test/beta-page'];
check('menu rebuild counts', LinkEngine::rebuild_menus() === 1);
check('menu link removes orphan', !in_array(42, LinkEngine::orphan_ids(), true));

// External check with mocked HEAD.
$GLOBALS['__http_queue'][] = ['code' => 200];
$GLOBALS['__http_queue'][] = ['code' => 404];
$GLOBALS['__http_queue'][] = new WP_Error('timeout');
LinkEngine::rebuild_for_post(41);
$GLOBALS['wpdb']->insert(LinkEngine::table(), ['source_id' => 41, 'source_type' => 'post', 'target_id' => 0, 'url' => 'https://external.test/a', 'anchor' => 'a', 'is_internal' => 0, 'is_broken' => 0]);
$GLOBALS['wpdb']->insert(LinkEngine::table(), ['source_id' => 41, 'source_type' => 'post', 'target_id' => 0, 'url' => 'https://external.test/b', 'anchor' => 'b', 'is_internal' => 0, 'is_broken' => 0]);
$GLOBALS['wpdb']->insert(LinkEngine::table(), ['source_id' => 41, 'source_type' => 'post', 'target_id' => 0, 'url' => 'https://external.test/c', 'anchor' => 'c', 'is_internal' => 0, 'is_broken' => 0]);
[$checked, $broken] = LinkEngine::check_external(20);
check('external check counts', $checked === 3 && $broken === 2);
check('broken list', count(LinkEngine::broken()) === 2);

// Scanner end-to-end (2 posts, one batch).
$job = Scanner::start();
check('scan job total 2', $job['total'] === 2);
$job = Scanner::step();
check('scan finished', ($job['status'] ?? '') === 'done' && ($job['done'] ?? 0) === 2);
$summary = Scanner::summary();
check('summary health computed', ($summary['health'] ?? 0) > 0);
check('summary groups present', isset($summary['groups']['content']));
check('history recorded', count(Scanner::history()) === 1);
check(
    'pages grouped with findings',
    count($summary['pages'] ?? []) === 2
    && isset($summary['pages'][0]['object_id'], $summary['pages'][0]['findings'])
    && ($summary['pages'][0]['critical'] ?? 0) + ($summary['pages'][0]['warnings'] ?? 0) > 0
);
$eval_total = 0;
foreach ($GLOBALS['wpdb']->tables['wp_tqk_wpseo_scan'] as $row) {
    $eval_total += (int) ($row['evaluated'] ?? 0);
}
check(
    'passed = evaluated - findings',
    ($summary['passed'] ?? -1) === $eval_total - ($summary['critical'] ?? 0) - ($summary['warnings'] ?? 0)
);

// Scanner modes: sample picks one representative per post type.
$GLOBALS['__posts'][43] = [
    'ID' => 43, 'post_title' => 'Third post same type', 'post_content' => 'x', 'post_excerpt' => '',
    'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'third-post',
    'post_author' => 3, 'post_date' => '2026-09-03', 'post_modified' => '2026-09-03',
];
$sample = Scanner::start('sample');
check('sample dedupes by type', ($sample['total'] ?? 0) === 2 && ($sample['mode'] ?? '') === 'sample');
$full = Scanner::start('full');
check('full takes every page', ($full['total'] ?? 0) === 3 && ($full['mode'] ?? '') === 'full');

// Scanner ajax.
$_POST = ['op' => 'status', '_ajax_nonce' => 'valid'];try {
    Scanner::ajax();
    check('ajax status returns', false);
} catch (WP_Test_Response $e) {
    check('ajax status returns', $e->payload['success'] === true && isset($e->payload['data']['summary']));
}

// Dashboard wired to summary.
ob_start();
\TQK_WPSEO\Admin\Pages::dashboard();
$dash = (string) ob_get_clean();
check('dashboard keeps the site issues of the scan', str_contains($dash, 'Site issues'));
check('old "Top issues" list is gone', !str_contains($dash, 'Top issues') && !str_contains($dash, 'tqk-wpseo-score-end'));

// Redirects admin page: AJAX form + prefill (saves go through Ajax, covered in s8).
$_POST = [];
$_GET = ['from_404' => '/old-missing/'];
ob_start();
\TQK_WPSEO\Admin\Pages::redirects();
$rp = (string) ob_get_clean();
check('redirect page ajax form + prefill', str_contains($rp, 'data-tqk-form="redirect"') && str_contains($rp, '/old-missing/'));
$_GET = [];

// 404 page links create-redirect.
ob_start();
\TQK_WPSEO\Admin\Pages::monitor_404();
$mp = (string) ob_get_clean();
check('404 page has create link', str_contains($mp, 'Create redirect'));

// Dashboard accordion is the single bulk editor (Content page removed).
$_POST = [];
$_GET = [];
update_option(
    \TQK_WPSEO\Scanner\Scanner::SUMMARY_OPTION,
    ['health' => 80, 'critical' => 1, 'warnings' => 1, 'passed' => 5, 'groups' => [], 'finished_at' => 'now',
     'site' => [],
     'pages' => [
        ['object_id' => 41, 'title' => 'Alpha post about testing tools', 'type' => 'post', 'score' => 80, 'critical' => 1, 'warnings' => 0, 'url' => '#', 'findings' => [['severity' => 'critical', 'message' => 'Missing meta description.']]],
        ['object_id' => 42, 'title' => 'Beta page standing alone without friends', 'type' => 'page', 'score' => 70, 'critical' => 0, 'warnings' => 1, 'url' => '#', 'findings' => [['severity' => 'warning', 'message' => 'No focus keyword set.']]],
     ]]
);
// Quick fix on the dashboard: checked pages missing SEO basics only.
\TQK_WPSEO\MCP\Reports::save(41, ['plan' => [['prio' => 1, 'code' => 'noSchema', 'title' => 'No JSON-LD schema']]]);
update_post_meta(42, '_tqk_wpseo_description', 'Has one');
update_post_meta(42, '_tqk_wpseo_focus_keyword', 'beta');
\TQK_WPSEO\MCP\Reports::save(42, ['plan' => []]);
ob_start();
\TQK_WPSEO\Admin\Pages::dashboard();
$cp = (string) ob_get_clean();
check('quick fix form for a checked page missing basics', str_contains($cp, 'data-tqk-quickfix data-id="41"') && str_contains($cp, 'Alpha post'));
check('quick fix marks what is missing (desc, keyword, schema)', (bool) preg_match('/data-id="41".*?name="desc" class="is-missing".*?name="keyword" class="is-missing".*?name="schema" class="is-missing"/s', $cp));
check('complete page not listed', !str_contains($cp, 'data-id="42"'));
check('quick fix never touches the sitemap choice', !preg_match('/data-id="41".*?sitemap_exclude.*?<\/form>/s', $cp));
$_GET = [];

// Settings sanitize.
$clean = \TQK_WPSEO\Admin\Settings::sanitize(['title_template' => '<b>%title%</b>', 'ai_provider' => 'evil', 'noindex_search' => '1']);
check('settings strips tags', $clean['title_template'] === '%title%');
check('settings provider allowlist', $clean['ai_provider'] === 'none');
check('settings checkbox bool', $clean['noindex_search'] === true);

// Tools reset keeps plugin working.
$_POST = ['tqk-wpseo-reset' => 1, 'tqk-wpseo-tools-nonce' => 'valid'];
ob_start();
\TQK_WPSEO\Admin\Pages::tools();
$tp = (string) ob_get_clean();
check('reset re-seeds settings', get_option('tqk_wpseo_settings') !== false && str_contains($tp, 'data-tqk-form="tools"'));

// Scanner js only on analysis screen.
$GLOBALS['__enqueued'] = ['style' => [], 'script' => []];
\TQK_WPSEO\Admin\Assets::enqueue('tqk-wpseo_page_tqk-wpseo-site-analysis');
check('scanner js scoped', in_array('tqk-wpseo-scanner', $GLOBALS['__enqueued']['script'], true));

echo $fail === 0 ? "\nALL S5 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
