<?php
// S21 tests: editor JSON for outside AI (export / reply import), competitor
// JSON-LD → schema template + fields, rating block, scan result endpoint.
// Run: php tests/php/s21-ai-json.php
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

use TQK_WPSEO\Admin\AiJson;
use TQK_WPSEO\Admin\Metabox;

$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__posts'][21] = [
    'ID' => 21, 'post_title' => 'Cách nấu phở bò', 'post_content' => '<h2>Nguyên liệu</h2><p>Xương bò, bánh phở, hành.</p>',
    'post_excerpt' => '', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'pho-bo',
    'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01',
];
update_post_meta(21, '_tqk_wpseo_focus_keyword', 'phở bò');

// ---------- export ----------
$e = AiJson::export(21);
check('export has post id + instructions', $e['post_id'] === 21 && str_contains($e['instructions'], '"fields"'));
check('export carries current values by short key', ($e['current']['focus_keyword'] ?? '') === 'phở bò');
check('export has page text + headings', str_contains($e['page']['content'], 'Xương bò') && in_array('H2: Nguyên liệu', $e['page']['headings'], true));
check('reply keys listed', isset($e['reply_keys']['title'], $e['reply_keys']['description']));
check('ratings never offered to the AI', !isset($e['schema_fields']['Course']['course_rating']));
check('export encodes as JSON', is_string(json_encode($e)) && json_last_error() === 0);

// ---------- AI reply ----------
$reply = "Here you go:\n```json\n" . json_encode(['tqk_seo' => 1, 'post_id' => 21, 'fields' => [
    'title' => 'Cách nấu phở bò <b>ngon</b> chuẩn vị',
    'description' => 'Hướng dẫn nấu phở bò tại nhà.',
    'secondary_keywords' => ['phở bò Hà Nội', 'nước dùng phở', 'phở', 'bánh phở', 'thừa'],
    'robots' => 'noindex,maybe',
    'canonical' => 'https://other-site.test/pho',
    'course_rating' => '5',
    'evil_meta' => 'x',
    'faq_items' => [['question' => 'Phở là gì?', 'answer' => 'Món nước.']],
]]) . "\n```";
$r = AiJson::parse($reply, 21);
check('code fences + chat text stripped', ($r['kind'] ?? '') === 'reply');
check('title sanitised, mapped to meta key', ($r['fields']['_tqk_wpseo_title'] ?? '') === 'Cách nấu phở bò ngon chuẩn vị');
check('keyword list capped at 4', substr_count($r['fields']['_tqk_wpseo_secondary_keywords'] ?? '', ',') === 3);
check('invalid robots rejected', !isset($r['fields']['_tqk_wpseo_robots']));
check('canonical to another domain rejected', !isset($r['fields']['_tqk_wpseo_canonical']));
check('rating dropped with a reason', !isset($r['fields']['_tqk_wpseo_schema_course_rating']) && $r['dropped'] !== []);
check('unknown key ignored', count(array_filter(array_keys($r['fields']), fn($k) => str_contains($k, 'evil'))) === 0);
check('faq array → question/answer lines', ($r['fields']['_tqk_wpseo_schema_faq_items'] ?? '') === "Phở là gì?\nMón nước.");
check('same post → no mismatch', $r['post_mismatch'] === false);
$r = AiJson::parse(json_encode(['post_id' => 99, 'fields' => ['title' => 'X']]), 21);
check('other post id flagged', $r['post_mismatch'] === true && $r['reply_post_id'] === 99);
check('garbage → error', isset(AiJson::parse('not json at all', 21)['error']));

// ---------- competitor JSON-LD ----------
$ld = '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'WebPage', '@id' => '#wp'],
    ['@type' => 'Recipe', 'name' => 'Phở bò', 'recipeYield' => ['4', '4 bát'], 'prepTime' => 'PT30M', 'cookTime' => 'PT3H',
        'recipeIngredient' => ['1kg xương bò', '500g bánh phở'],
        'recipeInstructions' => [
            ['@type' => 'HowToSection', 'name' => 'Nước dùng', 'itemListElement' => [['@type' => 'HowToStep', 'text' => 'Chần xương.'], ['@type' => 'HowToStep', 'text' => 'Ninh 3 giờ.']]],
            ['@type' => 'HowToStep', 'text' => 'Chan nước dùng.'],
        ],
        'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => 4.9, 'ratingCount' => 812]],
    ['@type' => 'Restaurant', 'name' => 'Phở Đối Thủ', 'telephone' => '0909', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => '1 Lê Lợi', 'addressLocality' => 'TP.HCM']],
]]) . '</script><script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => [
    ['@type' => 'Question', 'name' => 'Nấu bao lâu?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '<p>Khoảng 3 giờ.</p>']],
]]) . '</script>';
$r = AiJson::parse($ld, 21);
check('JSON-LD detected', $r['kind'] === 'jsonld');
check('schema type picked (Recipe over FAQ)', ($r['fields']['_tqk_wpseo_schema_type'] ?? '') === 'Recipe');
check('ingredients one per line', ($r['fields']['_tqk_wpseo_schema_ingredients'] ?? '') === "1kg xương bò\n500g bánh phở");
check('sections flattened into steps', ($r['fields']['_tqk_wpseo_schema_instructions'] ?? '') === "Chần xương.\nNinh 3 giờ.\nChan nước dùng.");
check('yield + times', ($r['fields']['_tqk_wpseo_schema_recipe_yield'] ?? '') === '4' && ($r['fields']['_tqk_wpseo_schema_cook_time'] ?? '') === 'PT3H');
check('FAQ from second block, html stripped', ($r['fields']['_tqk_wpseo_schema_faq_items'] ?? '') === "Nấu bao lâu?\nKhoảng 3 giờ.");
check('rating from competitor dropped', $r['dropped'] !== [] && !preg_grep('/4\.9|812/', $r['fields']));
check('business details only shown, not imported', (bool) preg_grep('/Phở Đối Thủ/', $r['notes']));

