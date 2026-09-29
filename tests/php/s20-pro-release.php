<?php
// S20 tests: License / Pro gating, free manual steps, site-wide images,
// background override CSS, <picture> art direction, term SEO meta,
// report language, uninstall cleanup.
// Run: php tests/php/s20-pro-release.php
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

use TQK_WPSEO\Analyzer\ImageFix;
use TQK_WPSEO\Core\Pro;

$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();

// ---------- Pro is a separate add-on ----------
check('no License code left in the free plugin', !class_exists('TQK_WPSEO\Core\License') && !file_exists(dirname(__DIR__, 2) . '/src/Core/License.php'));
check('no file generator left in the free plugin', !method_exists(ImageFix::class, 'generate') && !method_exists(ImageFix::class, 'make'));
check('free by default (no add-on)', !Pro::installed() && !Pro::active());
add_filter('tqk_wpseo_is_pro', '__return_true');
check('one add_filter line cannot unlock Pro', !Pro::active());
remove_filter('tqk_wpseo_is_pro', '__return_true');
check('free banner has no PRO', Pro::banner() === 'TQK WP SEO v' . TQK_WPSEO_VERSION);
check('free settings box invites to get the add-on', str_contains(Pro::settings_html(), 'Pro add-on') || str_contains(Pro::settings_html(), 'TQK WP SEO Pro'));

class FakeAddon implements \TQK_WPSEO\Core\ProAddon {
    public bool $ok = true;
    public function version(): string { return '9.9'; }
    public function is_licensed(): bool { return $this->ok; }
    public function fingerprint(): string { return 'a1b2c3d4e5f6'; }
    public function settings_html(): string { return '<div id="tqk-license">addon</div>'; }
}
$fake = new FakeAddon();
Pro::register($fake);
check('add-on installed + licensed → Pro', Pro::installed() && Pro::active());
check('pro banner carries the add-on fingerprint', Pro::banner() === 'TQK WP SEO PRO v' . TQK_WPSEO_VERSION . ' · a1b2c3d4e5f6');
ob_start();
\TQK_WPSEO\Core\Frontend::head();
$head = (string) ob_get_clean();
check('banner printed around the SEO tags', str_contains($head, '<!-- TQK WP SEO PRO v') && str_contains($head, '<!-- / TQK WP SEO -->'));
add_filter('tqk_wpseo_is_pro', '__return_false');
check('filter can still turn Pro off', !Pro::active());
remove_filter('tqk_wpseo_is_pro', '__return_false');
$fake->ok = false;
check('add-on without license → free', !Pro::active() && Pro::installed());
check('settings box comes from the add-on', Pro::settings_html() === '<div id="tqk-license">addon</div>');
$fake->ok = true;
// Licensed add-on but nothing hooked to generate → still refused (no code to run).
$GLOBALS['__posts'][61] = ['ID' => 61, 'post_title' => 'P', 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'p', 'post_author' => 1, 'post_date' => '', 'post_modified' => '', 'post_excerpt' => '', 'post_content' => ''];
$_POST = ['page_id' => '61', 'op' => 'generate'];
try {
    ImageFix::ajax();
    $out = null;
} catch (WP_Test_Response $e) {
    $out = $e->payload;
}
check('generate without the add-on generator → Pro message', is_array($out) && empty($out['success']) && str_contains((string) ($out['data']['message'] ?? ''), 'Pro'));
add_filter('tqk_wpseo_image_generate', fn($r, $scope) => ['made' => 3, 'errors' => []], 10, 2);
try {
    ImageFix::ajax();
    $out = null;
} catch (WP_Test_Response $e) {
    $out = $e->payload;
}
check('generate delegated to the add-on', is_array($out) && !empty($out['success']) && str_contains((string) $out['data']['message'], '3'));
unset($GLOBALS['__actions']['tqk_wpseo_image_generate']);
$_POST = [];
Pro::reset();

