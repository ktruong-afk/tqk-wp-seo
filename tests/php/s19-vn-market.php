<?php
// S19 tests: Vietnamese-market features — Yoast/Rank Math importer,
// malware/SEO-spam signatures, 2025 address reform, Vietnamese SEO text
// rules, "Giá: Liên hệ" products, white-label report aggregates.
// Run: php tests/php/s19-vn-market.php
require __DIR__ . '/stubs.php';
if (!function_exists('date_i18n')) {
    function date_i18n($f, $ts = false) {
        return date($f, $ts ?: time());
    }
}
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

use TQK_WPSEO\Analyzer\Text;
use TQK_WPSEO\Local\Address;
use TQK_WPSEO\Security\Scan;
use TQK_WPSEO\Tools\Importer;

$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__posts'][70] = ['ID' => 70, 'post_title' => 'Máy lọc nước', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'may-loc-nuoc',
    'post_author' => 1, 'post_date' => '2026-01-01', 'post_modified' => '2026-01-02', 'post_excerpt' => '', 'post_content' => '<p>x</p>'];
$GLOBALS['__posts'][71] = $GLOBALS['__posts'][70];
$GLOBALS['__posts'][71]['ID'] = 71;

// ---------- importer: variables ----------
check('yoast vars → ours', Importer::convert_vars('%%title%% %%sep%% %%sitename%%', 'yoast', '|') === '%title% | %site_name%');
check('rank math vars → ours', Importer::convert_vars('%title% %sep% %sitename%', 'rankmath', '-') === '%title% - %site_name%');
check('unknown vars dropped, dangling separator tidied', Importer::convert_vars('%%title%% %%sep%% %%page%% %%sep%% %%sitename%%', 'yoast', '-') === '%title% - %site_name%');
check('plain text untouched', Importer::convert_vars('Mua máy lọc nước giá tốt', 'yoast') === 'Mua máy lọc nước giá tốt');

// ---------- importer: Yoast post ----------
foreach (['_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%', '_yoast_wpseo_metadesc' => 'Máy lọc nước chính hãng.',
    '_yoast_wpseo_focuskw' => 'máy lọc nước', '_yoast_wpseo_focuskeywords' => json_encode([['keyword' => 'lọc nước RO', 'score' => 50]]),
    '_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_meta-robots-nofollow' => '1',
    '_yoast_wpseo_opengraph-image' => 'https://s.test/og.jpg', '_yoast_wpseo_schema_article_type' => 'NewsArticle'] as $k => $val) {
    update_post_meta(70, $k, wp_slash($val)); // meta API unslashes, as in WordPress
}
$v = Importer::values_for(70, 'yoast');
check('yoast: title converted', ($v['_tqk_wpseo_title'] ?? '') === '%title% - %site_name%');
check('yoast: description + focus + secondary', $v['_tqk_wpseo_description'] === 'Máy lọc nước chính hãng.' && $v['_tqk_wpseo_focus_keyword'] === 'máy lọc nước' && $v['_tqk_wpseo_secondary_keywords'] === 'lọc nước RO');
check('yoast: noindex+nofollow → ours', $v['_tqk_wpseo_robots'] === 'noindex,nofollow');
check('yoast: NewsArticle → Article', $v['_tqk_wpseo_schema_type'] === 'Article');
check('yoast: og image copied', $v['_tqk_wpseo_og_image'] === 'https://s.test/og.jpg');

update_post_meta(70, '_tqk_wpseo_description', 'Mô tả đã có');
$r = Importer::import_post(70, 'yoast');
check('import: fills empty, keeps existing', get_post_meta(70, '_tqk_wpseo_description', true) === 'Mô tả đã có' && $r['kept'] === 1 && $r['written'] >= 6);
check('import: title stored with our tags', get_post_meta(70, '_tqk_wpseo_title', true) === '%title% - %site_name%');
Importer::import_post(70, 'yoast', true);
check('import: overwrite when asked', get_post_meta(70, '_tqk_wpseo_description', true) === 'Máy lọc nước chính hãng.');

$data = \TQK_WPSEO\Core\Data::for(['type' => 'singular', 'object_id' => 70, 'post_type' => 'post']);
check('imported per-post title expands tags on output', str_starts_with((string) $data['title'], 'Máy lọc nước - '));

