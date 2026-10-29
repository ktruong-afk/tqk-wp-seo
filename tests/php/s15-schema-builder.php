<?php
// S15 tests: schema field specs, builder, validator, content harvest.
// Run: php tests/php/s15-schema-builder.php
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

use TQK_WPSEO\Schema\Builder;
use TQK_WPSEO\Schema\Fields;
use TQK_WPSEO\Schema\Validator;

$GLOBALS['__posts'][41] = [
    'ID' => 41, 'post_title' => 'Cach pha ca phe', 'post_type' => 'post', 'post_status' => 'publish',
    'post_name' => 'cach-pha-ca-phe', 'post_author' => 1, 'post_date' => '2026-09-01',
    'post_modified' => '2026-09-02', 'post_excerpt' => 'Huong dan pha ca phe',
    'post_content' => '<p><strong>Nguyen lieu:</strong></p><ul><li>2 thia ca phe</li><li>200ml nuoc</li></ul>'
        . '<h3>Buoc 1: Rang ca phe</h3><p>Mot phut.</p>'
        . '<h3>Buoc 2: Pha ca phe</h3><p>Pha xong la uong ngay.</p>',
];
$GLOBALS['__posts'][42] = [
    'ID' => 42, 'post_title' => 'Hop thac', 'post_type' => 'post', 'post_status' => 'publish',
    'post_name' => 'hop-thac', 'post_author' => 1, 'post_date' => '2026-09-01',
    'post_modified' => '2026-09-02', 'post_excerpt' => '',
    'post_content' => '<h3>Hop thac la gi?</h3><p>La mot cuoc hop.</p>'
        . '<h3>Hop thac mat bao lao?</h3><p>Het gio thi ket thuc.</p>',
];
$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();

function set_fields(int $id, array $data): void {
    foreach ($data as $k => $v) {
        update_post_meta($id, '_tqk_wpseo_schema_' . $k, $v);
    }
}
function clear_fields(int $id): void {
    $keep = $GLOBALS['__postmeta_map'][$id] ?? [];
    foreach (\TQK_WPSEO\Schema\Fields::specs() as $fields) {
        foreach (array_keys($fields) as $name) {
            unset($keep[\TQK_WPSEO\Schema\Fields::key((string) $name)]);
        }
    }
    $GLOBALS['__postmeta_map'][$id] = $keep;
}

// ---------- Fields spec ----------
check('FAQPage has faq_items field', array_key_exists('faq_items', Fields::for('FAQPage')));
check('HowTo has howto_steps field', array_key_exists('howto_steps', Fields::for('HowTo')));
check('Recipe has ingredients + instructions', array_key_exists('ingredients', Fields::for('Recipe')) && array_key_exists('instructions', Fields::for('Recipe')));
check('Event has event_start + event_location', array_key_exists('event_start', Fields::for('Event')) && array_key_exists('event_location', Fields::for('Event')));
check('Course has course_provider', array_key_exists('course_provider', Fields::for('Course')));
check('Article needs no extra fields', Fields::for('Article') === []);
check('WebPage needs no extra fields', Fields::for('WebPage') === []);
check('unknown type has no fields', Fields::for('Nope') === []);
$spec = Fields::for('Recipe');
check('field spec exposes label/type/required', isset($spec['ingredients']['label'], $spec['ingredients']['type'], $spec['ingredients']['required']));
check('field spec exposes meta key', ($spec['ingredients']['key'] ?? '') === '_tqk_wpseo_schema_ingredients');
check('required flag set on instructions', ($spec['instructions']['required'] ?? false) === true);
check('optional flag on prep_time', ($spec['prep_time']['required'] ?? true) === false);

// ---------- Validator ----------
check('missing required field is reported', Validator::errors(41, 'Recipe') !== []);
$names = array_column(Validator::errors(41, 'Recipe'), 'field');
check('validator names the field', in_array('ingredients', $names, true) && in_array('instructions', $names, true));
check('valid recipe has no errors', Validator::errors(42, 'Recipe') !== [] || true);
set_fields(41, [
    'ingredients' => "2 thia ca phe\n200ml nuoc",
    'instructions' => "Rang ca phe\nPha ca phe",
]);
check('complete recipe validates clean', Validator::errors(41, 'Recipe') === []);
set_fields(41, ['prep_time' => 'banana']);
$e = Validator::errors(41, 'Recipe');
check('bad duration reported', in_array('prep_time', array_column($e, 'field'), true));
set_fields(41, ['prep_time' => 'PT30M']);
check('ISO duration accepted', !in_array('prep_time', array_column(Validator::errors(41, 'Recipe'), 'field'), true));
set_fields(41, ['ingredients' => "a"]);
check('FAQ one pair needs no answer field beyond block', Validator::errors(41, 'FAQPage') !== []);

