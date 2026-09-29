<?php
// S18 tests: image optimizer math, analysis and srcset/sizes serving.
// File generation (WP_Image_Editor) and the HTML tag processor need real
// WordPress and are verified in the browser, not here.
// Run: php tests/php/s18-image-fix.php
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

use TQK_WPSEO\Analyzer\ImageFix;

// --- object-position parsing ---
check('position: keywords', ImageFix::position('left top') === [0.0, 0.0] && ImageFix::position('right bottom') === [1.0, 1.0]);
check('position: percentages', ImageFix::position('25% 80%') === [0.25, 0.8]);
check('position: px falls back to centre', ImageFix::position('10px 0px') === [0.5, 0.5]);

// --- visible region under object-fit: cover ---
// 2000x1000 image in a 500x500 box: scale 0.5, visible 1000x1000 of the original.
check('cover: centred square of a 2:1 image', ImageFix::cover_region(2000, 1000, 500, 500, '50% 50%') === [500, 0, 1000, 1000]);
check('cover: object-position left keeps the left side', ImageFix::cover_region(2000, 1000, 500, 500, '0% 50%') === [0, 0, 1000, 1000]);
check('cover: box same ratio → whole image', ImageFix::cover_region(1600, 900, 800, 450, '50% 50%') === [0, 0, 1600, 900]);

// --- sizes attribute ---
$vp = ['desktop' => ['w' => 400], 'laptop' => ['w' => 400], 'tablet' => ['w' => 720], 'mobile' => ['w' => 343]];
check('sizes: fluid on phones (vw), fixed column on desktop (px)', ImageFix::sizes_attr($vp) === '(max-width: 767px) 92vw, (max-width: 1279px) 94vw, (max-width: 1439px) 400px, 400px');

// --- widths to generate ---
$w = ImageFix::target_widths($vp, 3000);
check('widths: 1x and 2x per screen, rounded to 10', in_array(350, $w, true) && in_array(690, $w, true) && in_array(1440, $w, true));
check('widths: near-duplicates merged (720 vs 690 → one)', !(in_array(720, $w, true) && in_array(690, $w, true)));
check('widths: never above the source', max(ImageFix::target_widths($vp, 600)) === 600);
check('widths: at most 6', count(ImageFix::target_widths(['a' => ['w' => 100], 'b' => ['w' => 300], 'c' => ['w' => 500], 'd' => ['w' => 900]], 5000)) <= 6);

// --- analysis ---
$big = ['kind' => 'img', 'src' => '/wp-content/uploads/hero.jpg', 'srcset' => false, 'sizes' => '',
    'vp' => ['desktop' => ['w' => 600, 'h' => 400, 'nw' => 3000, 'nh' => 2000, 'fit' => 'fill', 'pos' => '50% 50%', 'cur' => '/wp-content/uploads/hero.jpg'],
             'mobile' => ['w' => 343, 'h' => 229, 'nw' => 3000, 'nh' => 2000, 'fit' => 'fill', 'pos' => '50% 50%', 'cur' => '/wp-content/uploads/hero.jpg']]];
$a = ImageFix::analyze($big, ['w' => 3000, 'h' => 2000]);
check('analyze: oversized file on each screen reported', count(array_filter($a['issues'], fn($i) => strpos($i, '3000px wide') !== false)) === 2);
check('analyze: missing srcset reported', (bool) array_filter($a['issues'], fn($i) => strpos($i, 'No srcset') !== false));
check('analyze: JPG → WebP reported', (bool) array_filter($a['issues'], fn($i) => strpos($i, 'WebP') !== false));
check('analyze: fixable with widths up to 2x the desktop box', $a['fixable'] && max($a['widths']) === 1200);
check('analyze: same ratio → no crop', $a['crop'] === null);

$stretched = $big;
$stretched['vp']['desktop']['h'] = 600; // 1:1 box, 3:2 file, fill
$stretched['vp']['mobile']['h'] = 343;
$a = ImageFix::analyze($stretched, ['w' => 3000, 'h' => 2000]);
check('analyze: fill + other ratio → stretched, no auto crop', $a['crop'] === null && (bool) array_filter($a['issues'], fn($i) => strpos($i, 'stretched') !== false));

$cover = $stretched;
foreach (['desktop', 'mobile'] as $k) {
    $cover['vp'][$k]['fit'] = 'cover';
}
$a = ImageFix::analyze($cover, ['w' => 3000, 'h' => 2000]);
check('analyze: cover, same square box on all screens → crop to visible square', $a['crop'] === [500, 0, 2000, 2000]);
check('analyze: crop widths capped by the crop width', max($a['widths']) <= 2000);

$art = $cover;
$art['vp']['mobile']['h'] = 500; // different box ratio on phones
$a = ImageFix::analyze($art, ['w' => 3000, 'h' => 2000]);
check('analyze: different crop per screen → no auto crop (needs <picture>)', $a['crop'] === null && (bool) array_filter($a['issues'], fn($i) => strpos($i, 'picture') !== false));

