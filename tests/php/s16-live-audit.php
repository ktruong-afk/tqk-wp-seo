<?php
// S16 tests: Live Audit queue, per-page tracking, resume, retry, sanitizing.
// Browser-side measurement and snapshots run in JS and are NOT covered here.
// Run: php tests/php/s16-live-audit.php
require __DIR__ . '/stubs.php';
require dirname(__DIR__, 2) . '/tqk-wp-seo.php';

if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12, $special = true, $extra = false) {
        return substr(str_repeat(bin2hex(random_bytes(16)), 2), 0, $len);
    }
}

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

use TQK_WPSEO\Analyzer\LiveAudit;

$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$mk = function (int $id, string $type, string $status = 'publish') {
    $GLOBALS['__posts'][$id] = [
        'ID' => $id, 'post_title' => "P{$id}", 'post_type' => $type, 'post_status' => $status,
        'post_name' => "p{$id}", 'post_author' => 1, 'post_date' => '2026-09-01',
        'post_modified' => '2026-09-02', 'post_excerpt' => '', 'post_content' => '<p>x</p>',
    ];
};
// 3 pages, 5 posts, 1 draft post.
foreach ([10, 11, 12] as $id) {
    $mk($id, 'page');
}
foreach ([20, 21, 22, 23, 24] as $id) {
    $mk($id, 'post');
}
$mk(25, 'post', 'draft');

// --- queue building ---
$GLOBALS['__options']['show_on_front'] = 'posts';
$sample = LiveAudit::build_ids('sample');
check('sample: blog homepage first', ($sample[0] ?? null) === 0);
check('sample: every page included', array_slice($sample, 1, 3) === [10, 11, 12]);
check('sample: exactly one post, the newest', array_slice($sample, 4) === [24]);
check('sample: drafts never queued', !in_array(25, $sample, true));

$GLOBALS['__options']['show_on_front'] = 'page';
check('static front page: no extra homepage entry', !in_array(0, LiveAudit::build_ids('sample'), true));

$all = LiveAudit::build_ids('all', 'post');
check('all: every published post, oldest first', $all === [20, 21, 22, 23, 24]);
check('all: unknown type gives empty queue', LiveAudit::build_ids('all', 'nope') === []);

// --- run + per-page tracking ---
$run = LiveAudit::start('all', 'post', false);
$st = LiveAudit::state($run);
check('start: full list taken up front', $st['total'] === 5 && $st['pending'] === 5 && $st['done'] === 0);
check('start: run id is safe for a folder name', (bool) preg_match('/^[a-z0-9]{16}$/', $st['id']));
check('stored run matches', LiveAudit::run()['id'] === $st['id']);

[$items, $run] = LiveAudit::batch($run);
check('batch: pending pages with index + url', count($items) === 5 && $items[0]['index'] === 0 && $items[0]['id'] === 20);

$ok = ['seo' => ['score' => 88, 'findings' => [['sev' => 'warning', 'msg' => 'No canonical link']], 'schema' => ['Article']],
    'vps' => [['label' => 'Desktop 1440', 'score' => 90], ['label' => 'Mobile 375', 'score' => 60]], 'groups' => []];
$res = LiveAudit::save($run, 0, $ok, []);
$run = $res['run'];
check('save: page marked done', LiveAudit::status_of($run, 0) === LiveAudit::DONE);
check('save: record stored on the post', (LiveAudit::record(20, $run['id'])['seo']['score'] ?? 0) === 88);
check('save: worst viewport is the responsive score', LiveAudit::min_vp(LiveAudit::record(20, $run['id'])) === 60);

check('cache: meta holds a JSON string, not a PHP array', is_string(get_post_meta(20, LiveAudit::META, true)));
$quoted = $ok;
$quoted['seo']['findings'][] = ['sev' => 'warning', 'msg' => 'Canonical points to "C:\\path" O\'Brien'];
$run = LiveAudit::save($run, 1, $quoted, [])['run'];
$back = LiveAudit::record(21, $run['id']);
check('cache: quotes and backslashes survive the JSON round trip', ($back['seo']['findings'][1]['msg'] ?? '') === $quoted['seo']['findings'][1]['msg']);
// Put page 2 back to pending for the order tests below.
$run['st'][1] = LiveAudit::PENDING;