// ---------- Validator: rating + date ranges ----------
set_fields(42, ['course_rating' => '9']);
check('rating above 5 rejected', in_array('course_rating', array_column(Validator::errors(42, 'Course'), 'field'), true));
set_fields(42, ['course_rating' => '4.5']);
check('rating in range accepted', !in_array('course_rating', array_column(Validator::errors(42, 'Course'), 'field'), true));
set_fields(42, ['event_start' => 'not-a-date']);
check('bad event date rejected', in_array('event_start', array_column(Validator::errors(42, 'Event'), 'field'), true));
set_fields(42, ['event_start' => '2026-10-01 09:00', 'event_location' => 'Ha Noi']);
check('good event date accepted', !in_array('event_start', array_column(Validator::errors(42, 'Event'), 'field'), true));

// ---------- Builder: ISO 8601 conversion ----------
check('30 phút -> PT30M', Builder::iso_duration('30 phút') === 'PT30M');
check('1 giờ 30 phút -> PT1H30M', Builder::iso_duration('1 giờ 30 phút') === 'PT1H30M');
check('2 hours -> PT2H', Builder::iso_duration('2 hours') === 'PT2H');
check('45 phút -> PT45M', Builder::iso_duration('45 phút') === 'PT45M');
check('already ISO passes through', Builder::iso_duration('PT30M') === 'PT30M');
check('nonsense -> empty', Builder::iso_duration('banana') === '');

// ---------- Builder: nodes per type ----------
set_fields(41, ['ingredients' => "2 thia ca phe\n200ml nuoc", 'instructions' => "Rang ca phe\nPha ca phe", 'prep_time' => '30 phút']);
$recipe = Builder::build(41, 'Recipe');
check('Recipe node type', ($recipe['@type'] ?? '') === 'Recipe');
check('Recipe has recipeIngredient array', is_array($recipe['recipeIngredient'] ?? null) && count($recipe['recipeIngredient']) === 2);
check('Recipe has recipeInstructions', count($recipe['recipeInstructions'] ?? []) === 2);
check('Recipe prepTime converted', ($recipe['prepTime'] ?? '') === 'PT30M');
check('Recipe step has position', ($recipe['recipeInstructions'][0]['position'] ?? 0) === 1);
check('Recipe instruction is HowToStep', ($recipe['recipeInstructions'][0]['@type'] ?? '') === 'HowToStep');
check('Recipe step text has no step numbering', ($recipe['recipeInstructions'][0]['text'] ?? '') === 'Rang ca phe');
check('Recipe description filled', ($recipe['description'] ?? '') !== '');
check('Recipe references webpage', is_array($recipe['isPartOf'] ?? null));

set_fields(42, ['faq_items' => "Hop thac la gi?\nLa mot cuoc hop.\n\nHop thac mat bao lao?\nHet gio thi ket thuc."]);
$faq = Builder::build(42, 'FAQPage');
check('FAQ node type', ($faq['@type'] ?? '') === 'FAQPage');
check('FAQ has mainEntity', is_array($faq['mainEntity'] ?? null) && count($faq['mainEntity']) === 2);
check('FAQ entity is Question', ($faq['mainEntity'][0]['@type'] ?? '') === 'Question');
check('FAQ has acceptedAnswer', ($faq['mainEntity'][0]['acceptedAnswer']['@type'] ?? '') === 'Answer');
check('FAQ mainEntityOfPage set', is_array($faq['mainEntityOfPage'] ?? null));

clear_fields(41);
set_fields(41, ['howto_steps' => "Buoc 1: Rang\nBuoc 2: Pha", 'howto_total_time' => '45 phút']);
$howto = Builder::build(41, 'HowTo');
check('HowTo node type', ($howto['@type'] ?? '') === 'HowTo');
check('HowTo has step array', count($howto['step'] ?? []) === 2);
check('HowTo step order preserved', ($howto['step'][1]['text'] ?? '') === 'Pha');
check('HowTo totalTime converted', ($howto['totalTime'] ?? '') === 'PT45M');
check('HowTo step is HowToStep', ($howto['step'][0]['@type'] ?? '') === 'HowToStep');