// ---------- Pro gating on the image optimizer ----------
$GLOBALS['__posts'][60] = ['ID' => 60, 'post_title' => 'P', 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'p', 'post_author' => 1, 'post_date' => '', 'post_modified' => '', 'post_excerpt' => '', 'post_content' => ''];
foreach (['generate', 'activate'] as $op) {
    $_POST = ['page_id' => '60', 'op' => $op];
    try {
        ImageFix::ajax();
        $out = null;
    } catch (WP_Test_Response $e) {
        $out = $e->payload;
    }
    check("free: {$op} refused with a Pro message", is_array($out) && empty($out['success']) && str_contains((string) ($out['data']['message'] ?? ''), 'Pro'));
}
$_POST = ['page_id' => '60', 'op' => 'deactivate'];
try {
    ImageFix::ajax();
    $out = null;
} catch (WP_Test_Response $e) {
    $out = $e->payload;
}
check('free: deactivate still allowed (never stuck with files)', is_array($out) && !empty($out['success']));
$_POST = [];

// ---------- free: manual steps ----------
$big = ['kind' => 'img', 'src' => '/u/hero.jpg', 'srcset' => false, 'sizes' => '',
    'vp' => ['desktop' => ['w' => 600, 'h' => 400, 'nw' => 3000, 'nh' => 2000, 'fit' => 'fill', 'pos' => '50% 50%', 'cur' => '/u/hero.jpg'],
             'mobile' => ['w' => 343, 'h' => 229, 'nw' => 3000, 'nh' => 2000, 'fit' => 'fill', 'pos' => '50% 50%', 'cur' => '/u/hero.jpg']]];
$a = ImageFix::analyze($big, ['w' => 3000, 'h' => 2000]);
$steps = ImageFix::manual_steps($a, ['w' => 3000, 'h' => 2000], 'img');
check('manual steps: widths, WebP, srcset/sizes', count($steps) === 3 && str_contains($steps[0], '1200') && str_contains($steps[1], 'WebP') && str_contains($steps[2], 'sizes='));
check('manual steps: nothing to do → none', ImageFix::manual_steps(['issues' => [], 'crop' => null, 'widths' => [], 'need' => 0, 'sizes' => ''], ['w' => 10, 'h' => 10], 'img') === []);

// ---------- background override (Elementor-style CSS file) ----------
check('selector: id and elementor class accepted', ImageFix::clean_selector('#hero') === '#hero' && ImageFix::clean_selector('.elementor-element-3a9f2c1') === '.elementor-element-3a9f2c1');
check('selector: anything broader refused', ImageFix::clean_selector('div') === '' && ImageFix::clean_selector('.hero, body') === '' && ImageFix::clean_selector('#a{x}') === '');

$bg = ['kind' => 'bg', 'src' => '/u/bg.jpg', 'inline' => false, 'sel' => '.elementor-element-3a9f2c1', 'srcset' => false, 'sizes' => '',
    'vp' => ['desktop' => ['w' => 1440, 'h' => 600, 'nw' => 0, 'nh' => 0, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => '/u/bg.jpg'],
             'mobile' => ['w' => 375, 'h' => 500, 'nw' => 0, 'nh' => 0, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => '/u/bg.jpg']]];
$a = ImageFix::analyze($bg, ['w' => 6000, 'h' => 3000]);
check('bg in CSS file with a hook → fixable, one width per screen', $a['fixable'] && $a['bgw'] === ['desktop' => 2880, 'mobile' => 750]);
$css = ImageFix::bg_css([['kind' => 'bg', 'sel' => '.elementor-element-3a9f2c1', 'bg' => ['mobile' => '/a/m.webp', 'desktop' => '/a/d.webp']]]);
check('bg css: phone file under max-width 767', str_contains($css, '@media (max-width: 767px){html body .elementor-element-3a9f2c1{background-image:url("/a/m.webp")!important}}'));
check('bg css: desktop file from 1440', str_contains($css, '@media (min-width: 1440px){html body .elementor-element-3a9f2c1{background-image:url("/a/d.webp")!important}}'));
check('bg css: unsafe selector stored in a plan is still ignored', ImageFix::bg_css([['kind' => 'bg', 'sel' => 'body', 'bg' => ['mobile' => '/x.webp']]]) === '');