$ok = ['kind' => 'img', 'src' => '/x.webp', 'srcset' => true, 'sizes' => '(max-width: 767px) 92vw, 400px',
    'vp' => ['desktop' => ['w' => 400, 'h' => 300, 'nw' => 800, 'nh' => 600, 'fit' => 'fill', 'pos' => '50% 50%', 'cur' => '/x-800x600.webp']]];
check('analyze: right-sized WebP with srcset → nothing to do', ImageFix::analyze($ok, ['w' => 1600, 'h' => 1200])['issues'] === []);

$avatar = ['kind' => 'img', 'src' => 'https://gravatar.test/a.webp', 'srcset' => true, 'wdesc' => false, 'sizes' => '',
    'vp' => ['desktop' => ['w' => 32, 'h' => 32, 'nw' => 64, 'nh' => 64, 'fit' => 'fill', 'pos' => '50% 50%', 'cur' => 'https://gravatar.test/a.webp']]];
check('analyze: density srcset (2x) needs no sizes', ImageFix::analyze($avatar, null)['issues'] === []);
$wd = $avatar;
$wd['wdesc'] = true;
check('analyze: width srcset without sizes is reported', ImageFix::analyze($wd, null)['issues'] !== []);

$bg_css = ['kind' => 'bg', 'src' => '/bg.jpg', 'inline' => false, 'srcset' => false, 'sizes' => '',
    'vp' => ['desktop' => ['w' => 1440, 'h' => 600, 'nw' => 0, 'nh' => 0, 'fit' => 'cover', 'pos' => '50% 50%', 'cur' => '/bg.jpg']]];
$a = ImageFix::analyze($bg_css, ['w' => 6000, 'h' => 3000]);
check('analyze: background in a CSS file → report only', !$a['fixable']);
$bg_inline = $bg_css;
$bg_inline['inline'] = true;
$a = ImageFix::analyze($bg_inline, ['w' => 6000, 'h' => 3000]);
check('analyze: inline background → one resized file at 2x the box', $a['fixable'] && $a['widths'] === [2880]);

// --- input sanitizing ---
$clean = ImageFix::clean_images([
    ['kind' => 'img', 'src' => 'javascript:alert(1)', 'vp' => []],
    ['kind' => 'evil', 'src' => 'https://s.test/a.jpg', 'att' => '12', 'vp' => ['mobile' => ['w' => '300', 'h' => 99999, 'cur' => 'https://s.test/a.jpg'], 'hacker' => ['w' => 1]]],
]);
check('clean: javascript: URL dropped', count($clean) === 1);
check('clean: unknown kind → img, sizes clamped, unknown viewport dropped', $clean[0]['kind'] === 'img' && $clean[0]['vp']['mobile']['h'] === 20000 && !isset($clean[0]['vp']['hacker']) && $clean[0]['att'] === 12);

// --- serving through core srcset / sizes ---
$GLOBALS['__cond']['singular'] = true;
$GLOBALS['__queried_object_id'] = 50;
$plan = ['active' => true, 'images' => ['a7' => ['att' => 7, 'kind' => 'img', 'src' => '/u/hero.jpg', 'crop' => false, 'sizes' => '(max-width: 767px) 92vw, 600px',
    'files' => [['w' => 350, 'h' => 233, 'url' => '/u/audit-pics/50-page/a7-350x233.webp'], ['w' => 1200, 'h' => 800, 'url' => '/u/audit-pics/50-page/a7-1200x800.webp']]]]];
update_post_meta(50, ImageFix::META, wp_slash(json_encode($plan)));
$core = [768 => ['url' => '/u/hero-768x512.jpg', 'descriptor' => 'w', 'value' => 768]];
$out = ImageFix::filter_srcset($core, [600, 400], '/u/hero.jpg', [], 7);
check('srcset: our WebP widths added next to core sizes', isset($out[350], $out[768], $out[1200]) && array_keys($out) === [350, 768, 1200]);
check('sizes: measured sizes replace the theme guess', ImageFix::filter_sizes('(max-width: 600px) 100vw, 600px', [600, 400], '', [], 7) === '(max-width: 767px) 92vw, 600px');
check('other attachments untouched', ImageFix::filter_srcset($core, [], '', [], 8) === $core);

$plan['images']['a7']['crop'] = true;
update_post_meta(50, ImageFix::META, wp_slash(json_encode($plan)));
$ref = new ReflectionProperty(ImageFix::class, 'plans');
$ref->setValue(null, []);
$out = ImageFix::filter_srcset($core, [600, 400], '/u/hero.jpg', [], 7);
check('srcset: cropped image uses only the cropped files', array_keys($out) === [350, 1200]);

$plan['active'] = false;
update_post_meta(50, ImageFix::META, wp_slash(json_encode($plan)));
$ref->setValue(null, []);
check('inactive plan: visitors see core output', ImageFix::filter_srcset($core, [], '', [], 7) === $core);

$GLOBALS['__queried_object_id'] = 51;
$ref->setValue(null, []);
check('other pages untouched', ImageFix::filter_srcset($core, [], '', [], 7) === $core);

echo $fail === 0 ? "\nALL S18 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
