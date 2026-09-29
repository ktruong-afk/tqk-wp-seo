<?php
// S23 tests: AI sets (page text first, one task each) and the MCP endpoint
// (JSON-RPC: initialize, tools, prompts, report + screenshots, safe writes).
// Run: php tests/php/s23-mcp.php
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

use TQK_WPSEO\Admin\AiJson;
use TQK_WPSEO\MCP\Protocol;
use TQK_WPSEO\MCP\Reports;

$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$long = str_repeat('phở bò ngon tuyệt ', 400); // 1600 words
$GLOBALS['__posts'][31] = [
    'ID' => 31, 'post_title' => 'Phở bò', 'post_content' => '<h2>Giới thiệu</h2>[gallery ids="1,2"]<p>' . $long . '</p><script>var x=1;</script>[contact-form-7 id="5"]',
    'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'pho-bo',
    'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01',
];

// ---------- AI sets ----------
$t = AiJson::plain_text(31);
check('plain text: no tags, no shortcodes, no script', !str_contains($t['text'], '<') && !str_contains($t['text'], '[gallery') && !str_contains($t['text'], 'contact-form') && !str_contains($t['text'], 'var x'));
check('plain text cut at 1000 words', $t['cut'] && $t['words'] > 1600 && count(preg_split('/\s+/u', trim(rtrim($t['text'], ' …')))) === 1000);
$c = AiJson::bundle(31, 'content');
check('set ① is plain text starting with the title', is_string($c) && str_starts_with($c, "Phở bò\n"));
$s = AiJson::bundle(31, 'seo');
check('set ② puts the content first', array_key_first($s) === 'page' && array_key_first($s['page']) === 'content');
check('set ② asks only for its 4 keys', array_keys($s['reply_keys']) === ['focus_keyword', 'secondary_keywords', 'title', 'description']);
check('set ② much shorter than everything', strlen((string) json_encode($s)) < strlen((string) json_encode(AiJson::bundle(31, 'all'))));
$so = AiJson::bundle(31, 'social');
check('set ③ social keys only', array_keys($so['reply_keys']) === ['og_title', 'og_description', 'twitter_title', 'twitter_description']);
update_post_meta(31, '_tqk_wpseo_schema_type', 'Recipe');
$sc = AiJson::bundle(31, 'schema');
check('set ④ only the chosen schema type form', array_keys($sc['schema_fields']) === ['Recipe'] && !isset($sc['schema_fields']['Recipe']['course_rating']));
delete_post_meta(31, '_tqk_wpseo_schema_type');
$all = AiJson::bundle(31, 'all');
check('"everything" keeps the full package, content first', array_key_first($all['page']) === 'content' && isset($all['schema_fields']['FAQPage']));

// ---------- reports + inbox ----------
check('no report before a scan', Reports::get(31) === null);
Reports::save(31, ['url' => 'https://example.test/pho-bo', 'plan' => [['prio' => 1, 'title' => 'Missing meta description'], ['prio' => 2, 'title' => 'x'], ['prio' => 9]],
    'screens' => [['screen' => 'Mobile 375', 'score' => 73]], 'bad' => ['<b>tag</b>' => '<script>alert(1)</script>ok'], 'shots' => []]);
$r = Reports::get(31);
check('report stored, keys and values without markup', $r !== null && isset($r['bad']['btagb']) && !str_contains((string) $r['bad']['btagb'], '<') && isset($r['at']));
Reports::set_part(31, 'security', ['verdict' => 'clean', 'checks' => []]);
Reports::push(31, 'tập trung vào mobile');
$sum = Reports::inbox_summary();
check('inbox: note, plan counts (bad prio clamped), security', $sum[0]['note'] === 'tập trung vào mobile' && $sum[0]['plan'] === ['fix_now' => 1, 'should_fix' => 1, 'nice_to_have' => 1] && $sum[0]['security'] === 'clean');
Reports::push(31, 'again');
check('pushing twice keeps one entry', count(Reports::inbox()) === 1);

// ---------- MCP protocol ----------
update_option('tqk_wpseo_modules', array_merge((array) get_option('tqk_wpseo_modules', []), ['mcp' => true]));
$token = \TQK_WPSEO\MCP\Server::token();
check('auth: X-TQK-Token', Protocol::auth(['headers' => ['X-TQK-Token' => $token]]) === \TQK_WPSEO\Core\Modules::is_active('mcp'));
check('auth: Bearer token', Protocol::auth(['headers' => ['Authorization' => 'Bearer ' . $token]]) === \TQK_WPSEO\Core\Modules::is_active('mcp'));
check('auth: wrong token refused', Protocol::auth(['headers' => ['X-TQK-Token' => 'nope']]) === false);

