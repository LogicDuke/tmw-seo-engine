<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * At-rest encryption for third-party API credentials.
 *
 * Mirrors the GSC OAuth-token encryption pattern (sodium_crypto_secretbox
 * keyed on wp_salt('auth') + AUTH_KEY) but with a distinct key-derivation
 * context so the API-key keyspace is cryptographically separate from the
 * GSC keyspace. That means a hypothetical key compromise in one path
 * doesn't decrypt the other.
 *
 * Storage format:
 *   - "enc:" + base64(nonce || ciphertext)  — sodium-encrypted (preferred)
 *   - "b64:" + base64(plaintext)            — fallback if libsodium isn't
 *                                             available (obfuscation only,
 *                                             not encryption)
 *   - anything else                          — treated as legacy plain text
 *                                             and returned unchanged on
 *                                             decrypt(). This is the
 *                                             back-compat hinge that lets
 *                                             existing production DBs keep
 *                                             working until the next save
 *                                             through an encrypted path.
 *
 * The "return non-prefixed values unchanged" behaviour is deliberate. With
 * it, no separate migration script is required: existing plain-text values
 * keep being read as-is; the first save through any encrypted-save path
 * (autopilot admin form, engine Settings save, CrakRevenue settings save)
 * silently re-stores them encrypted. Once all production saves have round-
 * tripped at least once, all stored secrets are encrypted on disk.
 *
 * Tests sidestep this transparently: they call update_option(..., 'plain')
 * directly, and when read through decrypt() the value comes back unchanged.
 */
class Crypto {

    /**
     * Sentinel prefix for sodium-encrypted ciphertext.
     */
    private const SODIUM_PREFIX = 'enc:';

    /**
     * Sentinel prefix for libsodium-unavailable base64 fallback.
     */
    private const B64_PREFIX = 'b64:';

    /**
     * Key-derivation context. Distinct from the GSC encryption context so
     * the two key spaces are cryptographically separated.
     */
    private const KEY_CONTEXT = 'tmwseo_api_credentials_v1';

