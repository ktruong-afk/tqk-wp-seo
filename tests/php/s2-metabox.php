<?php
// S2 tests: metabox render + fast save. Run: php tests/php/s2-metabox.php
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

use TQK_WPSEO\Admin\Metabox;
use TQK_WPSEO\Analyzer\AnalysisBox;
use TQK_WPSEO\Core\Data;
use TQK_WPSEO\Core\Queue;

$GLOBALS['__posts'][11] = [
    'ID' => 11, 'post_title' => 'Draft', 'post_content' => 'x', 'post_excerpt' => '',
    'post_type' => 'post', 'post_status' => 'draft', 'post_name' => 'draft',
    'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01',
];

do_action('init');
check('metabox hook registered', isset($GLOBALS['__actions']['add_meta_boxes']));
do_action('add_meta_boxes');
$box_ids = array_column($GLOBALS['__metaboxes'] ?? [], 'id');
check(
    'one metabox per post type (analysis is inline)',
    count($GLOBALS['__metaboxes'] ?? []) === 2
    && in_array('tqk-wpseo', $box_ids, true)
    && !in_array('tqk-wpseo-analysis', $box_ids, true)
);

// Placement: one box, main (left) column. Analysis is embedded in it, not a
// separate metabox, so the score sits directly above the fields it grades.
$boxes = $GLOBALS['__metaboxes'] ?? [];
$by_id = [];
foreach ($boxes as $b) {
    $by_id[$b['id']] ??= $b;
}
check('analysis box no longer registered separately', !isset($by_id['tqk-wpseo-analysis']));
check('analysis is still renderable inline', method_exists(AnalysisBox::class, 'render') && method_exists(AnalysisBox::class, 'register'));
check('fields box in main (left) column', ($by_id['tqk-wpseo']['ctx'] ?? '') === 'normal');
check('no plugin box left in sidebar', !in_array('side', array_column($boxes, 'ctx'), true));

// Preview must reflect what the frontend actually outputs, not raw field values.
$prev = Metabox::preview(11);
check('preview falls back to template title when field empty', trim((string) $prev['title']) !== '');
check('preview title is not the raw empty meta', trim((string) $prev['title']) !== '');
$prev_override = Metabox::preview(11, ['title' => 'Custom SERP Title']);
check('preview honours override', $prev_override['title'] === 'Custom SERP Title');
$prev_blank = Metabox::preview(11, ['title' => '']);
check('blank override does not clobber resolved title', trim((string) $prev_blank['title']) === trim((string) $prev['title']));

// Tab grouping keeps every field reachable exactly once.
$tab_keys = array_merge(
    array_keys(Metabox::tab_fields('seo')),
    array_keys(Metabox::tab_fields('social')),
    array_keys(Metabox::tab_fields('advanced'))
);
sort($tab_keys);
$all_keys = array_keys(Metabox::fields());
sort($all_keys);
check('all 13 fields assigned to a tab', count($tab_keys) === 13 && $tab_keys === $all_keys);
check('token hint non-empty', str_contains(Metabox::tokens(), '%title%'));

// Render.
ob_start();
Metabox::render((object) $GLOBALS['__posts'][11]);
$html = (string) ob_get_clean();
$spec_count = array_sum(array_map('count', \TQK_WPSEO\Schema\Fields::specs()));
check(
    'renders the 13 core fields, 2 "same as" checkboxes and the schema type fields',
    substr_count($html, 'name="_tqk_wpseo_') === 13 + 2 + $spec_count
);
check('has nonce', str_contains($html, 'name="tqk-wpseo-metabox"'));
check('has serp preview', str_contains($html, 'tqk-wpseo-serp-title'));
check('renders 3 tabs', substr_count($html, 'data-tab="') === 3);
check('social panel present', str_contains($html, 'data-panel="social"') && str_contains($html, 'tqk-wpseo-social'));
check('"same as page title" checkbox present', str_contains($html, 'id="tqk-title-sync" name="_tqk_wpseo_title_sync"'));
check('"same as Google" ticked while share texts are empty', (bool) preg_match('/id="tqk-social-sync" name="_tqk_wpseo_social_sync" value="1" checked/', $html));
check('Facebook, Zalo and X previews', str_contains($html, 'data-net="facebook"') && str_contains($html, 'data-net="zalo"') && str_contains($html, 'data-net="x"'));
check('SEO fields come before the checklist', strpos($html, 'name="_tqk_wpseo_description"') < strpos($html, 'tqk-wpseo-analysis'));
check('keyword, title, description first in the SEO tab', array_slice(array_keys(Metabox::tab_fields('seo')), 0, 3) === ['_tqk_wpseo_focus_keyword', '_tqk_wpseo_title', '_tqk_wpseo_description']);
check('scan modes offered, never posted with the form', substr_count($html, 'name="tqk_scan_mode"') === 2 && str_contains($html, 'form="tqk-wpseo-noform"'));
check('sitelinks guide present', str_contains($html, 'tqk-serp-help'));
// The old client-side keyword echo was replaced by the live server checklist.
check('live checklist container present', str_contains($html, 'tqk-wpseo-analysis'));
check('serp preview seeded with resolved title', (bool) preg_match('/id="tqk-wpseo-serp-title" data-effective="[^"]+">[^<]+<\/div>/', $html));
check('robots select present', str_contains($html, 'noindex,nofollow'));
check('schema fields block present', str_contains($html, 'tqk-wpseo-schema-fields'));
check('schema type blocks rendered for builder types', substr_count($html, 'tqk-wpseo-schema-block"') >= 5);
check('harvest button rendered', str_contains($html, 'tqk-wpseo-schema-harvest'));