$init = Protocol::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
check('initialize echoes a supported version', $init['result']['protocolVersion'] === '2025-03-26' && isset($init['result']['capabilities']['tools'], $init['result']['capabilities']['prompts']));
$init = Protocol::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
check('unknown version → latest', $init['result']['protocolVersion'] === Protocol::VERSIONS[0]);
check('notification → no response (HTTP 202)', Protocol::handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) === null);
check('ping → empty object', json_encode(Protocol::handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'])['result']) === '{}');
check('unknown method → -32601', Protocol::handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'nope'])['error']['code'] === -32601);
$tools = Protocol::handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'])['result']['tools'];
$names = array_column($tools, 'name');
check('tools listed with input schemas', in_array('list_pushed_pages', $names, true) && in_array('get_page_report', $names, true) && in_array('apply_seo_fields', $names, true) && isset($tools[0]['inputSchema']['type']));
check('empty properties encode as {}', str_contains((string) json_encode($tools[0]['inputSchema']), '"properties":{}'));
$batch = Protocol::handle([['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping'], ['jsonrpc' => '2.0', 'method' => 'notifications/x']]);
check('batch: one reply per request, none for notifications', count($batch) === 1 && $batch[0]['id'] === 5);

$call = static fn(string $name, array $args = []) => Protocol::handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]])['result'];
$l = $call('list_pushed_pages');
check('list_pushed_pages returns the page', str_contains($l['content'][0]['text'], '"post_id": 31') && $l['isError'] === false);

// Screenshot: a real JPEG, tall enough to be cut into parts.
$GLOBALS['__uploads'] = sys_get_temp_dir() . '/tqk-up-' . getmypid();
$has_gd = function_exists('imagecreatetruecolor');
if ($has_gd) {
    $im = imagecreatetruecolor(375, 4500);
    ob_start();
    imagejpeg($im, null, 60);
    $jpg = 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    $u = \TQK_WPSEO\Analyzer\LiveAudit::save_editor_shot(31, 'mobile', $jpg);
    check('editor snapshot saved for mobile', $u !== '' && \TQK_WPSEO\Analyzer\LiveAudit::editor_shot_path(31, 'mobile') !== '');
    check('laptop / tablet accepted too, unknown keys refused', \TQK_WPSEO\Analyzer\LiveAudit::save_editor_shot(31, 'tablet', $jpg) !== '' && \TQK_WPSEO\Analyzer\LiveAudit::save_editor_shot(31, 'tv', $jpg) === '');
    check('tall screenshot cut into 3 readable parts', count(Protocol::tiles(\TQK_WPSEO\Analyzer\LiveAudit::editor_shot_path(31, 'mobile'))) === 3);
}
$rep = $call('get_page_report', ['post_id' => 31]);
$types = array_column($rep['content'], 'type');
check('report: JSON text first, latest owner note included', $types[0] === 'text' && str_contains($rep['content'][0]['text'], '"owner_note": "again"'));
if ($has_gd) {
    check('report: screenshots as images with captions', in_array('image', $types, true) && $rep['content'][array_search('image', $types, true)]['mimeType'] === 'image/jpeg');
}
$rep = $call('get_page_report', ['post_id' => 31, 'screenshots' => false]);
check('screenshots can be left out', !in_array('image', array_column($rep['content'], 'type'), true));
check('unknown post → isError', $call('get_page_report', ['post_id' => 999])['isError'] === true);
$cont = $call('get_page_content', ['post_id' => 31]);
check('get_page_content: text first', str_contains($cont['content'][0]['text'], 'phở bò ngon'));

// Approval first: by default an AI app never writes to the page.
$fields = ['title' => 'Phở bò <b>chuẩn</b> vị', 'description' => 'Cách nấu phở bò.', 'course_rating' => '5', 'robots' => 'bad', 'hack' => 'x'];
$sg = $call('submit_suggestions', ['post_id' => 31, 'advice' => "1. Thêm mô tả.\n2. Nút menu mobile quá nhỏ.", 'fields' => $fields, 'from' => 'Claude']);
check('suggestion: nothing written to the page', get_post_meta(31, '_tqk_wpseo_title', true) !== 'Phở bò chuẩn vị' && str_contains($sg['content'][0]['text'], 'nothing was written'));
$s = Reports::suggestion(31);
check('suggestion stored: advice, cleaned fields, refused list, sender', $s['status'] === 'pending' && str_contains($s['advice'], 'Nút menu') && ($s['fields']['_tqk_wpseo_title'] ?? '') === 'Phở bò chuẩn vị' && !isset($s['fields']['_tqk_wpseo_robots']) && $s['refused'] !== [] && $s['from'] === 'Claude');
check('pending list shows the page', array_keys(Reports::pending()) === [31]);
$ap = $call('apply_seo_fields', ['post_id' => 31, 'fields' => ['description' => 'Khác']]);
check('apply_seo_fields without permission → kept as a suggestion', get_post_meta(31, '_tqk_wpseo_description', true) !== 'Khác' && Reports::suggestion(31)['fields']['_tqk_wpseo_description'] === 'Khác');
check('empty suggestion refused', $call('submit_suggestions', ['post_id' => 31, 'advice' => ' '])['isError'] === true);
Reports::close_suggestion(31, 'dismissed');
check('dismissed → leaves the pending list', Reports::pending() === [] && Reports::suggestion(31)['status'] === 'dismissed');

