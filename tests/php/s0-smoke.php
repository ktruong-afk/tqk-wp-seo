<?php
// S0 smoke test. Run: php tests/php/s0-smoke.php  (exit 0 = all pass)
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

// 1. Hooks wired.
check('plugins_loaded hooks textdomain', in_array('tqk_wpseo_load_textdomain', $GLOBALS['__actions']['plugins_loaded'] ?? [], true));
check('init hooks bootstrap', in_array('tqk_wpseo_init', $GLOBALS['__actions']['init'] ?? [], true));
check('activation hook registered', isset($GLOBALS['__activation']));
check('deactivation hook registered', isset($GLOBALS['__deactivation']));

do_action('plugins_loaded');
check('textdomain loads tqk-wpseo', ($GLOBALS['__textdomain'][0] ?? '') === 'tqk-wpseo');

// 2. Activation: options, tables, meta, flush.
call_user_func($GLOBALS['__activation']);
check('settings seeded with title template', (get_option('tqk_wpseo_settings')['title_template'] ?? '') === '%title% | %site_name%');
check('version option set', get_option('tqk_wpseo_version') === TQK_WPSEO_VERSION);
check('5 tables created', count($GLOBALS['__tables']) === 5);
check('redirects table named', in_array('wp_tqk_wpseo_redirects', $GLOBALS['__tables'], true));
check('404 table named', in_array('wp_tqk_wpseo_404', $GLOBALS['__tables'], true));
check('links table named', in_array('wp_tqk_wpseo_links', $GLOBALS['__tables'], true));
check('scan table named', in_array('wp_tqk_wpseo_scan', $GLOBALS['__tables'], true));
check('gsc table named', in_array('wp_tqk_wpseo_gsc', $GLOBALS['__tables'], true));
check('13 postmeta keys', count($GLOBALS['__postmeta']) === 13);check('rewrite flushed on activate', $GLOBALS['__flushes'] === 1);

// 3. Menu: 1 top + 11 subs (Security scan added).
do_action('init');
check('one top menu (tqk-wpseo)', count($GLOBALS['__menus']) === 1 && $GLOBALS['__menus'][0]['slug'] === 'tqk-wpseo');
check('eleven submenus', count($GLOBALS['__submenus']) === 11);
check('all subs under top', count(array_filter($GLOBALS['__submenus'], fn($s) => $s['parent'] === 'tqk-wpseo')) === 11);
check(
    'hook names survive core admin_page_hooks overwrite',
    (function () {
        // Core rewrites $admin_page_hooks[TOP] to basename(slug) when building
        // the menu (wp-admin/includes/menu.php), after registration.
        $GLOBALS['admin_page_hooks'][\TQK_WPSEO\Admin\Menu::TOP_SLUG] = \TQK_WPSEO\Admin\Menu::TOP_SLUG;
        foreach (\TQK_WPSEO\Admin\Menu::items() as $slug => [$title, $method]) {
            $hook = get_plugin_page_hookname($slug, \TQK_WPSEO\Admin\Menu::TOP_SLUG);
            if (!has_action($hook)) {
                return false;
            }
        }
        return true;
    })()
);

// 4. Assets scoped (no Bootstrap): 1 style + admin.js + tools.js.
\TQK_WPSEO\Admin\Assets::enqueue('toplevel_page_tqk-wpseo');
check('3 assets on plugin screen', count($GLOBALS['__enqueued']['style']) === 1 && count($GLOBALS['__enqueued']['script']) === 2);
$GLOBALS['__enqueued'] = ['style' => [], 'script' => []];
\TQK_WPSEO\Admin\Assets::enqueue('edit.php');
check('zero assets off plugin screen', count($GLOBALS['__enqueued']['style']) === 0 && count($GLOBALS['__enqueued']['script']) === 0);
check('subscreen detected', \TQK_WPSEO\Admin\Assets::is_plugin_screen('seo_page_tqk-wpseo-redirects'));
check('foreign screen rejected', !\TQK_WPSEO\Admin\Assets::is_plugin_screen('edit.php'));

// 5. Dashboard renders: wrapper, stats, real checklist passes.
ob_start();
\TQK_WPSEO\Admin\Pages::dashboard();
$dash = (string) ob_get_clean();
check('dashboard has scope wrapper', str_contains($dash, 'tqk-wpseo'));
check('dashboard hero: logo, tagline, support line (no score ring)', str_contains($dash, 'tqk-hero-logo') && str_contains($dash, 'Website Audit &amp; Optimization Engine') && str_contains($dash, '0364 999 916') && !str_contains($dash, 'tqk-wpseo-ring'));
check('dashboard honest empty state', str_contains($dash, 'No page checked yet'));
check('dashboard explains each page', str_contains($dash, 'tqk-guide-card'));
check('dashboard has no header scan button', !str_contains($dash, 'Run SEO Scan'));
check('dashboard checklist renders', str_contains($dash, 'tqk-wpseo-checklist'));
$checklist_html = (string) (explode('</ul>', explode('tqk-wpseo-checklist', $dash)[1] ?? '')[0] ?? '');
check('no failed badges in checklist', !str_contains($checklist_html, 'is-bad'));

// 6. Redirects page is functional (S5).
ob_start();
\TQK_WPSEO\Admin\Pages::redirects();
$ph = (string) ob_get_clean();
check('redirects page has add form', str_contains($ph, 'Add redirect'));

// 7. Deactivation flushes, keeps data.
call_user_func($GLOBALS['__deactivation']);
check('rewrite flushed on deactivate', $GLOBALS['__flushes'] === 2);
check('data kept on deactivate', get_option('tqk_wpseo_settings') !== false);

echo $fail === 0 ? "\nALL S0 CHECKS PASSED\n" : "\n{$fail} CHECKS FAILED\n";
exit($fail === 0 ? 0 : 1);