// ---------- <picture> art direction ----------
$art = ['kind' => 'img', 'src' => '/u/a.jpg', 'srcset' => true, 'wdesc' => true, 'sizes' => '100vw',
    'vp' => ['desktop' => ['w' => 1200, 'h' => 400, 'nw' => 2400, 'nh' => 1600, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => '/u/a.jpg'],
             'mobile' => ['w' => 375, 'h' => 375, 'nw' => 2400, 'nh' => 1600, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => '/u/a.jpg']]];
$a = ImageFix::analyze($art, ['w' => 2400, 'h' => 1600]);
check('art: a region per screen', isset($a['art']['desktop'], $a['art']['mobile']) && $a['art']['mobile'][2] === $a['art']['mobile'][3] && $a['crop'] === null);
check('art: widths are 1x/2x of each screen', in_array(380, $a['widths'], true) && in_array(750, $a['widths'], true) && in_array(2400, $a['widths'], true));
$entry = ['kind' => 'img', 'src' => 'https://s.test/u/a.jpg', 'files' => [['w' => 750, 'url' => '/p/m.webp']],
    'art' => ['mobile' => [['w' => 380, 'url' => '/p/m1.webp'], ['w' => 750, 'url' => '/p/m2.webp']], 'desktop' => [['w' => 1200, 'url' => '/p/d.webp']]],
    'art_sizes' => ['mobile' => '100vw', 'desktop' => '1200px']];
$html = '<html><head></head><body><img class="x" src="https://s.test/u/a.jpg" alt=""><picture><img src="https://s.test/u/a.jpg"></picture></body></html>';
$out = ImageFix::rewrite($html, ['a1' => $entry]);
check('art: <img> wrapped in <picture> with a source per screen', str_contains($out, '<picture class="tqk-art"><source media="(max-width: 767px)" srcset="/p/m1.webp 380w, /p/m2.webp 750w" sizes="100vw"><source media="(min-width: 1440px)"'));
check('art: an <img> already inside <picture> is left alone', substr_count($out, '<picture') === 2);
check('art: display:contents keeps layout', str_contains($out, 'picture.tqk-art{display:contents}') && strpos($out, '<style id="tqk-wpseo-img-fix">') < strpos($out, '</head>'));

// ---------- site-wide images ----------
$logo = fn(int $w) => ['kind' => 'img', 'src' => '/u/logo.png', 'att' => 0, 'vp' => ['desktop' => ['w' => $w, 'h' => 40], 'mobile' => ['w' => 120, 'h' => 36]]];
$hero = ['kind' => 'img', 'src' => '/u/only-here.jpg', 'att' => 0, 'vp' => ['desktop' => ['w' => 800, 'h' => 400]]];
$shared = ImageFix::shared([1 => [$logo(132), $hero], 2 => [$logo(130)], 3 => [$logo(132)]]);
check('site-wide: logo on 3 pages, same box → shared', count($shared) === 1 && reset($shared)['pages'] === 3);
check('site-wide: largest box kept', reset($shared)['vp']['desktop']['w'] === 132);
check('site-wide: image on one page stays page-level', !isset($shared['img|' . '//u/only-here.jpg']) && count($shared) === 1);
$shared = ImageFix::shared([1 => [$logo(132)], 2 => [$logo(300)]]);
check('site-wide: different sizes per page → not shared', $shared === []);
check('scope parser', ImageFix::scope('site') === 'site' && ImageFix::scope('12') === 12 && ImageFix::scope('x') === 0);

// ---------- term SEO meta ----------
$clean = \TQK_WPSEO\Admin\TermFields::clean([
    '_tqk_wpseo_title' => '<b>Máy lọc</b> nước', '_tqk_wpseo_robots' => 'evil', '_tqk_wpseo_canonical' => 'javascript:alert(1)', 'other' => 'x',
]);
check('term fields: sanitized, unknown robots / js URL dropped', $clean['_tqk_wpseo_title'] === 'Máy lọc nước' && $clean['_tqk_wpseo_robots'] === '' && $clean['_tqk_wpseo_canonical'] === '' && !isset($clean['other']));