// Out of order is fine: pages are tracked individually, not by a cursor.
$run = LiveAudit::save($run, 2, $ok, [])['run'];
check('save out of order: page 3 done, page 2 still pending', LiveAudit::status_of($run, 2) === 'd' && LiveAudit::status_of($run, 1) === '.');

$again = LiveAudit::save($run, 0, $ok, []);
check('repeat save of a done page is a no-op', $again['record'] === null && empty($again['error']));
check('save unknown index is refused', !empty(LiveAudit::save($run, 99, $ok, [])['error']));

$run = LiveAudit::save($run, 1, ['error' => 'timeout'], [], true)['run'];
check('failed page marked failed, not done', LiveAudit::status_of($run, 1) === LiveAudit::FAILED);
check('failed record keeps the reason', (LiveAudit::record(21, $run['id'])['error'] ?? '') === 'timeout');

// Resume: next batch only returns what is still pending.
[$items, $run] = LiveAudit::batch($run);
check('resume: only unfinished pages come back', array_column($items, 'index') === [3, 4]);

// A post unpublished mid-run is marked failed, not silently dropped.
$GLOBALS['__posts'][23]['post_status'] = 'draft';
[$items, $run] = LiveAudit::batch($run);
check('unpublished mid-run: marked failed', LiveAudit::status_of($run, 3) === LiveAudit::FAILED && array_column($items, 'index') === [4]);
$GLOBALS['__posts'][23]['post_status'] = 'publish';

$run = LiveAudit::save($run, 4, $ok, [])['run'];
$st = LiveAudit::state($run);
check('all pages finished → run done', $st['status'] === 'done' && $st['pending'] === 0 && $st['done'] === 3 && $st['failed'] === 2);

$run = LiveAudit::retry_failed($run);
$st = LiveAudit::state($run);
check('retry: failed back to pending, done kept', $st['pending'] === 2 && $st['done'] === 3 && $st['status'] === 'running');

$rep = LiveAudit::report_rows($run);
check('report: lists finished pages only', $rep['count'] === 3);

// A new run does not show the old run's records.
$run2 = LiveAudit::start('sample', '', false);
check('new run: old records not reported', LiveAudit::record(20, $run2['id']) === null);

// --- sanitizing what the browser sends ---
$dirty = LiveAudit::clean_item([
    'seo' => ['score' => 400, 'findings' => [['sev' => 'evil', 'msg' => '<script>x</script>ok']], 'schema' => ['Article', ['x']]],
    'vps' => array_fill(0, 9, ['label' => 'L', 'score' => -5, 'hack' => 1]),
    'groups' => [['root' => 'div.a', 'count' => '7']],
    'extra' => 'dropped',
]);
check('clean: score clamped to 0..100', $dirty['seo']['score'] === 100 && $dirty['vps'][0]['score'] === 0);
check('clean: unknown severity becomes warning', $dirty['seo']['findings'][0]['sev'] === 'warning');
check('clean: tags stripped from messages', strpos($dirty['seo']['findings'][0]['msg'], '<') === false);
check('clean: at most 4 viewports', count($dirty['vps']) === 4);
check('clean: unknown keys dropped', !isset($dirty['extra']) && !isset($dirty['vps'][0]['hack']));

// --- snapshot decoding ---
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\0", 16);
check('shot: non-jpeg data URL refused', LiveAudit::decode_shot('data:image/png;base64,' . base64_encode($jpeg)) === null);
check('shot: fake jpeg bytes refused', LiveAudit::decode_shot('data:image/jpeg;base64,' . base64_encode('<?php echo 1;')) === null);
check('shot: broken base64 refused', LiveAudit::decode_shot('data:image/jpeg;base64,@@@@') === null);
if (function_exists('imagecreatetruecolor')) {
    ob_start();
    imagejpeg(imagecreatetruecolor(4, 4));
    $real = (string) ob_get_clean();
    check('shot: real jpeg accepted', LiveAudit::decode_shot('data:image/jpeg;base64,' . base64_encode($real)) === $real);
}

echo $fail === 0 ? "\nALL S16 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