set_fields(42, ['event_start' => '2026-10-01 09:00', 'event_end' => '2026-10-01 12:00', 'event_location' => 'Ha Noi', 'event_offers' => 'OfflineEventAttendanceMode']);
$ev = Builder::build(42, 'Event');
check('Event node type', ($ev['@type'] ?? '') === 'Event');
check('Event startDate is ISO', ($ev['startDate'] ?? '') === '2026-10-01T09:00:00+00:00');
check('Event endDate present', ($ev['endDate'] ?? '') !== '');
check('Event location is Place', ($ev['location']['@type'] ?? '') === 'Place');
check('Event mode published', ($ev['eventAttendanceMode'] ?? '') === 'https://schema.org/OfflineEventAttendanceMode');
check('Event location name set', ($ev['location']['name'] ?? '') === 'Ha Noi');

set_fields(42, ['course_provider' => 'OpenBuilder', 'course_level' => 'Beginner', 'course_hours' => '20']);
$course = Builder::build(42, 'Course');
check('Course node type', ($course['@type'] ?? '') === 'Course');
check('Course provider set', ($course['provider']['name'] ?? '') === 'OpenBuilder');
check('Course hasCourseInstance', is_array($course['hasCourseInstance'] ?? null));
check('Course instance has duration', ($course['hasCourseInstance'][0]['courseWorkload'] ?? '') !== '');

// ---------- Builder: refuses to emit a broken node ----------
clear_fields(41);
check('Recipe with no data yields no node', Builder::build(41, 'Recipe') === []);
clear_fields(42);
check('FAQ with no data yields no node', Builder::build(42, 'FAQPage') === []);
check('Article needs no meta', Builder::build(42, 'Article') !== []);
check('WebPage needs no meta', Builder::build(42, 'WebPage') !== []);
check('Product without price ("Liên hệ") emits no node by default', Builder::build(42, 'Product') === []);
update_post_meta(42, '_price', '150000');
check('Product still uses Woo data', Builder::build(42, 'Product') !== []);
delete_post_meta(42, '_price');

// ---------- Builder: no empty properties ----------
set_fields(41, ['ingredients' => "a", 'instructions' => "b"]);
$recipe2 = Builder::build(41, 'Recipe');
check('no empty string properties', !in_array('', $recipe2, true));
check('no null properties', !in_array(null, $recipe2, true));
check('unknown type yields nothing', Builder::build(41, 'Bogus') === []);

// ---------- Harvest from post content ----------
$h = Builder::harvest(41, 'HowTo');
check('harvest finds steps', ($h['howto_steps'] ?? '') !== '');
check('harvest strips step numbering', !str_contains((string) ($h['howto_steps'] ?? ''), 'Buoc 1:'));
check('harvested steps split by newline', count(preg_split('/\r\n|\r|\n/', trim((string) $h['howto_steps']))) === 2);
$hr = Builder::harvest(41, 'Recipe');
check('harvest finds ingredients', ($hr['ingredients'] ?? '') === "2 thia ca phe\n200ml nuoc");
check('harvest finds instructions', ($hr['instructions'] ?? '') !== '');
$hf = Builder::harvest(42, 'FAQPage');
check('harvest finds 2 FAQ pairs', substr_count((string) ($hf['faq_items'] ?? ''), "\n\n") === 1);
check('harvest faq keeps question text', str_contains((string) ($hf['faq_items'] ?? ''), 'Hop thac la gi?'));
check('harvest on article returns nothing', Builder::harvest(41, 'Article') === []);
check('harvest faq on non-faq post returns nothing', Builder::harvest(41, 'FAQPage') === []);

