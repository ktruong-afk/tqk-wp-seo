<?php
namespace TQK_WPSEO\Core;

defined('ABSPATH') || exit;

/**
 * S14 — At-rest encryption for API secrets (GSC token/API key, Bing key).
 *
 * Design:
 *  - A per-install 32-byte key lives in its own autoload=false option, so the
 *    ciphertext in tqk_wpseo_settings is useless on its own and never sits in
 *    the options cache/autoload dump.
 *  - AES-256-GCM (authenticated) when the cipher is available, so tampered
 *    ciphertext decrypts to '' instead of garbage.
 *  - Ciphertext is prefixed 'tqk1:' so legacy plaintext installs can be detected
 *    and migrated on read without a version bump.
 *  - Encryption key loss means the secret is unrecoverable: the admin re-pastes
 *    it. That is the correct trade for a token that can be re-issued.
 */
class Secrets {

    public const KEY_OPTION = 'tqk_wpseo_secret_key';
    public const PREFIX = 'tqk1:';

    /** @return string[] Settings keys holding secret material. */
    public static function keys(): array {
        return ['gsc_api_key', 'gsc_token', 'bing_api_key', 'gemini_api_key'];
    }

    public static function is_secret(string $key): bool {
        return in_array($key, self::keys(), true);
    }

    /**
     * Per-install encryption key. Stored base64, autoload=false, created on
     * first use.
     */
    public static function key(): string {
        $stored = get_option(self::KEY_OPTION, '');
        if (is_string($stored) && $stored !== '') {
            $raw = base64_decode($stored, true);
            if (is_string($raw) && strlen($raw) === 32) {
                return $raw;
            }
        }
        $raw = function_exists('random_bytes') ? random_bytes(32) : hash('sha256', uniqid('', true), true);
        update_option(self::KEY_OPTION, base64_encode($raw), false);
        return $raw;
    }

    public static function is_encrypted(?string $value): bool {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * @return string Ciphertext ('tqk1:' prefixed) or '' for an empty input.
     */
    public static function encrypt(string $plain): string {
        $plain = trim($plain);
        if ($plain === '' || self::is_encrypted($plain)) {
            return self::is_encrypted($plain) ? $plain : '';
        }
        if (!function_exists('openssl_encrypt') || !self::cipher_supported()) {
            // No authenticated cipher: fall back to a reversible transform that
            // still keeps the value out of plaintext options and exports.
            return self::PREFIX . 'b64:' . base64_encode($plain);
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if (!is_string($ct)) {
            return self::PREFIX . 'b64:' . base64_encode($plain);
        }
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    /**
     * Decrypts a stored value. Passes legacy plaintext through unchanged.
     * Returns '' when the payload is unreadable or tampered.
     */
    public static function decrypt(?string $stored): string {
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        if (!self::is_encrypted($stored)) {
            return $stored;
        }
        $body = substr($stored, strlen(self::PREFIX));
        if (str_starts_with($body, 'b64:')) {
            $plain = base64_decode(substr($body, 4), true);
            return is_string($plain) ? $plain : '';
        }
        if (!function_exists('openssl_decrypt') || !self::cipher_supported()) {
            return '';
        }
        $raw = base64_decode($body, true);
        if (!is_string($raw) || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plain) ? $plain : '';
    }

    private static function cipher_supported(): bool {
        static $ok = null;
        if ($ok === null) {
            $ok = function_exists('openssl_encrypt')
                && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
        }
        return $ok;
    }

    /**
     * Encrypts secrets inside a settings array. Non-secret keys untouched.
     */
    public static function encrypt_settings(array $settings): array {
        foreach (self::keys() as $k) {
            if (array_key_exists($k, $settings)) {
                $settings[$k] = self::encrypt((string) $settings[$k]);
            }
        }
        return $settings;
    }

    /**
     * Removes secret material from a settings array before export/logging.
     * Keys are kept as empty strings so an import stays a valid settings file.
     */
    public static function redact(array $settings): array {
        foreach (self::keys() as $k) {
            if (array_key_exists($k, $settings)) {
                $settings[$k] = '';
            }
        }
        return $settings;
    }
}