// ---------- importer: Rank Math post ----------
update_post_meta(71, 'rank_math_title', '%title% %sep% Giá tốt');
update_post_meta(71, 'rank_math_focus_keyword', 'máy lọc nước,lọc nước RO,máy lọc Kangaroo');
update_post_meta(71, 'rank_math_robots', ['nofollow']);
update_post_meta(71, 'rank_math_rich_snippet', 'product');
$v = Importer::values_for(71, 'rankmath');
check('rank math: first keyword = focus, rest = secondary', $v['_tqk_wpseo_focus_keyword'] === 'máy lọc nước' && $v['_tqk_wpseo_secondary_keywords'] === 'lọc nước RO, máy lọc Kangaroo');
check('rank math: product schema', $v['_tqk_wpseo_schema_type'] === 'Product');
check('rank math: nofollow-only is flagged lossy, not guessed', !isset($v['_tqk_wpseo_robots']) && Importer::import_post(71, 'rankmath')['lossy'] === true);
check('source validation', Importer::valid_source('yoast') === 'yoast' && Importer::valid_source('evil') === '');

// ---------- security signatures ----------
// Malware samples are assembled at runtime ($ev, $po...) so this test file
// never contains the literal patterns and the scanner does not flag itself.
$ev = 'ev' . 'al';
$b64 = 'base64' . '_decode';
$post = '$_' . 'POST';
check('sig: eval(base64_decode) critical', Scan::scan_code("<?php\n\n@{$ev}({$b64}('ZWNobyAx'));")[0]['id'] === 'eval_decode');
check('sig: line number reported', Scan::scan_code("<?php\n\n@{$ev}({$b64}('x'));")[0]['line'] === 3);
check('sig: function from $_POST', Scan::scan_code('<?php ' . $post . '["f"](' . $post . '["a"]);')[0]['id'] === 'call_input');
check('sig: hidden admin creation', Scan::scan_code('<?php wp_insert' . '_user(["user_login"=>"x","role"=>"admin' . 'istrator"]);')[0]['id'] === 'create_admin');
check('sig: nulled marker', Scan::scan_code('/* Nul' . 'led by bab' . 'iato */')[0]['id'] === 'nulled');
check('sig: normal plugin code is clean', Scan::scan_code('<?php $x = base64_decode($row->data); echo esc_html($x); add_action("init", "f");') === []);
check('stub: text-only / comment-only index.php is harmless', Scan::is_harmless_stub('Kangaroos cannot jump here') && Scan::is_harmless_stub("<?php\n// Silence is golden.\n") && Scan::is_harmless_stub('<?php /* nothing */ ?>'));
check('stub: any real code is not a stub', !Scan::is_harmless_stub('<?php echo 1;') && !Scan::is_harmless_stub("<?php\n// hi\n" . $ev . '($x);'));
check('uploads: php-like names flagged', Scan::is_executable_name('shell.php') && Scan::is_executable_name('x.PHTML') && !Scan::is_executable_name('photo.jpg'));

$html = '<div style="display:none"><a href="https://nhacai-xyz.com">nhà cái uy tín</a><a href="https://ok.test">Đối tác</a></div><p><a href="https://essex.ac.uk">Essex</a></p>';
$hidden = Scan::hidden_spam_links($html, 'site.test');
check('spam: hidden gambling link found', count($hidden) === 1 && str_starts_with($hidden[0], 'nhacai-xyz.com'));
check('spam: whole words only (Essex is not "sex")', Scan::spam_links_in('<a href="https://essex.ac.uk">Essex unisex</a>', 'site.test') === []);
check('spam: own domain ignored', Scan::spam_links_in('<a href="https://site.test/casino">casino</a>', 'site.test') === []);

$user = '<html><head><title>Máy lọc nước</title></head><body><a href="https://facebook.com/x">fb</a> nội dung</body></html>';
$bot = '<html><head><title>Nhà cái uy tín 2026</title></head><body><a href="https://bet-abc.com">cá cược</a> nội dung</body></html>';
$why = Scan::compare_cloak($bot, $user, 'site.test');
check('cloak: different title + bot-only links + spam', count($why) === 3);
check('cloak: same page → nothing', Scan::compare_cloak($user, $user, 'site.test') === []);

// ---------- address reform ----------
$a = Address::check('123 Đại lộ Bình Dương, P. Phú Hòa, TP. Thủ Dầu Một, Bình Dương');
check('address: Bình Dương → TP. Hồ Chí Minh', $a['old'] === 'Bình Dương' && $a['new'] === 'TP. Hồ Chí Minh');
check('address: Bà Rịa - Vũng Tàu variants', Address::check('Số 5 Thùy Vân, Vũng Tàu')['new'] === 'TP. Hồ Chí Minh');
check('address: district level flagged', Address::check('12 Lê Lợi, Quận 1, TP. Hồ Chí Minh')['district'] && Address::check('Huyện Củ Chi, TP HCM')['district']);
check('address: new-style address is clean', Address::check('12 Lê Lợi, Phường Sài Gòn, TP. Hồ Chí Minh')['messages'] === []);
check('address: Hà Nội is not Hà Nam', Address::check('Số 1 Tràng Tiền, Hà Nội')['old'] === null);
check('address: 34 provinces, 29 absorbed', count(Address::current()) === 34 && count(array_unique(Address::current())) === 34 && count(Address::merged()) === 29);
check('address: every merge target is a current province', array_diff(array_unique(array_values(Address::merged())), Address::current()) === []);
$p = Address::postal('12 Lê Lợi, Phường Sài Gòn, TP. Hồ Chí Minh');
check('schema: PostalAddress gets region + VN', ($p['addressRegion'] ?? '') === 'TP. Hồ Chí Minh' && ($p['addressCountry'] ?? '') === 'VN');