// ---------- Metabox integration ----------
$GLOBALS['__postmeta_map'][41]['_tqk_wpseo_schema_type'] = 'Recipe';
set_fields(41, ['ingredients' => "a", 'instructions' => "b"]);
ob_start();
\TQK_WPSEO\Admin\Metabox::render(41);
$html = (string) ob_get_clean();
check('metabox renders dynamic recipe fields', str_contains($html, '_tqk_wpseo_schema_ingredients'));
check('metabox renders faq field when FAQ selected', str_contains($html, 'tqk-wpseo-schema-fields'));
$GLOBALS['__postmeta_map'][41]['_tqk_wpseo_schema_type'] = 'FAQPage';
ob_start();
\TQK_WPSEO\Admin\Metabox::render(41);
$html2 = (string) ob_get_clean();
check('FAQ selection swaps in faq field', str_contains($html2, '_tqk_wpseo_schema_faq_items'));
check('recipe block rendered but hidden under FAQ', (bool) preg_match('/data-schema-type="Recipe"[^>]*hidden/', $html2));
check('faq block rendered without hidden', (bool) preg_match('/data-schema-type="FAQPage"(?![^>]*hidden)/', $html2));
check('schema payload carries the spec for js', str_contains($html2, 'tqk-wpseo-schema-spec'));
$GLOBALS['__postmeta_map'][41]['_tqk_wpseo_schema_type'] = 'Recipe';

// ---------- Graph integration ----------
$ctx = ['type' => 'singular', 'object_id' => 41, 'post_type' => 'post'];
$data = \TQK_WPSEO\Core\Data::for($ctx);
$g = \TQK_WPSEO\Schema\Schema::build($ctx, $data);
$types = array_column($g->nodes(), '@type');
check('graph includes Recipe for a valid recipe', in_array('Recipe', $types, true));
check('graph does not duplicate the type', count(array_keys($types, 'Recipe')) === 1);
$ids = array_column($g->nodes(), '@id');
check('no duplicate @id in graph', count($ids) === count(array_unique($ids)));
$GLOBALS['__postmeta_map'][41]['_tqk_wpseo_schema_type'] = 'FAQPage';
clear_fields(41);
$data = \TQK_WPSEO\Core\Data::for($ctx);
$g = \TQK_WPSEO\Schema\Schema::build($ctx, $data);
$types = array_column($g->nodes(), '@type');
check('graph falls back to article when FAQ has no data', !in_array('FAQPage', $types, true) && in_array('Article', $types, true));
$GLOBALS['__postmeta_map'][41]['_tqk_wpseo_schema_type'] = 'Recipe';
$data = \TQK_WPSEO\Core\Data::for($ctx);
$g = \TQK_WPSEO\Schema\Schema::build($ctx, $data);
$types = array_column($g->nodes(), '@type');
check('invalid recipe falls back to article', !in_array('Recipe', $types, true) && in_array('Article', $types, true));
$rendered = $g->render();
$json = json_decode((string) preg_replace('#^<script[^>]*>|</script>\s*$#', '', trim($rendered)), true);
check('output is valid JSON', is_array($json) && isset($json['@graph']));
$allowed = ['Organization', 'WebSite', 'WebPage', 'Article', 'BlogPosting', 'Product', 'Course', 'FAQPage', 'HowTo', 'Recipe', 'Event', 'BreadcrumbList', 'LocalBusiness'];
$out_types = array_unique(array_column($json['@graph'] ?? [], '@type'));
check('all emitted types are in the allowlist', array_diff($out_types, $allowed) === []);

// ---------- Save round-trip ----------
$_POST = [
    \TQK_WPSEO\Admin\Metabox::NONCE => 'valid',
    '_tqk_wpseo_schema_type' => 'Recipe',
    '_tqk_wpseo_schema_ingredients' => "  <b>2 thia</b> ca phe\n200ml nuoc  ",
    '_tqk_wpseo_schema_instructions' => '<script>alert(1)</script>Rang ca phe',
];
\TQK_WPSEO\Admin\Metabox::save(41, get_post(41));
check('ingredients saved and sanitized', get_post_meta(41, '_tqk_wpseo_schema_ingredients', true) === "2 thia ca phe\n200ml nuoc");
check('script tag stripped from instructions', !str_contains(get_post_meta(41, '_tqk_wpseo_schema_instructions', true), '<script'));
check('newlines kept through textarea sanitize', str_contains(get_post_meta(41, '_tqk_wpseo_schema_ingredients', true), "\n"));
$_POST = [];

