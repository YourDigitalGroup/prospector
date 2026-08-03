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
 */
final class Crypto
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $path = dirname(__DIR__, 2) . '/storage/app_key.php';

        if (is_file($path)) {
            /** @var mixed $stored */
            $stored = require $path;
            if (is_string($stored) && strlen($stored) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2) {
                return self::$key = (string) hex2bin($stored);
            }
        }

        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
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

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::key());

        return 'enc:v1:' . base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Values written before encryption was in place, or hand-edited in the
        // database, are returned as-is rather than throwing.
        if (!str_starts_with($value, 'enc:v1:')) {
            return $value;
        }

        $raw = base64_decode(substr($value, 7), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? '' : $plain;
    }

    /** Constant-time comparison for cron tokens and similar shared secrets. */
    public static function matches(string $known, string $given): bool
    {
        return $known !== '' && hash_equals($known, $given);
    }
}
