<?php
// S12 tests: secondary keywords, news/video sitemap, XSL, settings import/export.
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

use TQK_WPSEO\Analyzer\Analyzer;
use TQK_WPSEO\Analyzer\Keyword;
use TQK_WPSEO\Sitemap\Sitemap;

call_user_func($GLOBALS['__activation']);
do_action('init');
update_option('tqk_wpseo_settings', \TQK_WPSEO\Core\Activator::default_settings());

// ---------- Secondary keywords ----------
check('secondary parse caps at 4', Keyword::parse_secondary('a, b, c, d, e, a') === ['a', 'b', 'c', 'd']);
check('secondary parse empty', Keyword::parse_secondary('  , ') === []);
$sec = Keyword::analyze_secondary(['alpha post', 'missing thing'], ['title' => 'Alpha post here', 'description' => ''], 'alpha post words here');
check('secondary presence', $sec['set'] === true && $sec['present'] === ['alpha post'] && $sec['missing'] === ['missing thing']);

$GLOBALS['__posts'][101] = ['ID' => 101, 'post_title' => 'Alpha post about testing tools', 'post_content' => '<h1>Alpha post about testing tools</h1><p>' . str_repeat('Alpha content words here. ', 30) . '</p>', 'post_excerpt' => 'Alpha excerpt here.', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'alpha-post', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-02'];
$GLOBALS['__postmeta_map'][101] = ['_tqk_wpseo_focus_keyword' => 'alpha post', '_tqk_wpseo_secondary_keywords' => 'content words, nowhere keyword'];
$r = Analyzer::analyze(101);
$ids = array_column($r['findings'], 'id');
check('secondary missing finding', in_array('kw_secondary_missing', $ids, true));
check('secondary adds evaluated slots', ($r['evaluated'] ?? 0) === 25);

$GLOBALS['__postmeta_map'][102] = ['_tqk_wpseo_focus_keyword' => 'alpha post', '_tqk_wpseo_secondary_keywords' => 'alpha post, content words'];
$GLOBALS['__posts'][102] = $GLOBALS['__posts'][101];
$GLOBALS['__posts'][102]['ID'] = 102;
$r2 = Analyzer::analyze(102);
check('secondary all present passes', !in_array('kw_secondary_missing', array_column($r2['findings'], 'id'), true));

// ---------- News sitemap (last 48h only) ----------
$GLOBALS['__posts'][103] = ['ID' => 103, 'post_title' => 'Fresh News Today', 'post_content' => '<p>' . str_repeat('news words here. ', 30) . '</p>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'fresh-news', 'post_author' => 1, 'post_date' => gmdate('Y-m-d H:i:s', time() - 3600), 'post_modified' => gmdate('Y-m-d H:i:s')];
$news = Sitemap::news_items();
check('news picks fresh only', count($news) === 1 && $news[0][1] === 'Fresh News Today');
$news_xml = Sitemap::render_news();
check('news xml namespaces', str_contains($news_xml, 'sitemap-news/0.9') && str_contains($news_xml, '<news:title>Fresh News Today</news:title>'));

// ---------- Video sitemap ----------
$GLOBALS['__posts'][104] = ['ID' => 104, 'post_title' => 'Video Post', 'post_content' => '<p>intro</p><iframe src="https://www.youtube.com/embed/abc123"></iframe>', 'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'video-post', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
$vids = Sitemap::extract_videos($GLOBALS['__posts'][104]['post_content']);
check('video extract youtube', count($vids) === 1 && str_contains($vids[0]['player'], 'youtube.com'));
$video_xml = Sitemap::render_video();
check('video xml namespaces', str_contains($video_xml, 'sitemap-video/1.1') && str_contains($video_xml, '<video:player_loc>https://www.youtube.com/embed/abc123</video:player_loc>'));

// ---------- XSL + index wiring ----------
$xsl = Sitemap::render_xsl();
check('xsl stylesheet', str_contains($xsl, 'xsl:stylesheet') && str_contains($xsl, 'XML Sitemap'));
$idx = Sitemap::render_index();
check('index has stylesheet pi', str_contains($idx, 'sitemap.xsl'));
check('index hides news/video by default', !str_contains($idx, 'news-sitemap.xml') && !str_contains($idx, 'video-sitemap.xml'));
update_option('tqk_wpseo_settings', array_merge(get_option('tqk_wpseo_settings'), ['sitemap_maps' => ['post', 'news', 'video']]));
$idx2 = Sitemap::render_index();
check('index lists news/video when enabled', str_contains($idx2, 'news-sitemap.xml') && str_contains($idx2, 'video-sitemap.xml'));
ob_start();
\TQK_WPSEO\Admin\Pages::sitemap();
$sm = (string) ob_get_clean();
check('sitemap page shows news/video rows', str_contains($sm, 'news-sitemap.xml') && str_contains($sm, 'video-sitemap.xml'));

// ---------- Settings import/export round-trip ----------
function tools_call($fields = []) {
    $_POST = array_merge(['tqk_action' => 'tools', '_ajax_nonce' => 'valid', 'op' => 'reset'], $fields);
    try {
        \TQK_WPSEO\Admin\Ajax::route();
    } catch (WP_Test_Response $e) {
        return $e->payload;
    }
    return null;
}
$r = tools_call(['op' => 'export']);
check('export returns json', ($r['success'] ?? false) === true && str_contains($r['data']['json'] ?? '', '"plugin":"tqk-wpseo"'));
$payload = $r['data']['json'] ?? '{}';
$decoded = json_decode($payload, true);
$decoded['settings']['title_template'] = 'Imported %title%';
$r2 = tools_call(['op' => 'import', 'payload' => json_encode($decoded)]);
check('import applies settings', ($r2['success'] ?? false) === true && get_option('tqk_wpseo_settings')['title_template'] === 'Imported %title%');
check('import rejects garbage', (tools_call(['op' => 'import', 'payload' => 'not json'])['success'] ?? true) === false);
ob_start();
\TQK_WPSEO\Admin\Pages::tools();
$tp = (string) ob_get_clean();
check('tools has import/export ui', str_contains($tp, 'data-tqk-export-settings') && str_contains($tp, 'tqk-import-settings'));

echo $fail === 0 ? "\nALL COMPLETENESS CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