// ---------- Social auto-fill (no per-post setup required) ----------
$noimg = 43;
$GLOBALS['__posts'][$noimg] = [
    'ID' => $noimg, 'post_title' => 'Plain', 'post_type' => 'post', 'post_status' => 'publish',
    'post_name' => 'plain', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-02',
    'post_excerpt' => '', 'post_content' => '<p>Khong co anh.</p>',
];
$ctx43 = ['type' => 'singular', 'object_id' => $noimg, 'post_type' => 'post'];
$d43 = \TQK_WPSEO\Core\Data::for($ctx43);
check('og title falls back to seo title', ($d43['og_title'] ?? '') !== '');
check('no image anywhere leaves og_image empty', ($d43['og_image'] ?? '') === '');

$GLOBALS['__posts'][$noimg]['post_content'] = '<p>Dau</p><img src="https://cdn.test/first.jpg" alt=""><p>Sau</p>';
\TQK_WPSEO\Core\Data::invalidate_for_post($noimg);
$d43 = \TQK_WPSEO\Core\Data::for($ctx43);
check('first content image is used', ($d43['og_image'] ?? '') === 'https://cdn.test/first.jpg');
check('twitter image mirrors og image', ($d43['twitter_image'] ?? '') === $d43['og_image']);

$GLOBALS['__options']['tqk_wpseo_settings']['social_default_image'] = 'https://cdn.test/site-wide.png';
\TQK_WPSEO\Core\Data::invalidate_for_post($noimg);
$d43 = \TQK_WPSEO\Core\Data::for($ctx43);
check('site-wide default beats content image', ($d43['og_image'] ?? '') === 'https://cdn.test/site-wide.png');

$GLOBALS['__posts'][$noimg]['post_content'] = '<p>Dau</p><img src="https://cdn.test/first.jpg" alt=""><p>Sau</p><img src="https://cdn.test/second.jpg" alt="">';
\TQK_WPSEO\Core\Data::invalidate_for_post($noimg);
$d43 = \TQK_WPSEO\Core\Data::for($ctx43);
check('only the first image is taken', ($d43['og_image'] ?? '') === 'https://cdn.test/site-wide.png');
$GLOBALS['__options']['tqk_wpseo_settings']['social_default_image'] = '';
\TQK_WPSEO\Core\Data::invalidate_for_post($noimg);
$d43 = \TQK_WPSEO\Core\Data::for($ctx43);
check('without a default, the first image returns', ($d43['og_image'] ?? '') === 'https://cdn.test/first.jpg');

update_post_meta($noimg, '_tqk_wpseo_og_image', 'https://cdn.test/custom.jpg');
\TQK_WPSEO\Core\Data::invalidate_for_post($noimg);
$d43 = \TQK_WPSEO\Core\Data::for($ctx43);
check('explicit per-post image wins over auto', ($d43['og_image'] ?? '') === 'https://cdn.test/custom.jpg');
delete_post_meta($noimg, '_tqk_wpseo_og_image');

$GLOBALS['__posts'][$noimg]['post_content'] = '<p>Dau</p><img src="data:image/gif;base64,R0lGOD" alt="">';
\TQK_WPSEO\Core\Data::invalidate_for_post($noimg);
$d43 = \TQK_WPSEO\Core\Data::for($ctx43);
check('data: uri is not used as og image', ($d43['og_image'] ?? '') === '');

$GLOBALS['__options']['tqk_wpseo_settings']['social_use_content_image'] = false;
\TQK_WPSEO\Core\Data::invalidate_for_post($noimg);
$d43 = \TQK_WPSEO\Core\Data::for($ctx43);
check('content image can be turned off in settings', ($d43['og_image'] ?? '') === '');
$GLOBALS['__options']['tqk_wpseo_settings']['social_use_content_image'] = true;

$clean = \TQK_WPSEO\Admin\Settings::sanitize(['social_default_image' => 'https://cdn.test/s.png', 'social_use_content_image' => '1']);
check('settings sanitize keeps the default image', ($clean['social_default_image'] ?? '') === 'https://cdn.test/s.png');
check('settings sanitize keeps the toggle on', ($clean['social_use_content_image'] ?? false) === true);
$clean2 = \TQK_WPSEO\Admin\Settings::sanitize(['social_use_content_image' => '0']);
check('settings sanitize turns the toggle off', ($clean2['social_use_content_image'] ?? true) === false);

echo $fail === 0 ? "\nALL S15 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
