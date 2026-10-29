<?php
// S3 tests: schema graph, breadcrumb, sitemap, robots.txt. Run: php tests/php/s3-schema-sitemap.php
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

use TQK_WPSEO\Core\Breadcrumb;
use TQK_WPSEO\Core\Data;
use TQK_WPSEO\Schema\Graph;
use TQK_WPSEO\Schema\Schema;
use TQK_WPSEO\Sitemap\Sitemap;

$GLOBALS['__posts'][21] = [
    'ID' => 21, 'post_title' => 'Schema Post', 'post_content' => 'Body',
    'post_excerpt' => 'Excerpt', 'post_type' => 'post', 'post_status' => 'publish',
    'post_name' => 'schema-post', 'post_author' => 3, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-03',
];
$GLOBALS['__users'][3] = ['display_name' => 'Khai', 'user_nicename' => 'khai'];
$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__terms'] = [
    ['term_id' => 5, 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category', 'parent' => 0],
    ['term_id' => 6, 'name' => 'Tech', 'slug' => 'tech', 'taxonomy' => 'category', 'parent' => 5],
];
$GLOBALS['__post_terms'][21] = ['category' => [6]];

// Graph: single script, @graph, typeless nodes rejected.
$g = new Graph();
$g->add(['nope' => 1]);
check('typeless node rejected', $g->nodes() === []);
$g->add(['@type' => 'WebSite', 'name' => 'X']);
$html = $g->render();
check('single script tag', substr_count($html, '<script') === 1 && str_contains($html, '"@graph"'));

// Full singular graph via Frontend filter path.
do_action('init');
$GLOBALS['__cond'] = ['singular' => true];
$GLOBALS['__queried_object_id'] = 21;
$data = Data::for(['type' => 'singular', 'object_id' => 21, 'post_type' => 'post']);
$graph = Schema::build(['type' => 'singular', 'object_id' => 21, 'post_type' => 'post'], $data);
$types = array_column($graph->nodes(), '@type');
check('graph has 5 nodes', $types === ['Organization', 'WebSite', 'WebPage', 'Article', 'BreadcrumbList']);

ob_start();
\TQK_WPSEO\Core\Frontend::head();
$head = (string) ob_get_clean();
check('head includes schema script', substr_count($head, 'application/ld+json') === 1);

// Breadcrumb: Home > News > Tech > post.
$trail = Breadcrumb::trail(['type' => 'singular', 'object_id' => 21, 'post_type' => 'post']);
check('trail labels', array_column($trail, 0) === ['Home', 'News', 'Tech', 'Schema Post']);
$crumb = Breadcrumb::render(['type' => 'singular', 'object_id' => 21, 'post_type' => 'post']);
check('breadcrumb html current page', str_contains($crumb, 'aria-current="page"') && str_contains($crumb, 'Tech'));

// Sitemap.
$GLOBALS['__urls'] = [];
$items = Sitemap::items('post');
check('post items listed', count($items) === 1 && $items[0][0] === 'https://example.test/schema-post');
check('rewrites registered on init', count(array_filter(
    $GLOBALS['__rewrite_rules'] ?? [],
    fn($r) => ($r['regex'] ?? '') === '^sitemap_index\.xml$'
)) === 1);
$map = Sitemap::render_map('post');
check('map is urlset with lastmod', str_contains($map, '<urlset') && str_contains($map, '<lastmod>2026-09-03</lastmod>'));
$index = Sitemap::render_index();
check('index lists 4 maps', substr_count($index, '<sitemap>') === 4);

// Filter can extend items.
add_filter('tqk_wpseo_sitemap_items', fn($items, $type) => array_merge($items, [['https://example.test/extra', '']]), 10, 2);
check('sitemap filter applies', count(Sitemap::items('post')) === 2);