$GLOBALS['__queried_object'] = (object) ['term_id' => 9, 'name' => 'Máy lọc nước', 'taxonomy' => 'category'];
update_term_meta(9, '_tqk_wpseo_title', '%title% giá tốt | %site_name%');
update_term_meta(9, '_tqk_wpseo_description', 'Chuyên mục máy lọc nước chính hãng.');
update_term_meta(9, '_tqk_wpseo_robots', 'noindex,follow');
$d = \TQK_WPSEO\Core\Data::for(['type' => 'archive', 'subtype' => 'term', 'term_id' => 9, 'taxonomy' => 'category']);
check('term: own title with tags', str_starts_with($d['title'], 'Máy lọc nước giá tốt | '));
check('term: own description + robots', $d['description'] === 'Chuyên mục máy lọc nước chính hãng.' && $d['robots']['index'] === false);

$GLOBALS['__options']['wpseo_taxonomy_meta'] = ['category' => [5 => ['wpseo_title' => '%%term_title%% %%sep%% %%sitename%%', 'wpseo_desc' => 'Mô tả Yoast', 'wpseo_noindex' => 'noindex']]];
$tv = \TQK_WPSEO\Tools\Importer::term_values('yoast');
check('import terms (Yoast option): description + noindex', ($tv[5]['_tqk_wpseo_description'] ?? '') === 'Mô tả Yoast' && ($tv[5]['_tqk_wpseo_robots'] ?? '') === 'noindex,follow');
$r = \TQK_WPSEO\Tools\Importer::import_terms('yoast');
check('import terms: written to term meta', $r['terms'] === 1 && get_term_meta(5, '_tqk_wpseo_description', true) === 'Mô tả Yoast');

// ---------- report language ----------
\TQK_WPSEO\Report\Report::use_locale('');
check('report language: "" leaves the locale alone', apply_filters('determine_locale', 'en_US') === 'en_US');
\TQK_WPSEO\Report\Report::use_locale('vi');
check('report language: vi forces the Vietnamese locale', apply_filters('determine_locale', 'en_US') === 'vi');

// ---------- redirects: no chain to the trailing-slash URL ----------
use TQK_WPSEO\Redirect\Redirects;
check('redirect target gets the site trailing slash (no 2nd hop)', Redirects::target_path('/sample-page') === '/sample-page/');
check('redirect target keeps its query string', Redirects::target_path('/shop?orderby=price') === '/shop/?orderby=price');
check('redirect target to a file keeps no slash', Redirects::target_path('/files/guide.pdf') === '/files/guide.pdf');
check('redirect loop still rejected with/without slash', Redirects::add('/loop', '/loop/', 301) === 0);

// ---------- 404 monitor noise ----------
use TQK_WPSEO\Redirect\Monitor404;
check('404: DevTools source maps ignored', Monitor404::ignored('/wp-content/plugins/x/assets/app.min.js.map'));
check('404: wp-admin / wp-json ignored', Monitor404::ignored('/wp-admin/tqk-wpseo-site-analysis') && Monitor404::ignored('/wp-json/foo'));
check('404: real broken pages and images still logged', !Monitor404::ignored('/old-product') && !Monitor404::ignored('/wp-content/uploads/2024/01/a.jpg') && !Monitor404::ignored('/wp-admin-tips'));

