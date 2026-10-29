<?php
// Appended by preview run: wizard shots.
require __DIR__ . '/stubs.php';
require dirname(__DIR__, 2) . '/tqk-wp-seo.php';
call_user_func($GLOBALS['__activation']);
do_action('init');
$out = 'C:\\Users\\KHAI\\AppData\\Local\\Temp\\opencode\\tqk-ui';
@mkdir($out, 0777, true);
copy(dirname(__DIR__, 2) . '/assets/vendor/bootstrap-scoped.min.css', $out . '/bootstrap-scoped.min.css');
copy(dirname(__DIR__, 2) . '/assets/css/tqk-wpseo-admin.css', $out . '/tqk-wpseo-admin.css');
$_GET = [];
$_POST = [];
ob_start();
TQK_WPSEO\Admin\Wizard::render();
file_put_contents($out . '/wizard-welcome.html', tqk_ui_shell((string) ob_get_clean()));
$_GET = ['step' => 3];
$_POST = [];
ob_start();
TQK_WPSEO\Admin\Wizard::render();
file_put_contents($out . '/wizard-step3.html', tqk_ui_shell((string) ob_get_clean()));
echo "wizard shots wrote\n";
function tqk_ui_shell($body) {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<link rel="stylesheet" href="bootstrap-scoped.min.css">'
        . '<link rel="stylesheet" href="tqk-wpseo-admin.css">'
        . '<style>body{background:#f0f0f1;padding:20px;font-family:sans-serif;}</style>'
        . '</head><body>' . $body . '</body></html>';
}
