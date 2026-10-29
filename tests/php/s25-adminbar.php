<?php
// S25 tests: "TQK SEO" on the front-end toolbar (last score, audit this
// page, last plan, edit link), gating, and its script data.
// Run: php tests/php/s25-adminbar.php
require __DIR__ . '/stubs.php';
if (!function_exists('is_admin_bar_showing')) {
    function is_admin_bar_showing() {
        return $GLOBALS['__bar'] ?? true;
    }
}
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

use TQK_WPSEO\Admin\AdminBar;
use TQK_WPSEO\MCP\Reports;

final class FakeBar {
    public array $nodes = [];
    public function add_node(array $n): void {
        $this->nodes[$n['id']] = $n;
    }
}

$GLOBALS['__options']['tqk_wpseo_settings'] = \TQK_WPSEO\Core\Activator::default_settings();
$GLOBALS['__posts'][71] = ['ID' => 71, 'post_title' => 'Giới thiệu', 'post_content' => 'x', 'post_excerpt' => '', 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'gioi-thieu', 'post_author' => 1, 'post_date' => '2026-09-01', 'post_modified' => '2026-09-01'];
$GLOBALS['__cond'] = ['singular' => true];
$GLOBALS['__queried_object_id'] = 71;

$menu = static function () {
    $bar = new FakeBar();
    AdminBar::menu($bar);
    return $bar->nodes;
};

$n = $menu();
check('toolbar item on a page never checked: score "–"', isset($n['tqk-wpseo']) && str_contains($n['tqk-wpseo']['title'], 'tqk-ab-score">–<'));
check('audit this page + edit link + analytics', isset($n['tqk-wpseo-audit'], $n['tqk-wpseo-edit'], $n['tqk-wpseo-analytics']) && str_contains($n['tqk-wpseo-edit']['href'], 'post=71') && !isset($n['tqk-wpseo-last']));

Reports::save(71, ['score' => 62, 'plan' => [['prio' => 1], ['prio' => 1], ['prio' => 2], ['prio' => 3]]]);
$n = $menu();
check('last score shown with its tone', str_contains($n['tqk-wpseo']['title'], 'tqk-ab-score is-ok">62<'));
check('last plan counts in the menu', isset($n['tqk-wpseo-last']) && str_contains($n['tqk-wpseo-last']['title'], '2') && str_contains($n['tqk-wpseo-last']['title'], '1'));

// Gating.
$GLOBALS['__is_admin'] = true;
check('not in wp-admin (the editor has its own Scan)', $menu() === []);
$GLOBALS['__is_admin'] = false;
$GLOBALS['__bar'] = false;
check('not when the toolbar is hidden', $menu() === []);
$GLOBALS['__bar'] = true;
$_GET['tqk_rs'] = 'desktop';
check('never inside an audit frame (no recursion, no toolbar in snapshots)', $menu() === []);
unset($_GET['tqk_rs']);
$GLOBALS['__can'] = false;
check('not for people who cannot use the plugin', $menu() === []);
$GLOBALS['__can'] = true;

// Archive: audited, not stored.
$GLOBALS['__cond'] = ['archive' => true];
$n = $menu();
check('archive page: audit offered, no edit link', isset($n['tqk-wpseo-audit']) && !isset($n['tqk-wpseo-edit']));

// Script data.
$GLOBALS['__cond'] = ['singular' => true];
$GLOBALS['__enqueued'] = ['style' => [], 'script' => []];
AdminBar::assets();
$loc = $GLOBALS['__localized']['tqk-wpseo-adminbar'][1] ?? [];
check('engine + plan + toolbar scripts loaded', in_array('tqk-wpseo-adminbar', $GLOBALS['__enqueued']['script'], true) && in_array('tqk-wpseo-responsive', $GLOBALS['__enqueued']['script'], true) && in_array('tqk-wpseo-plan', $GLOBALS['__enqueued']['script'], true));
check('script data: post, nonce, last report, texts', ($loc['postId'] ?? 0) === 71 && ($loc['nonce'] ?? '') !== '' && ($loc['last']['score'] ?? 0) === 62 && isset($loc['t']['plan'], $loc['t']['barSaved']));

echo $fail ? "\n{$fail} FAILED\n" : "\nALL S25 CHECKS PASSED\n";
exit($fail ? 1 : 0);