// ---------- internal links ----------
use TQK_WPSEO\Links\Engine as LinkEngine;
$GLOBALS['wpdb'] = new FakeWPDB();
$GLOBALS['__options']['tqk_wpseo_modules'] = ['links' => true];
foreach ([80 => 'Giới thiệu', 81 => 'Giỏ hàng', 82 => 'Bài không ai trỏ tới', 83 => 'Trang noindex'] as $id => $title) {
    $GLOBALS['__posts'][$id] = ['ID' => $id, 'post_title' => $title, 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'p' . $id, 'post_author' => 1, 'post_date' => '', 'post_modified' => '', 'post_excerpt' => '', 'post_content' => ''];
}
// A menu item pointing at page 80 BY ID (no _menu_item_url): the most common kind.
$GLOBALS['__posts'][90] = ['ID' => 90, 'post_title' => 'Giới thiệu', 'post_type' => 'nav_menu_item', 'post_status' => 'publish', 'post_name' => 'm90', 'post_author' => 1, 'post_date' => '', 'post_modified' => '', 'post_excerpt' => '', 'post_content' => ''];
update_post_meta(90, '_menu_item_type', 'post_type');
update_post_meta(90, '_menu_item_object_id', '80');
LinkEngine::rebuild_menus();
$GLOBALS['__options']['woocommerce_cart_page_id'] = 81;
update_post_meta(83, '_tqk_wpseo_robots', 'noindex,follow');
$orphans = LinkEngine::orphan_ids();
check('links: menu item by page ID counts as incoming', !in_array(80, $orphans, true));
check('links: WooCommerce cart page not an orphan', !in_array(81, $orphans, true));
check('links: noindex page not an orphan', !in_array(83, $orphans, true));
check('links: truly unlinked page is an orphan', in_array(82, $orphans, true));

check('links: admin / login / REST never HTTP-checked', LinkEngine::skip_check('https://s.test/wp-admin/') && LinkEngine::skip_check('/wp-login.php?x') && !LinkEngine::skip_check('https://s.test/category/tin-tuc/'));
for ($i = 1; $i <= 25; $i++) {
    $GLOBALS['wpdb']->insert(LinkEngine::table(), ['source_id' => 82, 'source_type' => 'page', 'target_id' => 0, 'url' => 'https://ext.test/' . $i, 'anchor' => 'x', 'is_internal' => 0, 'is_broken' => 0]);
}
$GLOBALS['__http_queue'] = array_fill(0, 30, ['code' => 200]);
[$c1, , $next] = LinkEngine::check_links(0);
[$c2, , $next2] = LinkEngine::check_links($next);
check('links: check moves forward by offset (no endless loop)', $c1 === 20 && $next === 20 && $c2 === 5 && $next2 === 25);
$GLOBALS['__options']['woocommerce_cart_page_id'] = 0;

// ---------- schema quality ----------
use TQK_WPSEO\Schema\Graph;
use TQK_WPSEO\Schema\Nodes;
$g = new Graph();
$g->add(['@type' => 'WebPage', '@id' => 'u#webpage', 'name' => 'A', 'description' => 'D', 'breadcrumb' => ['@id' => 'u#breadcrumb']]);
$g->add(['@type' => 'WebPage', '@id' => 'u#webpage', 'name' => 'B', 'url' => 'u']);
$nodes = $g->nodes();
check('schema: same @id merged into one node', count($nodes) === 1 && $nodes[0]['name'] === 'A' && $nodes[0]['url'] === 'u' && $nodes[0]['description'] === 'D');
$g->prune_refs();
check('schema: reference to a missing breadcrumb dropped', !isset($g->nodes()[0]['breadcrumb']));
$g->add(['@type' => 'BreadcrumbList', '@id' => 'u#breadcrumb']);
$g2 = (new Graph())->add(['@type' => 'WebPage', '@id' => 'v#webpage', 'breadcrumb' => ['@id' => 'v#breadcrumb']])->add(['@type' => 'BreadcrumbList', '@id' => 'v#breadcrumb'])->prune_refs();
check('schema: existing references kept', isset($g2->nodes()[0]['breadcrumb']));
check('schema: dates in ISO 8601', (bool) preg_match('/^2026-03-21T19:04:20[+-]\d\d:\d\d$/', Nodes::iso('2026-03-21 19:04:20')) && Nodes::iso('0000-00-00 00:00:00') === '');
$art = Nodes::article((object) ['post_date' => '2026-03-21 19:04:20', 'post_modified' => '2026-03-22 08:00:00', 'post_author' => 1], ['title' => 'T', 'canonical' => 'https://s.test/a/', 'og_image' => 'https://s.test/img.jpg']);
check('schema: Article has image, publisher, ISO dates', $art['image'] === ['https://s.test/img.jpg'] && $art['publisher']['@id'] === home_url('/#organization') && str_contains($art['datePublished'], 'T'));

