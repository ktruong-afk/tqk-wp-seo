<?php
// S4 tests: analyzer findings, keyword, scores, queue. Run: php tests/php/s4-analyzer.php
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

use TQK_WPSEO\Analyzer\Analyzer;
use TQK_WPSEO\Analyzer\Text;

// Problem post: long title/desc, 2x H1, skipped H3, img w/o alt, no internal links.
$GLOBALS['__posts'][31] = [
    'ID' => 31,
    'post_title' => 'A deliberately overlong post title that exceeds sixty characters easily',
    'post_content' => '<h1>First</h1><p>WordPress multisite plugin makes sites fly high and far away today.</p>'
        . '<h1>Second H1 here</h1><h3>Skipped H2 entirely</h3>'
        . '<p>More words to reach readable length for the analyzer checks below right now.</p>'
        . '<img src="https://example.test/a.jpg"><p><a href="https://external.test/x">out</a></p>'
        . '<p>' . trim(str_repeat('Filler words follow for length. ', 20)) . '</p>',
    'post_excerpt' => '',
    'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'problem-post',
    'post_author' => 3, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-02',
];
$GLOBALS['__users'][3] = ['display_name' => 'Khai', 'user_nicename' => 'khai'];
$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__postmeta_map'][31] = [
    '_tqk_wpseo_description' => str_repeat('Long description word ', 20),
    '_tqk_wpseo_focus_keyword' => 'WordPress Multisite Plugin',
];
$GLOBALS['__urls'] = ['https://example.test/problem-post' => 31];

do_action('init');
$r = Analyzer::analyze(31);
$ids = array_column($r['findings'], 'id');

check('finds long title', in_array('title_too_long', $ids, true));
check('finds long description', in_array('description_too_long', $ids, true));
check('finds multiple h1', in_array('multiple_h1', $ids, true));
check('finds heading skip', in_array('heading_skip', $ids, true));
check('finds missing alt', in_array('missing_alt', $ids, true));
check('finds no internal links', in_array('no_internal_links', $ids, true));
check('score below 100', $r['score'] < 100 && $r['score'] >= 0);
check('six groups scored', count($r['groups']) === 6);
check('stats words counted', $r['stats']['words'] > 20);
check('stored json retrievable', Analyzer::stored(31)['score'] === $r['score']);
check('evaluated slots counted', ($r['evaluated'] ?? 0) === 23);
check(
    'passed math consistent',
    ($r['evaluated'] ?? 0) - count($r['findings']) >= 0
);

// Builder content filter: supplied HTML replaces post_content.
$builder_filter = fn($html, $post) => '<h1>Builder Title Here</h1><p>' . str_repeat('Builder words here. ', 110) . '</p>';
add_filter('tqk_wpseo_analyzer_content', $builder_filter, 10, 2);
$rb = Analyzer::analyze(31);
$rbids = array_column($rb['findings'], 'id');
check('content filter feeds analyzer', !in_array('missing_h1', $rbids, true) && !in_array('thin_content', $rbids, true));
remove_filter('tqk_wpseo_analyzer_content', $builder_filter);

// Keyword normalized: different case still matches title? (title lacks keyword -> kw_title finding)
check('kw_title finding present', in_array('kw_title', $ids, true));
check('density computed', $r['keyword']['density'] > 0);

// Good post: everything set -> high score.
$GLOBALS['__posts'][32] = [
    'ID' => 32, 'post_title' => 'WordPress Multisite Plugin Guide',
    'post_content' => '<h1>WordPress Multisite Plugin Guide</h1><p>WordPress multisite plugin overview with enough words to pass the thin content check comfortably for testing purposes here and now today.</p>'
        . '<h2>Setup wordpress multisite plugin</h2><p>' . str_repeat('Body text wordpress multisite plugin. ', 40) . '</p>'
        . '<img src="https://example.test/b.jpg" alt="wordpress multisite plugin diagram" width="800" height="600">'
        . '<p><a href="https://example.test/problem-post">related</a> <a href="https://external.test/y">ext</a></p>',
    'post_excerpt' => 'A concise description with wordpress multisite plugin inside.',
    'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'wordpress-multisite-plugin',
    'post_author' => 3, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-02',
];
$GLOBALS['__postmeta_map'][32] = [
    '_tqk_wpseo_title' => 'WordPress Multisite Plugin Guide',
    '_tqk_wpseo_description' => 'Learn the wordpress multisite plugin the easy way today.',
    '_tqk_wpseo_focus_keyword' => 'wordpress multisite plugin',
];
$GLOBALS['__urls']['https://example.test/wordpress-multisite-plugin'] = 32;
$g = Analyzer::analyze(32);
$gids = array_column($g['findings'], 'id');
check('good post Title ok', !in_array('title_too_long', $gids, true) && !in_array('title_missing', $gids, true));
check('good post keyword in title', !in_array('kw_title', $gids, true));
check('good post keyword in url', !in_array('kw_url', $gids, true));
check('good post keyword in h1', !in_array('kw_h1', $gids, true));
check('good post keyword in alt', !in_array('kw_alt', $gids, true));
check('good post has internal link', !in_array('no_internal_links', $gids, true));
check('good post scores high', $g['score'] >= 80);

// Queue processing.
\TQK_WPSEO\Core\Queue::push(31);
\TQK_WPSEO\Core\Queue::push(32);
check('queue consumed count', Analyzer::process_queue() === 2);
check('queue empty after', \TQK_WPSEO\Core\Queue::all() === []);

// Analysis box renders stored result.
ob_start();
\TQK_WPSEO\Analyzer\AnalysisBox::render((object) $GLOBALS['__posts'][32]);
$box = (string) ob_get_clean();
check('box shows score', str_contains($box, 'SEO Score'));

// Text helpers.
check('normalize folds case+accents', Text::normalize('WordPress MULTISITE Plugin') === 'wordpress multisite plugin');
check('headings parsed', count(Text::headings('<h1>A</h1><h2>B</h2>')) === 2);

echo $fail === 0 ? "\nALL S4 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