// Save: sanitize + store + invalidate + queue.
$_POST = [
    'tqk-wpseo-metabox' => 'valid',
    '_tqk_wpseo_title' => '<b>New Title</b>',
    '_tqk_wpseo_description' => 'Desc here',
    '_tqk_wpseo_robots' => 'hacker-choice',
    '_tqk_wpseo_canonical' => 'https://example.test/new/',
];
Data::for(['type' => 'singular', 'object_id' => 11, 'post_type' => 'post']);
Metabox::save(11, (object) $GLOBALS['__posts'][11]);
check('title sanitized', get_post_meta(11, '_tqk_wpseo_title', true) === 'New Title');
check('desc stored', get_post_meta(11, '_tqk_wpseo_description', true) === 'Desc here');
check('bad robots rejected', get_post_meta(11, '_tqk_wpseo_robots', true) === '');
check('canonical stored', get_post_meta(11, '_tqk_wpseo_canonical', true) === 'https://example.test/new/');
check('cache invalidated', wp_cache_get('post:11', Data::CACHE_GROUP) === false);
check('queued for analysis', in_array(11, Queue::all(), true));

// "Same as page title" / "Same as Google" on save.
update_post_meta(11, '_tqk_wpseo_og_title', 'Old share title');
$_POST = [
    'tqk-wpseo-metabox' => 'valid',
    '_tqk_wpseo_title_sync' => '1',
    '_tqk_wpseo_title' => 'typed but ignored',
    '_tqk_wpseo_social_sync' => '1',
    '_tqk_wpseo_og_title' => 'hidden field value',
];
Metabox::save(11, (object) $GLOBALS['__posts'][11]);
check('title sync stores the post title', get_post_meta(11, '_tqk_wpseo_title', true) === 'Draft' && get_post_meta(11, Metabox::TITLE_SYNC, true) === '1');
check('social sync empties share texts', get_post_meta(11, '_tqk_wpseo_og_title', true) === '');
$_POST = ['tqk-wpseo-metabox' => 'valid', '_tqk_wpseo_title' => 'New Title', '_tqk_wpseo_og_title' => 'Own share'];
Metabox::save(11, (object) $GLOBALS['__posts'][11]);
check('unticked: typed title kept, flag removed', get_post_meta(11, '_tqk_wpseo_title', true) === 'New Title' && get_post_meta(11, Metabox::TITLE_SYNC, true) === '');
check('unticked: own share title kept', get_post_meta(11, '_tqk_wpseo_og_title', true) === 'Own share');
delete_post_meta(11, '_tqk_wpseo_og_title');

// Save without nonce does nothing.
$_POST = ['_tqk_wpseo_title' => 'Nope'];
Metabox::save(11, (object) $GLOBALS['__posts'][11]);
check('nonce required', get_post_meta(11, '_tqk_wpseo_title', true) === 'New Title');

// Metabox assets only on post screens.
$GLOBALS['__enqueued'] = ['style' => [], 'script' => []];
\TQK_WPSEO\Admin\Assets::enqueue('post.php');
check('metabox js on post.php', in_array('tqk-wpseo-metabox', $GLOBALS['__enqueued']['script'], true));
check('media scripts loaded for the image picker', !empty($GLOBALS['__enqueued']['media']));
check('metabox css on post.php', in_array('tqk-wpseo-metabox', $GLOBALS['__enqueued']['style'], true));
$GLOBALS['__enqueued'] = ['style' => [], 'script' => []];
\TQK_WPSEO\Admin\Assets::enqueue('edit.php');
check('no metabox js on edit.php', !in_array('tqk-wpseo-metabox', $GLOBALS['__enqueued']['script'], true));

// ---------- Share image is a picker, not a URL box ----------
check('og image field type is image', (Metabox::fields()['_tqk_wpseo_og_image'][1] ?? '') === 'image');
$html = '';
Metabox::render($GLOBALS['__posts'][11]);
ob_start();
Metabox::render($GLOBALS['__posts'][11]);
$html = (string) ob_get_clean();
check('picker button rendered', str_contains($html, 'tqk-wpseo-image-pick'));
check('picker stores a hidden url, no visible url box', str_contains($html, 'data-image-url') && !str_contains($html, 'type="url" name="_tqk_wpseo_og_image"'));
check('social card preview still rendered', str_contains($html, 'tqk-wpseo-social-title'));

$_POST = [
    Metabox::NONCE => 'valid',
    '_tqk_wpseo_og_image' => 'https://cdn.test/picked.jpg',
    '_tqk_wpseo_robots' => 'index,follow',
];
Metabox::save(11, (object) $GLOBALS['__posts'][11]);
check('picked image saved', get_post_meta(11, '_tqk_wpseo_og_image', true) === 'https://cdn.test/picked.jpg');
$_POST = [Metabox::NONCE => 'valid', '_tqk_wpseo_og_image' => 'javascript:alert(1)'];
Metabox::save(11, (object) $GLOBALS['__posts'][11]);
check('javascript url in image is stripped', (string) get_post_meta(11, '_tqk_wpseo_og_image', true) === '');

echo $fail === 0 ? "\nALL S2 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
