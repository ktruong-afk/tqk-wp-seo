<?php
// S9 tests: modules toggle + gating + score tones. Run: php tests/php/s9-modules.php
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

use TQK_WPSEO\Admin\View;
use TQK_WPSEO\Core\Modules;

call_user_func($GLOBALS['__activation']);
do_action('init');

// Defaults: everything on except instant-indexing.
check('fifteen modules', count(Modules::all()) === 15);
check('fourteen active by default', count(Modules::active()) === 14);
check('menu has modules page', isset(MenuItems()['tqk-wpseo-modules']));
function MenuItems() {
    return \TQK_WPSEO\Admin\Menu::items();
}

// Toggle analytics off hides its page (GSC).
Modules::set('analytics', false);
check('gsc page hidden', !isset(\TQK_WPSEO\Admin\Menu::items()['tqk-wpseo-site-analysis']));
check('dashboard stays', isset(\TQK_WPSEO\Admin\Menu::items()['tqk-wpseo']));
Modules::set('analytics', true);

// Enabling scanner auto-enables analyzer.
Modules::set('analyzer', false);
Modules::set('scanner', true);
check('scanner pulls analyzer', Modules::is_active('analyzer'));

// Unknown slug ignored.
check('unknown slug ignored', Modules::set('nope', true) === Modules::states());

// Gating: sitemap output 404s when module off.
Modules::set('sitemap', false);
ob_start();
\TQK_WPSEO\Sitemap\Sitemap::output('index');
ob_get_clean();
check('sitemap gated', ($GLOBALS['__status_header'] ?? 0) === 404);
Modules::set('sitemap', true);

// Gating: redirects + 404 skip when off.
Modules::set('redirects', false);
$_SERVER['REQUEST_URI'] = '/old-page/';
\TQK_WPSEO\Redirect\Redirects::add('/old-page/', '/new/', 301);
\TQK_WPSEO\Redirect\Redirects::handle();
check('redirect gated (no exit)', true);
Modules::set('redirects', true);
Modules::set('monitor-404', false);
$GLOBALS['__cond'] = ['is_404' => true];
$_SERVER['REQUEST_URI'] = '/missing/';
\TQK_WPSEO\Redirect\Monitor404::maybe_log();
check('404 gated (nothing logged)', \TQK_WPSEO\Redirect\Monitor404::all() === []);
Modules::set('monitor-404', true);
$GLOBALS['__cond'] = [];

// Gating: analyzer queue + MCP auth.
Modules::set('analyzer', false);
\TQK_WPSEO\Core\Queue::push(5);
check('queue paused when analyzer off', \TQK_WPSEO\Analyzer\Analyzer::process_queue() === 0);
Modules::set('analyzer', true);
Modules::set('mcp', false);
$token = \TQK_WPSEO\MCP\Server::token();
check('mcp gated', \TQK_WPSEO\MCP\Server::check(['headers' => ['x-tqk-token' => $token]]) === false);
Modules::set('mcp', true);
check('mcp back on', \TQK_WPSEO\MCP\Server::check(['headers' => ['x-tqk-token' => $token]]) === true);

// Scanner blocked without analyzer (ajax).
Modules::set('analyzer', false);
$_POST = ['op' => 'start', '_ajax_nonce' => 'valid'];
try {
    \TQK_WPSEO\Scanner\Scanner::ajax();
    check('scanner blocked without analyzer', false);
} catch (WP_Test_Response $e) {
    check('scanner blocked without analyzer', ($e->payload['success'] ?? true) === false);
}
Modules::set('analyzer', true);

// Modules page renders switch cards.
ob_start();
\TQK_WPSEO\Admin\Pages::modules();
$mp = (string) ob_get_clean();
check('modules page cards', substr_count($mp, 'data-tqk-module=') === 15);

// AJAX toggle flips + returns states.
$_POST = ['tqk_action' => 'module', '_ajax_nonce' => 'valid', 'module' => 'links', 'on' => ''];
try {
    \TQK_WPSEO\Admin\Ajax::route();
    check('module ajax toggles', false);
} catch (WP_Test_Response $e) {
    check(
        'module ajax toggles',
        ($e->payload['success'] ?? false) === true
        && ($e->payload['data']['states']['links'] ?? true) === false
        && !isset(\TQK_WPSEO\Admin\Menu::items()['tqk-wpseo-links'])
    );
}
\TQK_WPSEO\Core\Modules::set('links', true);

// Score tones.
check('tone good', View::score_tone(86) === 'is-good' && View::score_tone(80) === 'is-good');
check('tone ok', View::score_tone(79) === 'is-ok' && View::score_tone(50) === 'is-ok');
check('tone bad', View::score_tone(49) === 'is-bad' && View::score_tone(0) === 'is-bad');
check('tone null', View::score_tone(null) === '');
check('ring colors', str_contains(View::score_ring(90), '#34d399') && str_contains(View::score_ring(60), '#fbbf24') && str_contains(View::score_ring(10), '#fb7185'));
check('bar tone class', str_contains(View::score_bar('X', 30), 'is-bad'));

echo $fail === 0 ? "\nALL MODULE CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