// Archive canonical is the archive itself, never the homepage.
$GLOBALS['__queried_object'] = (object) ['term_id' => 12, 'name' => 'Tin tức', 'taxonomy' => 'category', 'slug' => 'tin-tuc'];
$d = \TQK_WPSEO\Core\Data::for(['type' => 'archive', 'subtype' => 'term', 'term_id' => 12, 'taxonomy' => 'category']);
check('canonical: category points to itself, not the homepage', $d['canonical'] !== home_url('/') && str_contains($d['canonical'], 'tin-tuc'));
$g3 = \TQK_WPSEO\Schema\Schema::build(['type' => 'archive', 'subtype' => 'term', 'term_id' => 12, 'taxonomy' => 'category'], $d);
$types = array_column($g3->nodes(), '@type');
check('schema: category page gets CollectionPage + BreadcrumbList', in_array('CollectionPage', $types, true) && in_array('BreadcrumbList', $types, true));
$dg = \TQK_WPSEO\Core\Data::for(['type' => 'archive', 'subtype' => 'date']);
check('canonical: date archive prints none rather than the homepage', $dg['canonical'] === '');

// ---------- private sitemap address ----------
use TQK_WPSEO\Sitemap\Sitemap;
check('sitemap name: sanitized', Sitemap::clean_slug(' Map-K7x2M9.xml ') === 'map-k7x2m9');
check('sitemap name: too short / bad chars / well-known refused', Sitemap::clean_slug('abc') === '' && Sitemap::clean_slug('my map!') === '' && Sitemap::clean_slug('sitemap_index') === '' && Sitemap::clean_slug('wp-sitemap') === '');
check('sitemap: private paths matched', Sitemap::match_private('/map-k7x2m9.xml', 'map-k7x2m9') === 'index' && Sitemap::match_private('/map-k7x2m9-post.xml', 'map-k7x2m9') === 'post' && Sitemap::match_private('/map-k7x2m9.xsl', 'map-k7x2m9') === 'xsl');
check('sitemap: other paths not matched', Sitemap::match_private('/sitemap_index.xml', 'map-k7x2m9') === '' && Sitemap::match_private('/map-k7x2m9.xml', '') === '');
$robots = "User-agent: *\nDisallow:\n\nSitemap: https://s.test/sitemap_index.xml\nSitemap: https://s.test/wp-sitemap.xml\n";
check('robots: private name → no Sitemap line at all', !str_contains(Sitemap::robots_sitemap_line($robots, 'map-k7x2m9', 'https://s.test/map-k7x2m9.xml'), 'Sitemap:'));
$pub = Sitemap::robots_sitemap_line("User-agent: *\nDisallow:\n", '', 'https://s.test/sitemap_index.xml');
check('robots: usual name → our sitemap listed once', substr_count($pub, 'Sitemap:') === 1 && str_contains($pub, 'sitemap_index.xml'));

$GLOBALS['__options']['tqk_wpseo_settings']['sitemap_slug'] = 'map-k7x2m9';
check('sitemap URLs follow the private name', str_ends_with(Sitemap::index_url(), '/map-k7x2m9.xml') && str_ends_with(Sitemap::map_url('page'), '/map-k7x2m9-page.xml'));
check('sitemap index links child maps by the private name', str_contains(Sitemap::render_index(), 'map-k7x2m9-post.xml') && !str_contains(Sitemap::render_index(), 'sitemap_index'));