// Owner allowed direct writes in the settings.
$GLOBALS['__options']['tqk_wpseo_settings']['mcp_direct_write'] = true;
$ap = $call('apply_seo_fields', ['post_id' => 31, 'fields' => $fields]);
check('apply (allowed): allowed fields written, sanitised', get_post_meta(31, '_tqk_wpseo_title', true) === 'Phở bò chuẩn vị' && get_post_meta(31, '_tqk_wpseo_description', true) === 'Cách nấu phở bò.');
check('apply: rating / bad robots / unknown key refused', get_post_meta(31, '_tqk_wpseo_robots', true) === '' && str_contains($ap['content'][0]['text'], 'refused'));
check('apply: write logged', (get_option(\TQK_WPSEO\MCP\Server::LOG_OPTION)[0]['tool'] ?? '') === 'mcp:apply_seo_fields');
$GLOBALS['__options']['tqk_wpseo_settings']['mcp_direct_write'] = false;

// Filled in the editor and saved → the suggestion is marked used.
Reports::suggest(31, 'Gợi ý', ['title' => 'Mới']);
$_POST = [\TQK_WPSEO\Admin\Metabox::NONCE => 'valid', 'tqk_wpseo_ai_applied' => '1'];
\TQK_WPSEO\Admin\Metabox::save(31, (object) $GLOBALS['__posts'][31]);
check('saving the post after filling closes the suggestion', Reports::suggestion(31)['status'] === 'applied' && Reports::pending() === []);
$_POST = [];
check('mark_page_done empties the inbox', str_contains($call('mark_page_done', ['post_id' => 31])['content'][0]['text'], 'Removed') && Reports::inbox() === []);

// ---------- Live Audit writes the same report; push all ----------
Reports::set_part(31, 'security', ['verdict' => 'clean']);
\TQK_WPSEO\Analyzer\LiveAudit::store_report(31, ['plan' => [['prio' => 1, 'title' => 'Missing meta description']], 'source' => 'forged'], ['fields' => ['No focus keyword set.'], 'shots' => []]);
$r = Reports::get(31);
check('site audit report: source set by the server, fields kept', $r['source'] === 'site audit' && $r['fields'] === ['No focus keyword set.']);
check('site audit keeps an earlier security check', ($r['security']['verdict'] ?? '') === 'clean');
$run = ['ids' => [31, 0, 999], 'st' => \TQK_WPSEO\Analyzer\LiveAudit::DONE . \TQK_WPSEO\Analyzer\LiveAudit::DONE . \TQK_WPSEO\Analyzer\LiveAudit::DONE];
check('push all: only pages with a report and something to fix', \TQK_WPSEO\Analyzer\LiveAudit::push_all($run) === 1 && Reports::inbox()[0]['id'] === 31);
if ($has_gd) {
    // Report screenshots are read from the stored URLs (site audit: uploads).
    $up = wp_upload_dir();
    @mkdir($up['basedir'] . '/tqk-wpseo-audit/abcdefgh12', 0777, true);
    copy(\TQK_WPSEO\Analyzer\LiveAudit::editor_shot_path(31, 'mobile'), $up['basedir'] . '/tqk-wpseo-audit/abcdefgh12/31-desktop.jpg');
    Reports::set_part(31, 'shots', ['desktop' => $up['baseurl'] . '/tqk-wpseo-audit/abcdefgh12/31-desktop.jpg', 'evil' => 'https://evil.test/x.jpg']);
    $rep = $call('get_page_report', ['post_id' => 31, 'screens' => ['desktop']]);
    check('screenshots follow the report (site audit files)', in_array('image', array_column($rep['content'], 'type'), true));
}
Reports::done(31);

$pl = Protocol::handle(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'prompts/list'])['result']['prompts'];
check('prompt review_page listed', $pl[0]['name'] === 'review_page');
$pg = Protocol::handle(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'prompts/get', 'params' => ['name' => 'review_page', 'arguments' => ['post_id' => '31']]]);
check('prompt: read the report, send suggestions for approval, never write', str_contains($pg['result']['messages'][0]['content']['text'], 'get_page_report') && str_contains($pg['result']['messages'][0]['content']['text'], 'submit_suggestions') && str_contains($pg['result']['messages'][0]['content']['text'], 'you do not write'));

echo $fail ? "\n{$fail} FAILED\n" : "\nALL S23 CHECKS PASSED\n";
exit($fail ? 1 : 0);
