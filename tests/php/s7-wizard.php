<?php
// S-wizard tests: redirect, steps, save, skip, finish. Run: php tests/php/s7-wizard.php
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

use TQK_WPSEO\Admin\Wizard;
use TQK_WPSEO\Sitemap\Sitemap;

call_user_func($GLOBALS['__activation']);
do_action('init');

check('activation flags wizard pending', get_option(Wizard::PENDING) == true);
check('wizard hooks registered', isset($GLOBALS['__actions']['admin_menu']) && isset($GLOBALS['__actions']['admin_init']));
check('seven steps', count(Wizard::steps()) === 7);

// Redirect when pending and not on wizard.
$_GET = [];
try {
    Wizard::maybe_redirect();
    check('redirect fires', false);
} catch (WP_Test_Response $e) {
    check('redirect fires', ($e->payload['redirect'] ?? '') === 'https://example.test/wp-admin/admin.php?page=tqk-wpseo-wizard');
}

// No redirect when already on wizard.
$_GET = ['page' => 'tqk-wpseo-wizard'];
Wizard::maybe_redirect();
check('no redirect on wizard page', true);

// Step 1 renders welcome + progress.
ob_start();
Wizard::render();
$w1 = (string) ob_get_clean();
check('welcome renders', str_contains($w1, 'Welcome to TQK WP SEO'));
check('progress shows 7 steps', substr_count($w1, '<li class=') === 7);

// AJAX step flow: save + advance, skip without save, finish clears.
function wiz_ajax($fields) {
    $_POST = $fields;
    try {
        Wizard::ajax();
    } catch (WP_Test_Response $e) {
        return $e->payload;
    }
    return null;
}

$r = wiz_ajax(['step' => 2, 'business_industry' => 'Nha khoa', 'business_name' => 'An Khang']);
check('step2 saved business', get_option('tqk_wpseo_settings')['business_industry'] === 'Nha khoa');
check('step2 advances to templates', ($r['success'] ?? false) === true && ($r['data']['step'] ?? 0) === 3 && str_contains($r['data']['html'] ?? '', 'Templates'));

$r = wiz_ajax(['step' => 3, 'title_template' => 'Custom %title%']);
check('step3 saved template', get_option('tqk_wpseo_settings')['title_template'] === 'Custom %title%');
check('step3 advances to indexability', ($r['success'] ?? false) === true && ($r['data']['step'] ?? 0) === 4 && str_contains($r['data']['html'] ?? '', 'Indexability'));

update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['title_template' => 'KeepMe']));
$r = wiz_ajax(['step' => 3, 'skip' => '1', 'title_template' => 'Changed']);
check('skip advances unsaved', ($r['data']['step'] ?? 0) === 4 && get_option('tqk_wpseo_settings')['title_template'] === 'KeepMe');

$r = wiz_ajax(['step' => 5, 'sitemap_enabled' => '1', 'sitemap_maps' => ['post' => '1']]);
check('maps saved', get_option('tqk_wpseo_settings')['sitemap_maps'] === ['post']);
check('maps filtered', array_keys(Sitemap::maps()) === ['post']);
check('index lists 1 map', substr_count(Sitemap::render_index(), '<sitemap>') === 1);

// Finish clears pending.
update_option(Wizard::PENDING, 1);
$r = wiz_ajax(['step' => 7]);
check('finish clears pending', ($r['data']['done'] ?? false) === true && get_option(Wizard::PENDING, false) == false);
check('finish shows dashboard link', str_contains($r['data']['html'] ?? '', 'Go to Dashboard'));

// No redirect after finish.
$_GET = [];
Wizard::maybe_redirect();
check('no redirect when done', true);

echo $fail === 0 ? "\nALL WIZARD CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
