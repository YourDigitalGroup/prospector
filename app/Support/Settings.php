<?php

declare(strict_types=1);

namespace Prospector\Support;

/**
 * Key/value application settings, editable from the Settings screen so no
 * secret ever has to be committed or hand-edited over FTP.
 *
 * Keys listed in SECRETS are encrypted at rest and never echoed back to the
 * browser — the UI shows a "saved" indicator and accepts a replacement instead.
 */
final class Settings
{
    public const SECRETS = [
        'anthropic_api_key',
        'smtp_password',
        'ghl_token',
        'cron_token',
        'worker_token',
    ];

    private const DEFAULTS = [
        'anthropic_api_key' => '',
        'mail_from_email' => '',
        'mail_from_name' => 'Prospector',
        'mail_transport' => 'mail',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_secure' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'ghl_token' => '',
        'ghl_location_id' => '',
        'ghl_pipeline_id' => '',
        'ghl_stage_id' => '',
        'ghl_auto_push' => '0',
        'cron_token' => '',
        'worker_token' => '',
        'engine' => 'api',
        'worker_last_seen' => '',
        'worker_label' => '',
        'worker_engine' => '',
        'run_hour' => '',
        'run_minute' => '',
        'run_weekdays_only' => '',
        'batch_size' => '',
        'min_fit_score' => '',
        'effort' => 'high',
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::DEFAULTS;

        foreach (Database::all('SELECT skey, svalue FROM settings') as $row) {
            $key = (string) $row['skey'];
            $raw = (string) ($row['svalue'] ?? '');
            $values[$key] = in_array($key, self::SECRETS, true) ? Crypto::decrypt($raw) : $raw;
        }

        return self::$cache = $values;
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = self::all()[$key] ?? $default;

        return $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::all()[$key] ?? '';

        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::all()[$key] ?? '';

        return ($value === '' || !is_numeric($value)) ? $default : (int) $value;
    }

    public static function set(string $key, string $value): void
    {
        $stored = in_array($key, self::SECRETS, true) ? Crypto::encrypt($value) : $value;
        $now = Clock::now();

        if (Database::driver() === 'mysql') {
            Database::run(
                'INSERT INTO settings (skey, svalue, updated_at) VALUES (:k, :v, :u)
                 ON DUPLICATE KEY UPDATE svalue = :v2, updated_at = :u2',
                ['k' => $key, 'v' => $stored, 'u' => $now, 'v2' => $stored, 'u2' => $now]
            );
        } else {
            Database::run(
                'INSERT INTO settings (skey, svalue, updated_at) VALUES (:k, :v, :u)
                 ON CONFLICT(skey) DO UPDATE SET svalue = excluded.svalue, updated_at = excluded.updated_at',
                ['k' => $key, 'v' => $stored, 'u' => $now]
            );
        }

        self::$cache = null;
    }

    /**
     * Remove a setting entirely.
     *
     * Different from setting it to '': absent means "no value", which is what a
     * transient marker wants when the thing it was marking is over.
     */
    public static function forget(string $key): void
    {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $key]);
        self::$cache = null;
    }

    /** @param array<string, string> $values */
    public static function setMany(array $values): void
    {
        Database::transaction(static function () use ($values): void {
            foreach ($values as $key => $value) {
                self::set($key, $value);
            }
        });
    }

    public static function hasSecret(string $key): bool
    {
        return self::get($key) !== '';
    }

    /** The Anthropic key may also come from the environment on hosts that support it. */
    public static function anthropicKey(): string
    {
        $env = getenv('ANTHROPIC_API_KEY');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return self::get('anthropic_api_key');
    }

    public static function cronToken(): string
    {
        return self::ensureToken('cron_token');
    }

    /** Shared secret for the batch worker API. */
    public static function workerToken(): string
    {
        return self::ensureToken('worker_token');
    }

    private static function ensureToken(string $key): string
    {
        $token = self::get($key);

        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            self::set($key, $token);
        }

        return $token;
    }

    /**
     * Which brain runs the daily batch.
     *
     * api    — Prospector calls the Anthropic API itself
     * worker — an external machine does the work and posts results back
     * manual — a person runs the loop and pastes the result in
     */
    public static function engine(): string
    {
        $engine = self::get('engine', 'api');

        return in_array($engine, ['api', 'worker', 'manual'], true) ? $engine : 'api';
    }
}