// ---------- Vietnamese text rules ----------
check('phrase: accent-insensitive', Text::has_phrase('Hướng dẫn pha cà phê ngon', 'ca phe'));
check('phrase: syllable-bounded ("an" not in "bàn")', !Text::has_phrase('Bộ bàn ghế gỗ', 'an'));
check('vietnamese detection', Text::is_vietnamese(str_repeat('Hướng dẫn chọn máy lọc nước phù hợp cho gia đình. ', 3)) && !Text::is_vietnamese(str_repeat('How to choose a water filter for your family. ', 3)));
$vi_long = '<p>' . str_repeat('Máy lọc nước giúp gia đình có nguồn nước sạch mỗi ngày ', 3) . '.</p>'; // ~36 syllables, 1 sentence
$GLOBALS['__posts'][72] = ['ID' => 72, 'post_title' => 'Test', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 't', 'post_author' => 1,
    'post_date' => '2026-01-01', 'post_modified' => '2026-01-02', 'post_excerpt' => '', 'post_content' => str_repeat('<p>Máy lọc nước giúp gia đình có nước sạch mỗi ngày.</p>', 30)];
$ids = array_column(\TQK_WPSEO\Analyzer\Analyzer::analyze(72)['findings'], 'id');
check('VI: 12-syllable sentences are not "long"', !in_array('long_sentences', $ids, true));
check('VI: 360 syllables is still thin (under 500)', in_array('thin_content', $ids, true));

// ---------- "Giá: Liên hệ" ----------
check('no price: empty / 0 / text', \TQK_WPSEO\Schema\Nodes::no_price('') && \TQK_WPSEO\Schema\Nodes::no_price('0') && \TQK_WPSEO\Schema\Nodes::no_price('Liên hệ') && !\TQK_WPSEO\Schema\Nodes::no_price('150000'));
$GLOBALS['__posts'][73] = $GLOBALS['__posts'][70];
$GLOBALS['__posts'][73]['ID'] = 73;
$GLOBALS['__posts'][73]['post_type'] = 'product';
$d73 = ['title' => 'SP', 'description' => '', 'canonical' => 'https://s.test/sp/'];
check('liên hệ: default skips Product node', \TQK_WPSEO\Schema\Nodes::product((object) $GLOBALS['__posts'][73], $d73) === []);
$GLOBALS['__options']['tqk_wpseo_settings']['product_no_price'] = 'no_offers';
$node = \TQK_WPSEO\Schema\Nodes::product((object) $GLOBALS['__posts'][73], $d73);
check('liên hệ: no_offers mode keeps Product without offers', ($node['@type'] ?? '') === 'Product' && !isset($node['offers']));
update_post_meta(73, '_price', '0');
check('price 0 is never printed as an offer', !isset(\TQK_WPSEO\Schema\Nodes::product((object) $GLOBALS['__posts'][73], $d73)['offers']));

// ---------- report aggregates ----------
$rows = [
    [['id' => 1], ['seo' => ['score' => 40, 'findings' => [['sev' => 'critical', 'msg' => 'Missing meta description'], ['sev' => 'warning', 'msg' => 'Title over 60 chars (72)']]], 'vps' => [['score' => 60]], 'fields' => []]],
    [['id' => 2], ['seo' => ['score' => 80, 'findings' => [['sev' => 'warning', 'msg' => 'Title over 60 chars (65)']]], 'vps' => [['score' => 100]], 'fields' => []]],
    [['id' => 3], ['error' => 'timeout']],
];
$sum = \TQK_WPSEO\Report\Report::summarize($rows);
check('report: averages skip failed pages', $sum['seo_avg'] === 60 && $sum['rs_avg'] === 80 && $sum['failed'] === 1 && $sum['pages'] === 3);
check('report: same issue with different numbers grouped', ($sum['top']['Title over chars'] ?? 0) === 2);
check('report: critical counted', $sum['critical'] === 1);

echo $fail === 0 ? "\nALL S19 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