$GLOBALS['__options']['tqk_wpseo_settings']['gsc_property'] = 'sc-domain:s.test';
$GLOBALS['__options']['tqk_wpseo_settings']['gsc_token'] = 'tok';
$GLOBALS['__requests'] = [];
$GLOBALS['__http_queue'] = [['code' => 204, 'body' => ''], ['code' => 204, 'body' => '']];
$r = \TQK_WPSEO\Google\SearchConsole::submit_sitemap('https://s.test/map-k7x2m9.xml', 'https://s.test/sitemap_index.xml');
check('Search Console: new sitemap PUT, old one DELETEd', $r['ok'] && $GLOBALS['__requests'][0][0] === 'PUT' && str_contains($GLOBALS['__requests'][0][1], rawurlencode('https://s.test/map-k7x2m9.xml')) && $GLOBALS['__requests'][1][0] === 'DELETE');
$GLOBALS['__http_queue'] = [['code' => 403, 'body' => json_encode(['error' => ['message' => 'User does not have sufficient permission']])]];
$r = \TQK_WPSEO\Google\SearchConsole::submit_sitemap('https://s.test/map-k7x2m9.xml');
check('Search Console: Google error shown as-is', !$r['ok'] && str_contains($r['message'], 'sufficient permission'));
$GLOBALS['__options']['tqk_wpseo_settings']['gsc_token'] = '';
check('Search Console: not connected → clear message, no request', !\TQK_WPSEO\Google\SearchConsole::submit_sitemap('https://s.test/x.xml')['ok']);
$GLOBALS['__options']['tqk_wpseo_settings']['sitemap_slug'] = '';

// ---------- post SEO box: honest checklist, score, live preview ----------
use TQK_WPSEO\Analyzer\Analyzer;
use TQK_WPSEO\Analyzer\AnalysisBox;
$GLOBALS['__options']['tqk_wpseo_settings']['title_template'] = '%title% | %site_name%';
$GLOBALS['__options']['tqk_wpseo_settings']['description_template'] = '';
$GLOBALS['__posts'][95] = ['ID' => 95, 'post_title' => 'Máy lọc nước RO', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'may-loc-nuoc-ro', 'post_author' => 1,
    'post_date' => '2026-01-01', 'post_modified' => '2026-01-02', 'post_excerpt' => '', 'post_content' => '<h1>Máy lọc nước RO</h1><p>' . str_repeat('Máy lọc nước RO cho gia đình. ', 40) . '</p>'];
$r = Analyzer::analyze(95);
$html = AnalysisBox::html($r, 95);
check('box: no keyword → keyword checks shown as not applicable, never ✓', !str_contains($html, '<span>✓</span> Keyword in title') && str_contains($html, '<span>–</span> Keyword in title'));
check('box: no description → length check not applicable', str_contains($html, '<span>–</span> Description ≤ 160 chars'));
check('score: a critical problem caps the score at 70', $r['score'] <= 70 && in_array('description_missing', array_column($r['findings'], 'id'), true));
check('score: weighted, content counts most', \TQK_WPSEO\Analyzer\Analyzer::overall(['content' => 0, 'technical' => 100, 'social' => 100, 'links' => 100, 'images' => 100, 'schema' => 100]) === 60);

$before = get_post_meta(95, Analyzer::META, true);
$live = Analyzer::analyze(95, null, ['focus_keyword' => 'may loc nuoc', 'description' => 'Máy lọc nước RO chính hãng, lắp đặt tận nhà, bảo hành 24 tháng cho gia đình và văn phòng tại Hà Nội.'], false);
$ids = array_column($live['findings'], 'id');
check('live: typed keyword used (accent-insensitive)', $live['keyword']['set'] && !in_array('kw_title', $ids, true) && !in_array('kw_description', $ids, true));
check('live: typed description removes the critical finding', !in_array('description_missing', $ids, true) && $live['score'] > $r['score']);
check('live: nothing saved', get_post_meta(95, Analyzer::META, true) === $before);
check('live: social card follows the typed description', !in_array('social_incomplete', $ids, true));
check('live: marked as not saved in the box', str_contains(AnalysisBox::html($live, 95, true), 'not saved yet'));

