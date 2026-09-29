<?php
/**
 * S14 — Secrets at rest: encryption, masking, export redaction, uninstall.
 */
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
function failures() {
    global $fail;
    return $fail;
}

use TQK_WPSEO\Admin\Settings;
use TQK_WPSEO\Core\Activator;
use TQK_WPSEO\Core\Secrets;

update_option('tqk_wpseo_settings', Activator::default_settings());

check('secret keys list', \TQK_WPSEO\Core\Secrets::keys() === ['gsc_api_key', 'gsc_token', 'bing_api_key', 'gemini_api_key']);
check('is_secret true', \TQK_WPSEO\Core\Secrets::is_secret('gsc_token'));
check('is_secret false', !\TQK_WPSEO\Core\Secrets::is_secret('gsc_property'));

// Round trip.
$ct = \TQK_WPSEO\Core\Secrets::encrypt('ya29.super-secret-token');
check('ciphertext is prefixed', \TQK_WPSEO\Core\Secrets::is_encrypted($ct));
check('ciphertext hides plaintext', !str_contains($ct, 'super-secret-token'));
check('decrypt round trip', \TQK_WPSEO\Core\Secrets::decrypt($ct) === 'ya29.super-secret-token');

// Empty + idempotency.
check('encrypt empty stays empty', \TQK_WPSEO\Core\Secrets::encrypt('') === '');
check('encrypt is idempotent', \TQK_WPSEO\Core\Secrets::encrypt($ct) === $ct);
check('decrypt empty', \TQK_WPSEO\Core\Secrets::decrypt('') === '');
check('decrypt null', \TQK_WPSEO\Core\Secrets::decrypt(null) === '');

// Legacy plaintext passes through unchanged (unmigrated install).
check('legacy plaintext passthrough', \TQK_WPSEO\Core\Secrets::decrypt('legacy-plain') === 'legacy-plain');

// Tamper detection: flip a byte of the ciphertext body.
$body = substr($ct, strlen(\TQK_WPSEO\Core\Secrets::PREFIX));
$raw = base64_decode($body, true);
$raw[40] = chr((ord($raw[40]) + 1) % 256);
$tampered = \TQK_WPSEO\Core\Secrets::PREFIX . base64_encode($raw);
check('tampered payload rejected', \TQK_WPSEO\Core\Secrets::decrypt($tampered) === '');

// Key is per-install and not autoloaded.
$key = \TQK_WPSEO\Core\Secrets::key();
check('key is 32 raw bytes', strlen($key) === 32);
check('key stable across calls', \TQK_WPSEO\Core\Secrets::key() === $key);

// Settings array helpers.
$arr = \TQK_WPSEO\Core\Secrets::encrypt_settings([
    'gsc_property' => 'sc-domain:example.test',
    'gsc_token'    => 'tok-123',
    'bing_api_key' => 'key-456',
]);
check('non-secret untouched', $arr['gsc_property'] === 'sc-domain:example.test');
check('secret gsc_token encrypted', \TQK_WPSEO\Core\Secrets::is_encrypted($arr['gsc_token']));
check('secret bing encrypted', \TQK_WPSEO\Core\Secrets::is_encrypted($arr['bing_api_key']));

$red = \TQK_WPSEO\Core\Secrets::redact($arr);
check('redact clears gsc_token', $red['gsc_token'] === '');
check('redact clears bing key', $red['bing_api_key'] === '');
check('redact keeps non-secret', $red['gsc_property'] === 'sc-domain:example.test');

