<?php
// S24 tests: private audit files (random folder, blocked web access,
// admin-only links, daily pruning).
// Run: php tests/php/s24-vault.php
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

use TQK_WPSEO\Core\Vault;

$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__uploads'] = sys_get_temp_dir() . '/tqk-vault-' . getmypid();

$base = Vault::base();
check('private folder has a random name', (bool) preg_match('#/tqk-wpseo-private-[a-f0-9]{24}$#', str_replace('\\', '/', $base)));
check('same folder on the next call', Vault::base() === $base);
check('web access blocked (.htaccess, web.config, index.php)', is_file($base . '/.htaccess') && str_contains((string) file_get_contents($base . '/.htaccess'), 'Require all denied') && is_file($base . '/web.config') && is_file($base . '/index.php'));

check('keys: folder/file.jpg|json only', Vault::valid('abcdefgh12/13-mobile.jpg') && Vault::valid('editorscan/2.json'));
check('keys: no traversal, no other types', !Vault::valid('../x.jpg') && !Vault::valid('abcdefgh12/../../wp-config.php') && !Vault::valid('abcdefgh12/x.php') && !Vault::valid('AB/x.jpg'));

$k = Vault::write('abcdefgh12', '13-mobile.jpg', "\xFF\xD8\xFFtest");
check('write returns the key, file inside the private folder', $k === 'abcdefgh12/13-mobile.jpg' && is_file($base . '/abcdefgh12/13-mobile.jpg'));
check('write refuses a bad name', Vault::write('abcdefgh12', '../evil.php', 'x') === '');

$u = Vault::url($k);
check('link goes through the admin gate with a nonce, never the file path', str_contains($u, 'admin-ajax.php') && str_contains($u, 'action=tqk_wpseo_file') && str_contains($u, '_wpnonce=') && !str_contains($u, 'tqk-wpseo-private'));
check('older public URLs pass through unchanged', Vault::url('https://example.test/wp-content/uploads/x.jpg') === 'https://example.test/wp-content/uploads/x.jpg');
check('local path of a key', Vault::local($k) === $base . '/abcdefgh12/13-mobile.jpg');
check('LiveAudit link hidden once the file is gone', \TQK_WPSEO\Analyzer\LiveAudit::file_url('abcdefgh12/missing.jpg') === '');

// Pruning: files older than the kept days go, recent ones stay.
$old = Vault::write('abcdefgh12', 'old.json', '{}');
touch($base . '/abcdefgh12/old.json', time() - 2 * 86400);
check('default: keep 1 day', Vault::keep_days() === 1);
$n = Vault::prune();
check('prune removes files older than 1 day only', $n === 1 && !is_file($base . '/abcdefgh12/old.json') && is_file($base . '/abcdefgh12/13-mobile.jpg'));
check('prune date remembered (once a day)', get_option(Vault::PRUNED_OPTION) === gmdate('Y-m-d'));
check('a later day removes everything older', Vault::prune(1, time() + 3 * 86400) === 1 && !is_dir($base . '/abcdefgh12'));

$GLOBALS['__options']['tqk_wpseo_settings']['audit_keep_days'] = 7;
check('setting: 7 days', Vault::keep_days() === 7);
$GLOBALS['__options']['tqk_wpseo_settings']['audit_keep_days'] = 99;
check('invalid setting falls back to 1 day', Vault::keep_days() === 1);

// Editor snapshots are stored in the vault too.
$jpg = '';
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(20, 20);
    ob_start();
    imagejpeg($im);
    $jpg = 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    $key = \TQK_WPSEO\Analyzer\LiveAudit::save_editor_shot(13, 'laptop', $jpg);
    check('editor snapshot → vault key', $key === 'editorscan/13-laptop.jpg' && \TQK_WPSEO\Analyzer\LiveAudit::editor_shot_path(13, 'laptop') !== '');
}

