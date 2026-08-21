<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Clock;
use Prospector\Support\Crypto;
use Prospector\Support\Database;

final class Users
{
    public const LOOPS = [
        'partner' => 'Partner Prospector (whitelabel resellers)',
        'client' => 'Client Prospector (direct AOR clients)',
        'home' => 'Home Prospector (home trades and retail, 100-mile radius)',
        'none' => 'No daily loop',
    ];

    /**
     * The loops that can actually run a batch — everything in LOOPS except
     * 'none'. Kept here as the single source of truth because five callers used
     * to carry their own copy of this list, which is one edit too many for
     * adding a loop.
     */
    public const RUNNABLE_LOOPS = ['partner', 'client', 'home'];

    public static function isRunnableLoop(string $loop): bool
    {
        return in_array($loop, self::RUNNABLE_LOOPS, true);
    }

    /** @return list<array<string, mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM users';
        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= " ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, name";

        return Database::all($sql);
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return Database::first(
            'SELECT * FROM users WHERE email = :email',
            ['email' => strtolower(trim($email))]
        );
    }

    /** Users who should receive an automated batch. */
    public static function scheduled(): array
    {
        return Database::all(
            "SELECT * FROM users WHERE active = 1 AND autorun = 1 AND loop <> 'none' ORDER BY name"
        );
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $now = Clock::now();

        return Database::insert('users', [
            'name' => (string) $data['name'],
            'email' => strtolower(trim((string) $data['email'])),
            'role' => in_array($data['role'] ?? 'user', ['admin', 'user'], true) ? (string) $data['role'] : 'user',
            'password_hash' => isset($data['password']) && $data['password'] !== ''
                ? password_hash((string) $data['password'], PASSWORD_DEFAULT)
                : null,
            'requires_password' => !empty($data['requires_password']) ? 1 : 0,
            'loop' => array_key_exists((string) ($data['loop'] ?? 'none'), self::LOOPS) ? (string) $data['loop'] : 'none',
            'geography' => $data['geography'] ?? null,
            'daily_email' => isset($data['daily_email']) ? (int) (bool) $data['daily_email'] : 1,
            'autorun' => isset($data['autorun']) ? (int) (bool) $data['autorun'] : 1,
            'active' => isset($data['active']) ? (int) (bool) $data['active'] : 1,
            'ghl_location_id' => $data['ghl_location_id'] ?? null,
            'ghl_token' => isset($data['ghl_token']) && $data['ghl_token'] !== ''
                ? Crypto::encrypt((string) $data['ghl_token'])
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        $allowed = [
            'name', 'email', 'role', 'requires_password', 'loop', 'geography',
            'daily_email', 'autorun', 'active', 'ghl_location_id', 'ghl_pipeline_id',
        ];

        $update = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (isset($update['email'])) {
            $update['email'] = strtolower(trim((string) $update['email']));
        }

        if (!empty($data['password'])) {
            $update['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }

        if (array_key_exists('ghl_token', $data)) {
            $token = (string) $data['ghl_token'];
            $update['ghl_token'] = $token === '' ? null : Crypto::encrypt($token);
        }

        if ($update === []) {
            return;
        }

        $update['updated_at'] = Clock::now();
        Database::update('users', $update, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::transaction(static function () use ($id): void {
            Database::run(
                'DELETE FROM activities WHERE lead_id IN (SELECT id FROM leads WHERE user_id = :id)',
                ['id' => $id]
            );
            Database::run('DELETE FROM leads WHERE user_id = :id', ['id' => $id]);
            Database::run('DELETE FROM runs WHERE user_id = :id', ['id' => $id]);
            Database::run('DELETE FROM users WHERE id = :id', ['id' => $id]);
        });
    }

    /** @param array<string, mixed> $user */
    public static function ghlToken(array $user): string
    {
        $token = (string) ($user['ghl_token'] ?? '');

        return $token === '' ? '' : Crypto::decrypt($token);
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        return $letters === '' ? '?' : $letters;
    }
}
