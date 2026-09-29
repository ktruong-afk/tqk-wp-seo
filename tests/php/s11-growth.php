<?php
// S11 tests: Local SEO NAP, Role Manager, Instant Indexing. Run: php tests/php/s11-growth.php
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

use TQK_WPSEO\Admin\Access;
use TQK_WPSEO\Admin\Settings;
use TQK_WPSEO\Core\Modules;
use TQK_WPSEO\Indexing\Submitter;
use TQK_WPSEO\Schema\Nodes;

call_user_func($GLOBALS['__activation']);
do_action('init');
update_option('tqk_wpseo_settings', \TQK_WPSEO\Core\Activator::default_settings());

// ---------- Local SEO: business profile → LocalBusiness NAP ----------
$org = Nodes::organization();
check('org default type', ($org['@type'] ?? '') === 'Organization');

update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), [
    'business_name' => 'Nha khoa An Khang',
    'business_description' => 'Nha khoa uy tin',
    'business_phone' => '0901234567',
    'business_address' => '123 Le Loi, Q1',
    'business_hours' => 'Mo-Fr 08:00-17:00',
]));
$lb = Nodes::organization();
check('localbusiness type', ($lb['@type'] ?? '') === 'LocalBusiness');
check('localbusiness nap', ($lb['telephone'] ?? '') === '0901234567'
    && ($lb['address']['streetAddress'] ?? '') === '123 Le Loi, Q1'
    && ($lb['openingHours'] ?? '') === 'Mo-Fr 08:00-17:00'
    && ($lb['name'] ?? '') === 'Nha khoa An Khang');

Modules::set('local-seo', false);
$off = Nodes::organization();
check('localbusiness gated by module', ($off['@type'] ?? '') === 'Organization');
Modules::set('local-seo', true);

// ---------- Role Manager ----------
check('access can for admin', Access::can() === true);
check('access roles list', Access::roles() === ['editor', 'author', 'contributor']);
check('access states default off', Access::states() === ['editor' => false, 'author' => false, 'contributor' => false]);
Access::sync();
check('access sync safe without role api', true);
$clean = Settings::sanitize(['seo_roles' => ['editor' => '1', 'boss' => '1']]);
check('roles sanitized to allowlist', ($clean['seo_roles'] ?? []) === ['editor' => true, 'author' => false, 'contributor' => false]);
ob_start();
\TQK_WPSEO\Admin\Pages::settings();
$sp = (string) ob_get_clean();
check('settings has role card', str_contains($sp, 'Role Manager') && str_contains($sp, 'settings[seo_roles][editor]'));

// ---------- Instant Indexing ----------
check('submit skips when module off', Submitter::submit('https://example.test/x/') === []);
Modules::set('instant-indexing', true);
check('submit skips when engines off', Submitter::submit('https://example.test/x/') === []);
check('google needs token', Submitter::google('https://example.test/x/') === ['ok' => false, 'message' => 'Missing GSC OAuth token.']);
check('bing needs key', Submitter::bing('https://example.test/x/') === ['ok' => false, 'message' => 'Missing Bing API key.']);

update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), [
    'indexing_google' => true, 'indexing_bing' => true,
    'gsc_token' => 'tok123', 'bing_api_key' => 'key123',
]));
$GLOBALS['__http_queue'][] = ['code' => 200, 'body' => '{}'];
$GLOBALS['__http_queue'][] = ['code' => 200, 'body' => '{}'];
$r = Submitter::submit('https://example.test/hello/');
check('submit pings both', ($r['google']['ok'] ?? false) === true && ($r['bing']['ok'] ?? false) === true);
check('submit logged', count(Submitter::recent()) === 1 && str_contains((string) (Submitter::recent()[0]['url'] ?? ''), 'hello'));

$GLOBALS['__http_queue'][] = ['code' => 403, 'body' => '{}'];
$bad = Submitter::google('https://example.test/x/');
check('google rejects bad token', ($bad['ok'] ?? true) === false);

// on_save gating.
$GLOBALS['__posts'][91] = ['ID' => 91, 'post_title' => 'Draft One', 'post_content' => 'x', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'draft', 'post_name' => 'draft-one', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
Submitter::on_save(91, (object) $GLOBALS['__posts'][91]);
check('on_save skips drafts', count(Submitter::recent()) === 1);

$GLOBALS['__posts'][92] = ['ID' => 92, 'post_title' => 'Live One', 'post_content' => 'x', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'live-one', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
$GLOBALS['__http_queue'][] = ['code' => 200, 'body' => '{}'];
$GLOBALS['__http_queue'][] = ['code' => 200, 'body' => '{}'];
Submitter::on_save(92, (object) $GLOBALS['__posts'][92]);
check('on_save pings on publish', count(Submitter::recent()) === 2);

$GLOBALS['__postmeta_map'][93] = ['_tqk_wpseo_sitemap_exclude' => '1'];
$GLOBALS['__posts'][93] = ['ID' => 93, 'post_title' => 'Hidden One', 'post_content' => 'x', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'hidden-one', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
Submitter::on_save(93, (object) $GLOBALS['__posts'][93]);
check('on_save respects sitemap exclude', count(Submitter::recent()) === 2);
Modules::set('instant-indexing', false);

echo $fail === 0 ? "\nALL GROWTH CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