// ---------- Audit scope: pages + per type skip / 1 sample / all (Pro) ----------
use TQK_WPSEO\Analyzer\LiveAudit;
$mk = static function (int $id, string $type) {
    $GLOBALS['__posts'][$id] = ['ID' => $id, 'post_title' => "P{$id}", 'post_content' => 'x', 'post_excerpt' => '', 'post_type' => $type, 'post_status' => 'publish', 'post_name' => "p{$id}", 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
};
foreach ([101, 102] as $i) { $mk($i, 'page'); }
foreach ([201, 202, 203] as $i) { $mk($i, 'post'); }
update_option('show_on_front', 'page');
$types = LiveAudit::types();
check('pages listed first', array_key_first($types) === 'page');
$ids = LiveAudit::build_plan(['post' => 'one']);
check('pages all + 1 sample post (latest)', in_array(101, $ids, true) && in_array(102, $ids, true) && count(array_intersect($ids, [201, 202, 203])) === 1 && in_array(203, $ids, true));
$ids = LiveAudit::build_plan(['post' => 'none'], false);
check('pages off + posts skipped → nothing', $ids === []);
$ids = LiveAudit::build_plan(['post' => 'all'], false);
check('all posts of a type', count(array_intersect($ids, [201, 202, 203])) === 3);
check('"all" needs Pro (named in the message)', LiveAudit::needs_pro(['post' => 'all', 'page' => 'all']) !== [] && LiveAudit::needs_pro(['post' => 'one']) === []);

// ---------- Taxonomy archives in the audit ----------
if (!function_exists('get_object_taxonomies')) {
    function get_object_taxonomies($pt, $output = 'names') {
        return $pt === 'post' ? ['category' => (object) ['public' => true, 'labels' => (object) ['name' => 'Categories']], 'post_format' => (object) ['public' => true, 'labels' => (object) ['name' => 'Formats']]] : [];
    }
}
if (!function_exists('wp_count_terms')) {
    function wp_count_terms($args = []) {
        return count($GLOBALS['__terms']);
    }
}
$GLOBALS['__terms'] = [['term_id' => 7, 'name' => 'Tin tức', 'slug' => 'tin-tuc', 'taxonomy' => 'category']];
$tx = LiveAudit::taxonomies();
check('taxonomies listed once, with their post types, formats hidden', array_keys($tx) === ['category'] && $tx['category']['for'] === 'Posts' || array_keys($tx) === ['category']);
$ids = LiveAudit::build_plan(['post' => 'none', 'tax:category' => 'one'], false);
check('1 sample archive queued as -term_id', $ids === [-7]);
check('archive skipped', LiveAudit::build_plan(['post' => 'none', 'tax:category' => 'none'], false) === []);
$it = LiveAudit::item(-7);
check('archive item: term name, term link, type', $it['title'] === 'Tin tức' && str_contains($it['url'], 'tin-tuc') && $it['type'] === 'term: category');
check('"all" archives need Pro', LiveAudit::needs_pro(['tax:category' => 'all']) === ['Categories']);
$run = LiveAudit::start('plan', '', false, ['post' => 'none', 'tax:category' => 'one'], false);
$res = LiveAudit::save($run, 0, ['seo' => ['score' => 70, 'findings' => []], 'vps' => [], 'groups' => []], []);
check('archive result stored in term meta, readable as a record', LiveAudit::record(-7, (string) $run['id'])['seo']['score'] === 70);
check('archive rows have no image block (images belong to posts)', LiveAudit::image_rows(-7) === []);

// ---------- Image lines in Live Audit rows ----------
\TQK_WPSEO\Analyzer\ImageFix::save_editor_images(202, [[
    'kind' => 'img', 'src' => 'https://example.test/wp-content/uploads/hero.jpg', 'att' => 0, 'srcset' => false, 'sizes' => '',
    'vp' => [
        'desktop' => ['w' => 1200, 'h' => 600, 'nw' => 2400, 'nh' => 1200, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => ''],
        'mobile' => ['w' => 343, 'h' => 172, 'nw' => 2400, 'nh' => 1200, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => ''],
    ],
]]);
$lines = LiveAudit::image_lines(202);
check('oversized image reported on its worst screen', count($lines) === 1 && str_contains($lines[0], 'hero.jpg') && str_contains($lines[0], '2400') && str_contains($lines[0], '343') && str_contains($lines[0], 'Mobile') && str_contains($lines[0], '686'));
check('page with no image issue → no line', LiveAudit::image_lines(201) === []);

// ---------- Re-scan keeps only a short score trail ----------
use TQK_WPSEO\MCP\Reports;
for ($i = 1; $i <= 13; $i++) {
    Reports::save(201, ['score' => 50 + $i, 'screens' => [['score' => 60 + $i], ['score' => 40 + $i, 'error' => '']], 'plan' => [['prio' => 1], ['prio' => 2], ['prio' => 2]]]);
}
$r = Reports::get(201);
check('latest report replaces the old one', $r['score'] === 63);
check('history: last 10 earlier checks only', count($r['history']) === 10 && end($r['history'])['seo'] === 62);
check('history point: seo, worst screen, plan counts', end($r['history'])['responsive'] === 52 && end($r['history'])['fix_now'] === 1 && end($r['history'])['should_fix'] === 2);

// ---------- Dashboard overview across page reports ----------
Reports::save(202, ['score' => 40, 'screens' => [['score' => 55]], 'plan' => [
    ['prio' => 1, 'area' => 'seo', 'code' => 'noDesc', 'title' => 'Missing meta description'],
    ['prio' => 2, 'area' => 'layout', 'code' => 'smallTap', 'title' => '3 buttons too small'],
    ['prio' => 3, 'area' => 'social', 'code' => 'noTwitter', 'title' => 'No X card'],
], 'security' => ['verdict' => 'review']]);
Reports::save(203, ['score' => 80, 'screens' => [['score' => 90]], 'plan' => [
    ['prio' => 1, 'area' => 'seo', 'code' => 'noDesc', 'title' => 'Missing meta description'],
    ['prio' => 1, 'area' => 'seo', 'title' => 'Old report item (12 words)'],
    ['prio' => 1, 'area' => 'seo', 'title' => 'Another old item'],
], 'security' => ['verdict' => 'clean']]);
$o = Reports::overview();
check('overview: pages with a report, weakest display', $o['pages'] >= 2 && $o['worst']['score'] <= 55);
check('overview: same kind grouped with its page count, fix-now first', ($o['issues']['noDesc']['pages'] ?? 0) === 2 && array_key_first($o['issues']) === 'noDesc');
check('overview: nice-to-have items left out', !isset($o['issues']['noTwitter']));
$old = array_column(array_filter($o['issues'], static fn($i) => str_starts_with((string) $i['code'], 't:')), 'title');
check('overview: old items without code stay separate (not merged by area)', in_array('Old report item (12 words)', $old, true) && in_array('Another old item', $old, true));
check('overview: security counted', $o['security']['review'] >= 1 && $o['security']['clean'] >= 1);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL S24 CHECKS PASSED\n";
exit($fail ? 1 : 0);
