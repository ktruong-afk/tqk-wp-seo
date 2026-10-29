<?php
// S1 tests: Context -> Data -> head output. Run: php tests/php/s1-frontend.php
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

use TQK_WPSEO\Core\Canonical;
use TQK_WPSEO\Core\Context;
use TQK_WPSEO\Core\Data;
use TQK_WPSEO\Core\Frontend;
use TQK_WPSEO\Core\Robots;

$GLOBALS['__posts'][7] = [
    'ID' => 7, 'post_title' => 'Hello SEO', 'post_content' => 'Body here',
    'post_excerpt' => 'Short excerpt here', 'post_type' => 'post',
    'post_status' => 'publish', 'post_name' => 'hello-seo',
    'post_author' => 3, 'post_date' => '2026-09-01 10:00:00', 'post_modified' => '2026-09-02 10:00:00',
];
$GLOBALS['__users'][3] = ['display_name' => 'Khai', 'user_nicename' => 'khai'];
$GLOBALS['__postmeta_map'][7] = ['_tqk_wpseo_focus_keyword' => 'hello seo'];
$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();

// Context shapes.
$GLOBALS['__cond'] = ['front' => true];
check('home context', Context::resolve() === ['type' => 'home']);
$GLOBALS['__cond'] = ['singular' => true];
$GLOBALS['__queried_object_id'] = 7;
$ctx = Context::resolve();
check('singular context', $ctx === ['type' => 'singular', 'object_id' => 7, 'post_type' => 'post']);
check('singular cache key', Context::key($ctx) === 'post:7');
$GLOBALS['__cond'] = ['search' => true];
check('search context', Context::resolve() === ['type' => 'search']);
$GLOBALS['__cond'] = ['is_404' => true];
check('404 context', Context::resolve() === ['type' => '404']);

// Data: template fallback + meta override.
$GLOBALS['__cond'] = ['singular' => true];
$d = Data::for($ctx);
check('title from template', $d['title'] === 'Hello SEO | Example Site');
check('desc from excerpt', $d['description'] === 'Short excerpt here');
check('canonical from permalink', $d['canonical'] === 'https://example.test/hello-seo');
check('robots default index,follow', $d['robots'] === ['index' => true, 'follow' => true, 'archive' => true]);
check('og falls back to title', $d['og_title'] === 'Hello SEO | Example Site');
check('focus keyword loaded', $d['focus_keyword'] === 'hello seo');

$GLOBALS['__postmeta_map'][7]['_tqk_wpseo_title'] = 'Custom Title';
$GLOBALS['__postmeta_map'][7]['_tqk_wpseo_robots'] = 'noindex,nofollow';
Data::invalidate_for_post(7);
$d2 = Data::for($ctx);
check('title override wins', $d2['title'] === 'Custom Title');
check('robots override parsed', $d2['robots'] === ['index' => false, 'follow' => false, 'archive' => true]);

// Caching: second call hits cache even after meta change.
$GLOBALS['__postmeta_map'][7]['_tqk_wpseo_title'] = 'Changed Later';
$d3 = Data::for($ctx);
check('cache hit returns stale', $d3['title'] === 'Custom Title');

// Canonical cleaning.
check(
    'utm stripped',
    Canonical::clean('https://example.test/page/?utm_source=x&paged=2') === 'https://example.test/page/?paged=2'
);
check('robots string', Robots::content(['index' => false, 'follow' => true, 'archive' => false]) === 'noindex,follow,noarchive');

// Full head output.
Data::invalidate_for_post(7);
unset($GLOBALS['__postmeta_map'][7]['_tqk_wpseo_robots']);
ob_start();
Frontend::head();
$head = (string) ob_get_clean();
check('head skips duplicate title (title-tag supported)', !str_contains($head, '<title>Changed Later</title>'));
check('head has description', str_contains($head, 'name="description"'));
check('head has canonical', str_contains($head, 'rel="canonical"'));
check('head prints no robots tag of its own', !str_contains($head, 'name="robots"'));
check('head has og', str_contains($head, 'og:title'));
check('head has twitter card', str_contains($head, 'twitter:card'));
check('head article type on singular', str_contains($head, 'og:type" content="article"'));

// De-duplication with core/theme output.
\TQK_WPSEO\Core\Frontend::hooks();
check('core canonical removed', in_array(['wp_head', 'rel_canonical'], $GLOBALS['__removed'] ?? []));
check(
    'document title filter returns ours',
    \TQK_WPSEO\Core\Frontend::document_title('Theme Title') === 'Changed Later'
);

// Robots merge into core's single wp_robots tag.
check('wp_robots filter hooked', in_array([Robots::class, 'filter'], $GLOBALS['__actions']['wp_robots'] ?? [], true));
$core = ['max-image-preview' => 'large'];
$GLOBALS['__options']['blog_public'] = '1';
check('public: index,follow added, core kept', Robots::merge($core, ['index' => true, 'follow' => true]) === ['max-image-preview' => 'large', 'index' => true, 'follow' => true]);
$m = Robots::merge($core, ['index' => false, 'follow' => true, 'archive' => false]);
check('public: per-post noindex,noarchive honored', !empty($m['noindex']) && !empty($m['follow']) && !empty($m['noarchive']) && !isset($m['index']));
$m = Robots::merge(['noindex' => true, 'follow' => true], ['index' => true, 'follow' => true]);
check('core noindex (e.g. search) not overridden', !empty($m['noindex']) && !isset($m['index']));
$GLOBALS['__options']['blog_public'] = '0';
$m = Robots::merge(['noindex' => true, 'nofollow' => true], ['index' => true, 'follow' => true]);
check('blog_public=0: stays noindex,nofollow', $m === ['noindex' => true, 'nofollow' => true]);
$m = Robots::merge([], ['index' => true, 'follow' => true]);
check('blog_public=0: never index/follow even if core empty', $m === ['noindex' => true, 'nofollow' => true]);
$GLOBALS['__postmeta_map'][7]['_tqk_wpseo_robots'] = 'index,nofollow';
$GLOBALS['__options']['blog_public'] = '1';
Data::invalidate_for_post(7);
$m = apply_filters('wp_robots', ['max-image-preview' => 'large']);
check('filter applies per-post override', !empty($m['index']) && !empty($m['nofollow']) && !isset($m['follow']));
unset($GLOBALS['__postmeta_map'][7]['_tqk_wpseo_robots'], $GLOBALS['__options']['blog_public']);
Data::invalidate_for_post(7);

$GLOBALS['__theme_support'] = false;
check(
    'title echoes without title-tag support',
    str_contains(\TQK_WPSEO\Core\Meta::render(['title' => 'T', 'description' => '']), '<title>T</title>')
);
$GLOBALS['__theme_support'] = true;
check(
    'title skipped with title-tag support',
    !str_contains(\TQK_WPSEO\Core\Meta::render(['title' => 'T', 'description' => '']), '<title>')
);

echo $fail === 0 ? "\nALL S1 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
