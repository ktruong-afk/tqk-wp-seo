<?php
// S8 tests: every admin action over AJAX, append-not-reload. Run: php tests/php/s8-ajax.php
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

use TQK_WPSEO\Admin\Ajax;
use TQK_WPSEO\Redirect\Monitor404;
use TQK_WPSEO\Redirect\Redirects;

call_user_func($GLOBALS['__activation']);
do_action('init');
$GLOBALS['__options']['tqk_wpseo_settings'] = TQK_WPSEO\Core\Activator::default_settings();

function ajax_call($action, $fields = []) {
    $_POST = array_merge(['tqk_action' => $action, '_ajax_nonce' => 'valid'], $fields);
    try {
        Ajax::route();
    } catch (WP_Test_Response $e) {
        return $e->payload;
    }
    return null;
}

// Guard.
$GLOBALS['__bad_nonce'] = true;
$r = ajax_call('redirect', ['op' => 'add']);
check('bad nonce rejected', ($r['success'] ?? true) === false);
$GLOBALS['__bad_nonce'] = false;
check('unknown action rejected', (ajax_call('nope')['success'] ?? true) === false);

// Wizard AJAX: save + advance, skip without save, finish clears.
$r = ajax_call('wizard', ['step' => 3, 'title_template' => 'Ajax %title%']);
check('wizard saves + advances', ($r['success'] ?? false) === true && ($r['data']['step'] ?? 0) === 4 && str_contains($r['data']['html'] ?? '', 'Indexability'));
check('wizard template stored', get_option('tqk_wpseo_settings')['title_template'] === 'Ajax %title%');
update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['title_template' => 'KeepMe']));
$r = ajax_call('wizard', ['step' => 3, 'skip' => '1', 'title_template' => 'Changed']);
check('wizard skip advances unsaved', ($r['data']['step'] ?? 0) === 4 && get_option('tqk_wpseo_settings')['title_template'] === 'KeepMe');
update_option(TQK_WPSEO\Admin\Wizard::PENDING, 1);
$r = ajax_call('wizard', ['step' => 7]);
check('wizard finish done', ($r['data']['done'] ?? false) === true && str_contains($r['data']['html'] ?? '', 'Setup complete'));
check('wizard pending cleared', get_option(TQK_WPSEO\Admin\Wizard::PENDING, false) == false);

// Redirect add appends row HTML.
$r = ajax_call('redirect', ['op' => 'add', 'source' => '/ajax-old/', 'target' => '/ajax-new/', 'type' => '301']);
check('redirect ajax adds', ($r['success'] ?? false) === true && str_contains($r['data']['row'] ?? '', '/ajax-old<'));
check('redirect ajax bad input', (ajax_call('redirect', ['op' => 'add', 'source' => '/x/', 'target' => '/x/'])['success'] ?? true) === false);
$id = Redirects::get('/ajax-old/')->id ?? 0;
$r = ajax_call('redirect', ['op' => 'del', 'id' => $id]);
check('redirect ajax deletes', ($r['success'] ?? false) === true && Redirects::get('/ajax-old/') === null);

// 404 delete.
Monitor404::log('/gone/', '');
$mid = Monitor404::all()[0]->id ?? 0;
$r = ajax_call('four04', ['id' => $mid]);
check('404 ajax deletes', ($r['success'] ?? false) === true && Monitor404::all() === []);