// robots.txt: default, custom, validation.
check('default robots has sitemap', str_contains(Sitemap::default_robots(), 'sitemap_index.xml'));
// Core's own lines are kept; our sitemap is listed (core sitemaps are off).
check('public default passthrough + our sitemap', Sitemap::robots('WP-DEFAULT', true) === "WP-DEFAULT\n\nSitemap: " . Sitemap::index_url() . "\n");
check('non-public blocks all', Sitemap::robots('x', false) === "User-agent: *\nDisallow: /\n");
update_option(Sitemap::ROBOTS_OPTION, "User-agent: *\nDisallow: /secret/\n");
check('custom robots served', str_contains(Sitemap::robots('x', true), '/secret/'));
check('block-all warned', Sitemap::validate_robots("User-agent: *\nDisallow: /") !== []);
check('clean robots valid', Sitemap::validate_robots(Sitemap::default_robots()) === []);

// Schema templates: override wins, map fallback, Product node with offer.
$GLOBALS['__postmeta_map'][21]['_tqk_wpseo_schema_type'] = 'BlogPosting';
check('override wins', \TQK_WPSEO\Schema\Schema::type_for(get_post(21)) === 'BlogPosting');
unset($GLOBALS['__postmeta_map'][21]['_tqk_wpseo_schema_type']);
$GLOBALS['__options']['tqk_wpseo_settings']['schema_map'] = ['post' => 'HowTo', 'page' => 'WebPage', 'product' => 'Product'];
check('map fallback', \TQK_WPSEO\Schema\Schema::type_for(get_post(21)) === 'HowTo');
$GLOBALS['__postmeta_map'][21]['_price'] = '99.00';
$GLOBALS['__postmeta_map'][21]['_sku'] = 'SKU-1';
$GLOBALS['__postmeta_map'][21]['_stock_status'] = 'instock';
$data21 = \TQK_WPSEO\Core\Data::for(['type' => 'singular', 'object_id' => 21, 'post_type' => 'post']);
$prod = \TQK_WPSEO\Schema\Nodes::product(get_post(21), $data21);
check('product node with offer', ($prod['@type'] ?? '') === 'Product' && ($prod['offers']['price'] ?? '') === '99.00');

// Sitemap cache: second render served from transient; invalidate clears.
\TQK_WPSEO\Sitemap\Sitemap::cached_map('post');
check('map cached', get_transient('tqk_wpseo_sitemap_post') !== false);
\TQK_WPSEO\Sitemap\Sitemap::invalidate();
check('invalidate clears', get_transient('tqk_wpseo_sitemap_post') === false);

// llms.txt lists site + pages.
$llms = \TQK_WPSEO\Sitemap\Sitemap::render_llms();
check('llms has site + sitemap ref', str_contains($llms, '# Example Site') && str_contains($llms, 'sitemap_index.xml'));
// Sitemap admin page renders tables + robots form.
ob_start();
\TQK_WPSEO\Admin\Pages::sitemap();
$pg = (string) ob_get_clean();
check('sitemap page has index link', str_contains($pg, 'sitemap_index.xml'));
check('sitemap page has robots form', str_contains($pg, 'tqk-robots-text') && str_contains($pg, 'data-tqk-form="robots"'));

// Dispatch: query var outputs XML with 200; disabled gives 404.
$GLOBALS['__query_vars'][\TQK_WPSEO\Sitemap\Sitemap::QUERY_VAR] = 'post';
ob_start();
\TQK_WPSEO\Sitemap\Sitemap::output('post');
$ox = (string) ob_get_clean();
check('dispatch outputs map xml', str_contains($ox, '<urlset') && ($GLOBALS['__status_header'] ?? 0) === 200);
$GLOBALS['__options']['tqk_wpseo_settings'] = array_merge(\TQK_WPSEO\Core\Activator::default_settings(), ['sitemap_enabled' => false]);
ob_start();
\TQK_WPSEO\Sitemap\Sitemap::output('post');
ob_get_clean();
check('disabled sitemap 404s', ($GLOBALS['__status_header'] ?? 0) === 404);

// Schema page previews graph.
ob_start();
\TQK_WPSEO\Admin\Pages::schema();
$sp = (string) ob_get_clean();
check('schema page previews @graph', str_contains($sp, '@graph'));

echo $fail === 0 ? "\nALL S3 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