    /**
     * Encrypt a plaintext string. Empty strings pass through unchanged.
     *
     * @param string $plaintext Raw secret (API key, password, token).
     * @return string Ciphertext with sentinel prefix, or empty string.
     */
    public static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }

        // Idempotency: if the input already carries one of our sentinel
        // prefixes, return it unchanged. This makes encrypt() safe to call
        // on a mixed batch where some values are new plaintext (from form
        // input) and others were merged in from already-encrypted storage
        // (sanitize_settings does this when a form omits a field and
        // falls back to the existing value via `?? $existing[...]`).
        if (strpos($plaintext, self::SODIUM_PREFIX) === 0
            || strpos($plaintext, self::B64_PREFIX) === 0
        ) {
            return $plaintext;
        }

        if (!function_exists('sodium_crypto_secretbox')) {
            // Obfuscation fallback — better than nothing for installations
            // missing libsodium, but the operator should fix that.
            return self::B64_PREFIX . base64_encode($plaintext);
        }

        $key   = self::derive_key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return self::SODIUM_PREFIX . base64_encode($nonce . $box);
    }

    /**
     * Decrypt a stored value. Returns plain text unchanged for back-compat
     * with values written before encryption was added; only values carrying
     * a recognised sentinel prefix are run through the cipher.
     *
     * @param string $ciphertext Stored value (encrypted, b64-fallback, or
     *                           legacy plain text).
     * @return string Decrypted plaintext, or the input unchanged if it
     *                doesn't carry a sentinel prefix.
     */
    public static function decrypt(string $ciphertext): string {
        if ($ciphertext === '') {
            return '';
        }

        if (strpos($ciphertext, self::B64_PREFIX) === 0) {
            return (string) base64_decode(substr($ciphertext, strlen(self::B64_PREFIX)));
        }

        if (strpos($ciphertext, self::SODIUM_PREFIX) !== 0
            || !function_exists('sodium_crypto_secretbox_open')
        ) {
            // Legacy plain-text row (or unrecognised prefix). Pass through.
            return $ciphertext;
        }

        $decoded = base64_decode(substr($ciphertext, strlen(self::SODIUM_PREFIX)));
        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $key = self::derive_key();
        } catch (\RuntimeException $e) {
            // AUTH_KEY undefined — surface the configuration problem in the
            // security log and degrade gracefully. We CAN'T throw here:
            // Settings::all() calls decrypt_in() on every engine boot, so
            // raising the exception would brick the plugin (admin pages,
            // cron, REST — all dead) for as long as wp-config is misconfigured.
            // Returning ciphertext unchanged means downstream API clients
            // see a malformed key and fail their own validation, which
            // surfaces to the operator without taking down the whole engine.
            Logs::error('security', 'API-key decryption skipped — AUTH_KEY undefined', [
                'reason' => $e->getMessage(),
            ]);
            return $ciphertext;
        }
        $plaintext = sodium_crypto_secretbox_open($box, $nonce, $key);

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Read a top-level WP option and transparently decrypt it.
     * Use for autopilot's flat-option secrets (tmwseo_openai_api_key,
     * tmwseo_serper_api_key, etc.).
     */
    public static function get_option_secret(string $option, string $default = ''): string {
        $raw = (string) get_option($option, $default);
        return self::decrypt($raw);
    }

    /**
     * Encrypt + write a top-level WP option. Always stored with
     * autoload=false because credentials don't need to be loaded on every
     * page request.
     */
    public static function update_option_secret(string $option, string $value): bool {
        return update_option($option, self::encrypt($value), false);
    }

    /**
     * Decrypt every value at the listed keys inside an associative array.
     * Use for structured options (engine's tmwseo_engine_settings,
     * CrakRevenue's tmwseo_crakrevenue_api_settings) where only some
     * fields are secrets.
     */
    public static function decrypt_in(array $data, array $secret_keys): array {
        foreach ($secret_keys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = self::decrypt($data[$key]);
            }
        }
        return $data;
    }

    /**
     * Inverse of decrypt_in() — encrypt every value at the listed keys
     * before the array is persisted.
     */
    public static function encrypt_in(array $data, array $secret_keys): array {
        foreach ($secret_keys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                $data[$key] = self::encrypt($data[$key]);
            }
        }
        return $data;
    }

    /**
     * Derive a 32-byte symmetric key from WordPress's auth salt + AUTH_KEY,
     * scoped by KEY_CONTEXT so this keyspace can't decrypt GSC ciphertext
     * (and vice versa) even if both contexts ran with identical sodium
     * primitives. Tying the key to wp-config secrets means a leaked DB
     * dump is useless without the corresponding wp-config.php.
     *
     * Throws when AUTH_KEY is undefined or empty rather than silently
     * substituting a hardcoded fallback string. The previous fallback —
     * 'tmwseo_fallback_key_no_auth_key_defined' — was identical across
     * every install of this plugin, so any encryption performed without
     * AUTH_KEY had a known component visible to anyone with source-code
     * access. wp_salt('auth') still provides install-specific entropy
     * in that situation, but composing a deterministic-source key with
     * a known string is a footgun better caught loudly here than
     * silently weakened.
     *
     * Encrypt callers (encrypt, encrypt_in, update_option_secret) let
     * the exception bubble — a failed save is a clearer signal than a
     * silently weak ciphertext. Decrypt callers catch and treat the
     * value as undecryptable so a misconfigured wp-config doesn't take
     * down every engine page load via Settings::all().
     */
    private static function derive_key(): string {
        $auth = defined('AUTH_KEY') ? AUTH_KEY : '';
        if ($auth === '') {
            throw new \RuntimeException(
                'TMWSEO encryption refuses to derive a key: AUTH_KEY is not defined in wp-config.php. '
                . 'Define it (and re-save any credentials encrypted while it was missing, since the '
                . 'existing ciphertext cannot be decrypted with a different key) before continuing.'
            );
        }
        $salt = function_exists('wp_salt') ? wp_salt('auth') : '';
        return substr(
            hash('sha256', $salt . $auth . self::KEY_CONTEXT, true),
            0,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }
}