$ev = AiJson::parse(json_encode(['@context' => 'https://schema.org', '@type' => 'MusicEvent', 'startDate' => '2026-12-24T19:30:00+07:00',
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'location' => ['@type' => 'Place', 'name' => 'Nhà hát lớn', 'address' => 'Tràng Tiền, Hà Nội']]), 21);
check('Event subtype → Event', ($ev['fields']['_tqk_wpseo_schema_type'] ?? '') === 'Event');
check('ISO date → datetime-local', ($ev['fields']['_tqk_wpseo_schema_event_start'] ?? '') === '2026-12-24T19:30');
check('attendance mode short name', ($ev['fields']['_tqk_wpseo_schema_event_offers'] ?? '') === 'OfflineEventAttendanceMode');
check('location + address', ($ev['fields']['_tqk_wpseo_schema_event_location'] ?? '') === 'Nhà hát lớn' && str_contains($ev['fields']['_tqk_wpseo_schema_event_address'] ?? '', 'Tràng Tiền'));

$ht = AiJson::parse(json_encode([['@type' => 'https://schema.org/HowTo', 'name' => 'Thay lốp', 'tool' => [['@type' => 'HowToTool', 'name' => 'Cờ lê']], 'step' => ['Tháo ốc', 'Thay lốp']]]), 21);
check('HowTo from list + full type URL', ($ht['fields']['_tqk_wpseo_schema_type'] ?? '') === 'HowTo' && ($ht['fields']['_tqk_wpseo_schema_howto_steps'] ?? '') === "Tháo ốc\nThay lốp" && ($ht['fields']['_tqk_wpseo_schema_howto_tools'] ?? '') === 'Cờ lê');

// ---------- AJAX ----------
function ajax(callable $fn) {
    try {
        $fn();
    } catch (WP_Test_Response $r) {
        return $r->payload;
    }
    return null;
}
$_POST = ['post_id' => 21, 'mode' => 'export'];
$p = ajax([Metabox::class, 'ai_json']);
check('ajax export', ($p['success'] ?? false) && ($p['data']['json']['post_id'] ?? 0) === 21);
$_POST = ['post_id' => 21, 'mode' => 'read', 'json' => addslashes(json_encode(['fields' => ['title' => 'Mới']]))];
$p = ajax([Metabox::class, 'ai_json']);
check('ajax read never saves', ($p['data']['fields']['_tqk_wpseo_title'] ?? '') === 'Mới' && get_post_meta(21, '_tqk_wpseo_title', true) === '');
$_POST = ['post_id' => 21, 'html' => '<html><body><header>Menu</header><main><h1>Phở</h1><p>' . str_repeat('phở bò ngon ', 40) . '</p></main><footer>F</footer></body></html>'];
$p = ajax([Metabox::class, 'scan_result']);
$stored = json_decode((string) get_post_meta(21, \TQK_WPSEO\Analyzer\Analyzer::RENDERED, true), true);
check('scan result stored as rendered copy (main only)', ($p['success'] ?? false) && str_contains($stored['html'] ?? '', 'phở bò ngon') && !str_contains($stored['html'] ?? '', 'Menu'));
check('scan result returns fresh checklist', str_contains($p['data']['html'] ?? '', 'tqk') && is_int($p['data']['score'] ?? null));
check('scan result carries checklist counts for the plan', isset($p['data']['server']['critical'], $p['data']['server']['warning']));
check('scan result says Pro or not for the image step', isset($p['data']['images']['pro']) && $p['data']['images']['pro'] === false);

// Images measured by the editor scan feed the optimizer for that page.
$imgs = [[
    'kind' => 'img', 'src' => 'https://example.test/wp-content/uploads/hero.jpg', 'att' => 0, 'srcset' => false, 'sizes' => '',
    'vp' => ['desktop' => ['w' => 1200, 'h' => 600, 'nw' => 2400, 'nh' => 1200, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => ''],
             'mobile' => ['w' => 343, 'h' => 200, 'nw' => 2400, 'nh' => 1200, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => '']],
], ['kind' => 'img', 'src' => 'javascript:alert(1)', 'vp' => []]];
$_POST = ['post_id' => 21, 'html' => '<main><p>' . str_repeat('phở ', 50) . '</p></main>', 'images' => addslashes(json_encode($imgs))];
ajax([Metabox::class, 'scan_result']);
$pi = \TQK_WPSEO\Analyzer\ImageFix::page_images(21);
check('editor scan images stored, whitelisted (bad URL dropped)', count($pi) === 1 && $pi[0]['vp']['mobile']['w'] === 343);
$_POST = ['post_id' => 21, 'html' => ''];
check('empty scan refused', (ajax([Metabox::class, 'scan_result'])['success'] ?? true) === false);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL S21 CHECKS PASSED\n";
exit($fail ? 1 : 0);