// Bulk save.
$GLOBALS['__posts'][61] = ['ID' => 61, 'post_title' => 'B', 'post_content' => 'x', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'draft', 'post_name' => 'b', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
$r = ajax_call('bulk', ['rows' => [61 => ['title' => 'Ajax Bulk', 'desc' => 'D']]]);
check('bulk ajax saves', ($r['data']['saved'] ?? 0) === 1 && get_post_meta(61, '_tqk_wpseo_title', true) === 'Ajax Bulk');

// Bulk OG keys save through the same map.
$r = ajax_call('bulk', ['rows' => [61 => ['og_title' => 'OG T', 'og_description' => 'OG D', 'og_image' => 'https://example.test/og.jpg']]]);
check('bulk saves og meta', ($r['data']['saved'] ?? 0) === 1 && get_post_meta(61, '_tqk_wpseo_og_title', true) === 'OG T' && get_post_meta(61, '_tqk_wpseo_og_description', true) === 'OG D' && get_post_meta(61, '_tqk_wpseo_og_image', true) === 'https://example.test/og.jpg');
// Empty value deletes instead of storing.
$r = ajax_call('bulk', ['rows' => [61 => ['og_title' => '']]]);
check('bulk empty deletes og meta', ($r['data']['saved'] ?? 0) === 1 && get_post_meta(61, '_tqk_wpseo_og_title', true) === '');

// Bulk extended fields: keyword/canonical/robots.
$r = ajax_call('bulk', ['rows' => [61 => ['keyword' => 'ajax kw', 'canonical' => 'https://example.test/b/', 'robots' => 'noindex,follow']]]);
check('bulk saves keyword/canonical/robots', ($r['data']['saved'] ?? 0) === 1 && get_post_meta(61, '_tqk_wpseo_focus_keyword', true) === 'ajax kw' && get_post_meta(61, '_tqk_wpseo_canonical', true) === 'https://example.test/b/' && get_post_meta(61, '_tqk_wpseo_robots', true) === 'noindex,follow');
$r = ajax_call('bulk', ['rows' => [61 => ['robots' => 'evil-value']]]);
check('bulk rejects invalid robots', ($r['data']['saved'] ?? 0) === 1 && get_post_meta(61, '_tqk_wpseo_robots', true) === 'noindex,follow');

// Bulk URL-keyed rows resolve via url_to_postid.
$GLOBALS['__urls']['https://example.test/by-url/'] = 61;
$r = ajax_call('bulk', ['rows' => [['url' => 'https://example.test/by-url/', 'title' => 'By URL']]]);
check('bulk resolves url rows', ($r['data']['saved'] ?? 0) === 1 && get_post_meta(61, '_tqk_wpseo_title', true) === 'By URL');
$r = ajax_call('bulk', ['rows' => [['url' => 'https://example.test/nope/', 'title' => 'X']]]);
check('bulk skips unresolvable url', ($r['data']['saved'] ?? 0) === 0);

// Settings save.
$r = ajax_call('settings', ['settings' => ['title_template' => 'S %title%', 'ai_provider' => 'bogus', 'noindex_tag' => '1']]);
check('settings ajax saves+sanitizes', ($r['success'] ?? false) === true && get_option('tqk_wpseo_settings')['title_template'] === 'S %title%' && get_option('tqk_wpseo_settings')['ai_provider'] === 'none');
ob_start();
\TQK_WPSEO\Admin\Pages::settings();
$sp = (string) ob_get_clean();
check('settings uses switches + cards', str_contains($sp, 'tqk-wpseo-switch') && str_contains($sp, 'tqk-wpseo-formcard'));

// Robots save + reset.
$r = ajax_call('robots', ['op' => 'save', 'content' => "User-agent: *\nDisallow: /"]);
check('robots ajax warns block-all', ($r['success'] ?? false) === true && ($r['data']['warnings'] ?? []) !== []);
$r = ajax_call('robots', ['op' => 'reset']);
check('robots ajax resets', ($r['success'] ?? false) === true && str_contains($r['data']['content'] ?? '', 'Sitemap:'));

// Tools reset.
$r = ajax_call('tools', []);
check('tools ajax resets', ($r['success'] ?? false) === true && get_option('tqk_wpseo_settings') !== false);

// Report html.
$r = ajax_call('report', []);
check('report returns html', ($r['success'] ?? false) === true && str_contains($r['data']['html'] ?? '', 'Latest result'));

// Links check batch (mocked HEAD).
$GLOBALS['__http_queue'][] = ['code' => 200];
$GLOBALS['wpdb']->insert(
    \TQK_WPSEO\Links\Engine::table(),
    ['source_id' => 61, 'source_type' => 'post', 'target_id' => 0, 'url' => 'https://external.test/z', 'anchor' => 'z', 'is_internal' => 0, 'is_broken' => 0]
);
$r = ajax_call('links', []);
check('links ajax checks', ($r['success'] ?? false) === true && ($r['data']['checked'] ?? 0) >= 1 && isset($r['data']['html']));

// MCP token regen.
$before = \TQK_WPSEO\MCP\Server::token();
$r = ajax_call('mcp', ['op' => 'regen']);
check('mcp regen rotates token', ($r['success'] ?? false) === true && ($r['data']['token'] ?? '') !== '' && ($r['data']['token'] ?? $before) !== $before);

// CSV export.
\TQK_WPSEO\Redirect\Redirects::add('/csv-a/', '/csv-b/', 301);
$r = ajax_call('redirect', ['op' => 'export']);
check('csv export', ($r['success'] ?? false) === true && str_contains($r['data']['csv'] ?? '', 'source,target,type,hits') && str_contains($r['data']['csv'] ?? '', '/csv-a,/csv-b/,301'));

// Nonce localized for admin.js.
$GLOBALS['__enqueued'] = ['style' => [], 'script' => []];
TQK_WPSEO\Admin\Assets::enqueue('toplevel_page_tqk-wpseo');
check('ajax nonce localized', ($GLOBALS['__localized']['tqk-wpseo-admin'][1]['nonce'] ?? '') === 'valid');

// Pages render AJAX hooks (no full-post forms left).
ob_start();
TQK_WPSEO\Admin\Pages::redirects();
$rp = (string) ob_get_clean();
check('redirects page ajax form', str_contains($rp, 'data-tqk-form="redirect"') && str_contains($rp, 'tqk-rows-redirects'));
Monitor404::log('/preview-gone/', '');
ob_start();
TQK_WPSEO\Admin\Pages::monitor_404();
check('404 page ajax rows', str_contains((string) ob_get_clean(), 'data-tqk-del="four04"'));

// Dashboard quick-fix forms per page group.
update_option(
    \TQK_WPSEO\Scanner\Scanner::SUMMARY_OPTION,
    ['health' => 80, 'critical' => 1, 'warnings' => 0, 'passed' => 5, 'groups' => [], 'finished_at' => 'now',
     'pages' => [['object_id' => 61, 'title' => 'B', 'type' => 'post', 'score' => 80, 'critical' => 1, 'warnings' => 0, 'url' => '#', 'findings' => []]]]
);
TQK_WPSEO\MCP\Reports::save(61, ['plan' => []]);
delete_post_meta(61, '_tqk_wpseo_focus_keyword'); // something left to fill
ob_start();
TQK_WPSEO\Admin\Pages::dashboard();
$dash = (string) ob_get_clean();
check('dashboard quickfix forms', str_contains($dash, 'data-tqk-quickfix') && str_contains($dash, 'data-id="61"'));

echo $fail === 0 ? "\nALL AJAX CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
