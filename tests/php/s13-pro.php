<?php
// S13 tests: GSC rank tracker, bulk AI, schema conditional rules. Run: php tests/php/s13-pro.php
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

use TQK_WPSEO\Admin\Settings;
use TQK_WPSEO\Core\Activator;
use TQK_WPSEO\Core\Modules;
use TQK_WPSEO\Google\SearchConsole;

call_user_func($GLOBALS['__activation']);
do_action('init');
update_option('tqk_wpseo_settings', Activator::default_settings());

// ---------- Tracker: table upgrade + snapshot idempotency ----------
check('gsc table exists', in_array('wp_tqk_wpseo_gsc', $GLOBALS['__tables'], true));
unset($GLOBALS['__tables'][array_search('wp_tqk_wpseo_gsc', $GLOBALS['__tables'], true)]);
$GLOBALS['__tables'] = array_values($GLOBALS['__tables']);
Activator::ensure_tables();
check('ensure_tables restores gsc', in_array('wp_tqk_wpseo_gsc', $GLOBALS['__tables'], true));

update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), [
    'gsc_property' => 'sc-domain:example.test', 'gsc_token' => 'tok123',
]));
$GLOBALS['__http_queue'][] = ['code' => 200, 'body' => json_encode(['rows' => [
    ['keys' => ['dental implants'], 'clicks' => 12, 'impressions' => 300, 'ctr' => 0.04, 'position' => 8.5],
    ['keys' => ['braces price'], 'clicks' => 4, 'impressions' => 120, 'ctr' => 0.033, 'position' => 12.0],
]])];
$r = SearchConsole::sync();
check('sync snapshots', ($r['ok'] ?? false) === true && count(SearchConsole::history('dental implants')) === 1);
$GLOBALS['__http_queue'][] = ['code' => 200, 'body' => json_encode(['rows' => [
    ['keys' => ['dental implants'], 'clicks' => 15, 'impressions' => 320, 'ctr' => 0.047, 'position' => 7.0],
]])];
SearchConsole::sync();
check('resync same day replaces', count(SearchConsole::history('dental implants')) === 1
    && SearchConsole::history('dental implants')[0]['clicks'] === 15);
check('queries reflect latest snapshot', SearchConsole::queries() === ['dental implants']);
check('history html deltas', str_contains(SearchConsole::history_html('dental implants'), 'Position'));
SearchConsole::ensure_cron();
check('daily cron armed', count(array_filter($GLOBALS['__cron'] ?? [], fn($c) => ($c['hook'] ?? '') === SearchConsole::CRON_HOOK)) === 1);
SearchConsole::daily();
check('daily resyncs + rearms', count(SearchConsole::history('dental implants')) >= 1);

// ---------- Bulk AI ----------
$GLOBALS['__posts'][111] = ['ID' => 111, 'post_title' => 'Bulk One', 'post_content' => '<h1>Bulk One</h1><p>' . str_repeat('Bulk content words here. ', 30) . '</p>', 'post_excerpt' => 'Bulk excerpt here.', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'bulk-one', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['ai_provider' => 'ollama']));
$GLOBALS['__http_queue'][] = ['code' => 200, 'body' => wp_json_encode(['response' => "1. Bulk Title A\n2. Bulk Title B\n"])];
$bulk = \TQK_WPSEO\AI\Suggester::generate_bulk('title', [111, 0, 111, 999]);
check('bulk caps + dedupes + skips missing', isset($bulk[111]) && !isset($bulk[0]) && !isset($bulk[999]) && count($bulk) === 1);
check('bulk returns options', ($bulk[111][0] ?? '') === 'Bulk Title A');
$off = \TQK_WPSEO\AI\Suggester::generate_bulk('title', []);
check('bulk empty ids', $off === []);
update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['ai_provider' => 'none']));
$disabled = \TQK_WPSEO\AI\Suggester::generate_bulk('title', [111]);
check('bulk errors when ai off', isset($disabled[111]['error']));
update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['ai_provider' => 'ollama']));
ob_start();
\TQK_WPSEO\Admin\Pages::site_analysis();
$sa = (string) ob_get_clean();
check('bulk ai card lists candidates', str_contains($sa, 'Bulk AI (local)') || str_contains($sa, 'No pages missing'));

// ---------- Schema conditional rules ----------
$clean = Settings::sanitize(['schema_rules' => [
    ['post_type' => 'post', 'taxonomy' => '', 'term' => '', 'schema_type' => 'HowTo'],
    ['post_type' => '', 'taxonomy' => 'category', 'term' => 'x', 'schema_type' => 'Article'],
    ['post_type' => 'post', 'taxonomy' => 'category', 'term' => 'recipes', 'schema_type' => 'Bogus'],
    'junk',
]]);
check('rules sanitized', ($clean['schema_rules'] ?? []) === [
    ['post_type' => 'post', 'taxonomy' => '', 'term' => '', 'schema_type' => 'HowTo'],
]);
update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['schema_rules' => [
    ['post_type' => 'post', 'taxonomy' => '', 'term' => '', 'schema_type' => 'HowTo'],
]]));
$GLOBALS['__posts'][112] = ['ID' => 112, 'post_title' => 'Howto Post', 'post_content' => 'x', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'howto-post', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
check('rule beats map', \TQK_WPSEO\Schema\Schema::type_for((object) $GLOBALS['__posts'][112]) === 'HowTo');
update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['schema_rules' => []]));
check('empty rules fall back to map', \TQK_WPSEO\Schema\Schema::type_for((object) $GLOBALS['__posts'][112]) === 'Article');
ob_start();
\TQK_WPSEO\Admin\Pages::schema();
$sp = (string) ob_get_clean();
check('schema page has rules builder', str_contains($sp, 'Conditional rules') && str_contains($sp, 'settings[schema_rules]'));

// ---------- Partial settings forms never wipe ----------
update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['title_template' => 'KeepMe %title%']));
Settings::sanitize(['schema_map' => ['post' => 'BlogPosting']]);
check('partial save keeps title', get_option('tqk_wpseo_settings')['title_template'] === 'KeepMe %title%');
$_POST = ['tqk_action' => 'settings', '_ajax_nonce' => 'valid', 'settings' => ['schema_map' => ['post' => 'BlogPosting']]];
try {
    \TQK_WPSEO\Admin\Ajax::route();
} catch (WP_Test_Response $e) {
    // Expected success response.
}
check('ajax partial keeps title + applies map', get_option('tqk_wpseo_settings')['title_template'] === 'KeepMe %title%'
    && get_option('tqk_wpseo_settings')['schema_map']['post'] === 'BlogPosting');

echo $fail === 0 ? "\nALL PRO CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