// ---- Settings::sanitize stores ciphertext, get() returns plaintext ----
update_option('tqk_wpseo_settings', Activator::default_settings());
// sanitize() returns the value; persisting it is register_setting's / caller's job.
$saved = Settings::sanitize([
    'gsc_token'    => 'live-token-abc',
    'gsc_property' => 'sc-domain:example.test',
    'title_template' => '%title%',
]);
update_option('tqk_wpseo_settings', $saved);
$raw_option = get_option('tqk_wpseo_settings');
check('sanitize encrypts token in option', \TQK_WPSEO\Core\Secrets::is_encrypted($raw_option['gsc_token']));
check('option does not leak token', !str_contains(json_encode($raw_option), 'live-token-abc'));
check('get() returns plaintext token', \TQK_WPSEO\Admin\Settings::get('gsc_token') === 'live-token-abc');
check('get() non-secret unchanged', \TQK_WPSEO\Admin\Settings::get('gsc_property') === 'sc-domain:example.test');

// Masked/empty submission keeps the stored secret (password field resubmits '').
$saved2 = Settings::sanitize(['gsc_token' => '', 'title_template' => '%title% | X']);
update_option('tqk_wpseo_settings', $saved2);
check('empty secret keeps stored value', Settings::get('gsc_token') === 'live-token-abc');
check('other settings still saved', Settings::get('title_template') === '%title% | X');

// A real new secret does overwrite.
update_option('tqk_wpseo_settings', Settings::sanitize(['gsc_token' => 'rotated-token']));
check('new secret overwrites', Settings::get('gsc_token') === 'rotated-token');

// ---- Migration encrypts a legacy plaintext install ----
update_option('tqk_wpseo_settings', array_merge(Activator::default_settings(), [
    'gsc_token'    => 'legacy-plain-token',
    'bing_api_key' => 'legacy-bing-key',
]));
Activator::migrate_secrets();
$migrated = get_option('tqk_wpseo_settings');
check('migration encrypts legacy token', \TQK_WPSEO\Core\Secrets::is_encrypted($migrated['gsc_token']));
check('migration encrypts legacy bing', \TQK_WPSEO\Core\Secrets::is_encrypted($migrated['bing_api_key']));
check('migrated token still readable', \TQK_WPSEO\Admin\Settings::get('gsc_token') === 'legacy-plain-token');
check('migrated bing still readable', \TQK_WPSEO\Admin\Settings::get('bing_api_key') === 'legacy-bing-key');

// Idempotent.
$before = get_option('tqk_wpseo_settings');
Activator::migrate_secrets();
check('migration idempotent', get_option('tqk_wpseo_settings') === $before);

// ---- uninstall covers gsc table + secret key ----
$uninstall = file_get_contents(__DIR__ . '/../../uninstall.php');
check('uninstall drops gsc table', str_contains($uninstall, "'tqk_wpseo_gsc'"));
// The secret key option is tqk_wpseo_secret_key: covered by the prefix rule.
check('uninstall deletes secret key (prefix rule)', str_contains($uninstall, "option_name LIKE 'tqk\\\\_wpseo\\\\_%'"));
check('uninstall deletes gsc data (prefix rule)', str_contains($uninstall, "option_name LIKE 'tqk\\\\_wpseo\\\\_%'"));
check('uninstall table list matches activator', (function () {
    $src = file_get_contents(__DIR__ . '/../../uninstall.php');
    preg_match('/foreach \(\[(.*?)\] as \$t\)/s', $src, $m);
    preg_match_all("/'(tqk_wpseo_[a-z0-9_]+)'/", $m[1] ?? '', $found);
    $uninstall_tables = array_values($found[1]);
    $expected = array_map(
        static fn(string $t): string => str_replace('wp_', '', $t),
        Activator::table_names('wp_')
    );
    $missing = array_values(array_diff($expected, $uninstall_tables));
    if ($missing !== []) {
        echo "   missing from uninstall: " . implode(', ', $missing) . "\n";
    }
    return $missing === [];
})());

// ---- export never contains secrets ----
$export_src = file_get_contents(__DIR__ . '/../../src/Admin/Ajax.php');
check('export uses redact', str_contains($export_src, 'Secrets::redact'));

echo "\n";
if (failures() === 0) {
    echo "ALL SECRETS CHECKS PASSED\n";
} else {
    echo failures() . " SECRETS CHECKS FAILED\n";
    exit(1);
}