update_post_meta(95, Analyzer::RENDERED, wp_slash(json_encode(['at' => time() + 60, 'html' => '<h1>Rendered</h1><p>builder output</p>'])));
[, $src] = Analyzer::content_for((object) $GLOBALS['__posts'][95]);
check('builder pages: analysis reads the rendered page when fresh', $src === 'rendered');
update_post_meta(95, Analyzer::RENDERED, wp_slash(json_encode(['at' => 1, 'html' => '<p>old</p>'])));
[, $src] = Analyzer::content_for((object) $GLOBALS['__posts'][95]);
check('builder pages: stale rendered copy ignored after an edit', $src === 'editor');

// ---------- uninstall, executed ----------
$tmp = sys_get_temp_dir() . '/tqk-uninstall-' . getmypid();
@mkdir($tmp . '/audit-pics/13-page', 0777, true);
@mkdir($tmp . '/tqk-wpseo-audit/run1', 0777, true);
@mkdir($tmp . '/2026/03', 0777, true);
file_put_contents($tmp . '/audit-pics/13-page/a1-100x50.webp', 'x');
file_put_contents($tmp . '/tqk-wpseo-audit/run1/1.json', '{}');
file_put_contents($tmp . '/2026/03/photo.jpg', 'keep');
$GLOBALS['__uploads'] = $tmp;

class UninstallDB {
    public $prefix = 'wp_';
    public $options = 'wp_options';
    public $postmeta = 'wp_postmeta';
    public $termmeta = 'wp_termmeta';
    public $queries = [];
    public function query($q) {
        $this->queries[] = $q;
        return 1;
    }
}
$GLOBALS['wpdb'] = new UninstallDB();
$GLOBALS['__cleared'] = [];
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($h) {
        $GLOBALS['__cleared'][] = $h;
    }
}
class FakeRole {
    public $caps = ['tqk_seo_access' => true, 'edit_posts' => true];
    public function has_cap($c) {
        return !empty($this->caps[$c]);
    }
    public function remove_cap($c) {
        unset($this->caps[$c]);
    }
}
$GLOBALS['__role'] = new FakeRole();
if (!function_exists('wp_roles')) {
    function wp_roles() {
        return (object) ['roles' => ['editor' => []]];
    }
}
if (!function_exists('get_role')) {
    function get_role($r) {
        return $GLOBALS['__role'];
    }
}
define('WP_UNINSTALL_PLUGIN', true);
include dirname(__DIR__, 2) . '/uninstall.php';
$q = implode("\n", $GLOBALS['wpdb']->queries);
check('uninstall: all 5 tables dropped', substr_count($q, 'DROP TABLE IF EXISTS') === 5);
check('uninstall: options + transients by prefix', str_contains($q, "option_name LIKE 'tqk\\_wpseo\\_%'") && str_contains($q, "\\_transient\\_tqk\\_wpseo\\_%"));
check('uninstall: post meta and term meta', str_contains($q, 'wp_postmeta') && str_contains($q, 'wp_termmeta'));
check('uninstall: cron events cleared', in_array('tqk_wpseo_license_check', $GLOBALS['__cleared'], true) && in_array('tqk_wpseo_gsc_daily', $GLOBALS['__cleared'], true));
check('uninstall: role capability removed, others kept', !$GLOBALS['__role']->has_cap('tqk_seo_access') && $GLOBALS['__role']->has_cap('edit_posts'));
check('uninstall: our upload folders deleted', !is_dir($tmp . '/audit-pics') && !is_dir($tmp . '/tqk-wpseo-audit'));
check('uninstall: original media untouched', file_get_contents($tmp . '/2026/03/photo.jpg') === 'keep');
@unlink($tmp . '/2026/03/photo.jpg');
@rmdir($tmp . '/2026/03');
@rmdir($tmp . '/2026');
@rmdir($tmp);

echo $fail === 0 ? "\nALL S20 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
