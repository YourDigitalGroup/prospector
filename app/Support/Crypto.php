<?php

declare(strict_types=1);

namespace Prospector\Support;

use RuntimeException;

/**
 * Authenticated encryption for values that have to live in the database:
 * the Anthropic key, SMTP password, GoHighLevel tokens.
 *
 * The key is generated on first boot into storage/app_key.php, which is
 * excluded from version control so it never leaves the server.
 *
 * Two ciphers are supported, and which one is used depends on what the host
 * actually has. libsodium is preferred, but it is NOT universally compiled into
 * cPanel PHP builds — referencing SODIUM_* unconditionally is a fatal error on
 * a host without it, which is exactly what happened in production. OpenSSL with
 * AES-256-GCM is the fallback: also authenticated, and present everywhere.
 *
 *   enc:v1:  libsodium secretbox   (24-byte nonce || ciphertext)
 *   enc:v2:  OpenSSL AES-256-GCM   (12-byte IV || 16-byte tag || ciphertext)
 *
 * Both take the same 32-byte key, so a host that gains or loses libsodium keeps
 * reading everything it wrote before — no re-entry of credentials, no key
 * migration. Decryption always dispatches on the stored prefix rather than on
 * what this host happens to prefer today.
 */
final class Crypto
{
    /** Shared by both ciphers: secretbox and AES-256 both want 32 bytes. */
    private const KEY_BYTES = 32;

    private const SECRETBOX_NONCE_BYTES = 24;
    private const GCM_IV_BYTES = 12;
    private const GCM_TAG_BYTES = 16;

    private const GCM_CIPHER = 'aes-256-gcm';

    private static ?string $key = null;
    private static ?string $driver = null;

    /**
     * Which cipher this host will write with: 'sodium' or 'openssl'.
     *
     * Checked with function_exists rather than extension_loaded because some
     * hosts load a partial build, and the cipher list is checked because
     * AES-256-GCM needs OpenSSL 1.0.1+.
     */
    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        if (function_exists('sodium_crypto_secretbox')) {
            return self::$driver = 'sodium';
        }

        if (function_exists('openssl_encrypt') && in_array(self::GCM_CIPHER, openssl_get_cipher_methods(), true)) {
            return self::$driver = 'openssl';
        }

        throw new RuntimeException(
            'No authenticated cipher available. This host needs either the sodium extension '
            . 'or OpenSSL with AES-256-GCM. Enable one in cPanel under Select PHP Version → Extensions.'
        );
    }

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $path = dirname(__DIR__, 2) . '/storage/app_key.php';

        if (is_file($path)) {
            /** @var mixed $stored */
            $stored = require $path;
            if (is_string($stored) && strlen($stored) === self::KEY_BYTES * 2) {
                return self::$key = (string) hex2bin($stored);
            }
        }

        $key = random_bytes(self::KEY_BYTES);
        $written = @file_put_contents(
            $path,
            "<?php\n\n// Generated automatically. Do not commit or share.\nreturn '" . bin2hex($key) . "';\n",
            LOCK_EX
        );

        if ($written === false) {
            throw new RuntimeException(
                'Cannot write storage/app_key.php. Make the storage/ directory writable (chmod 755 or 775).'
            );
        }

        @chmod($path, 0600);

        return self::$key = $key;
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        if (self::driver() === 'sodium') {
            $nonce = random_bytes(self::SECRETBOX_NONCE_BYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::key());

            return 'enc:v1:' . base64_encode($nonce . $cipher);
        }

        $iv = random_bytes(self::GCM_IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::GCM_CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed: ' . (openssl_error_string() ?: 'unknown OpenSSL error.'));
        }

        return 'enc:v2:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Values written before encryption was in place, or hand-edited in the
        // database, are returned as-is rather than throwing.
        if (str_starts_with($value, 'enc:v1:')) {
            return self::openSecretbox(substr($value, 7));
        }

        if (str_starts_with($value, 'enc:v2:')) {
            return self::openGcm(substr($value, 7));
        }

        return $value;
    }

    /** Constant-time comparison for cron tokens and similar shared secrets. */
    public static function matches(string $known, string $given): bool
    {
        return $known !== '' && hash_equals($known, $given);
    }

    /**
     * A value this host cannot open — wrong key, truncated column, or the
     * extension that wrote it since removed — comes back empty rather than
     * fatal, so a bad credential shows as "not set" and can be re-entered
     * instead of taking the whole screen down.
     */
    private static function openSecretbox(string $encoded): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= self::SECRETBOX_NONCE_BYTES) {
            return '';
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, self::SECRETBOX_NONCE_BYTES),
            substr($raw, 0, self::SECRETBOX_NONCE_BYTES),
            self::key()
        );

        return $plain === false ? '' : $plain;
    }

    private static function openGcm(string $encoded): string
    {
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= self::GCM_IV_BYTES + self::GCM_TAG_BYTES) {
            return '';
        }

        $plain = openssl_decrypt(
            substr($raw, self::GCM_IV_BYTES + self::GCM_TAG_BYTES),
            self::GCM_CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::GCM_IV_BYTES),
            substr($raw, self::GCM_IV_BYTES, self::GCM_TAG_BYTES)
        );

        return $plain === false ? '' : $plain;
    }
}
