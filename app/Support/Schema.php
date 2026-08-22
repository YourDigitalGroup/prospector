<?php

declare(strict_types=1);

namespace Prospector\Support;

/**
 * Idempotent schema installer. Runs on every boot; each step is a no-op once
 * applied, so deploying a new version needs no separate migration command.
 */
final class Schema
{
    public static function install(): void
    {
        $mysql = Database::driver() === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fk = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $tables = [
            "CREATE TABLE IF NOT EXISTS users (
                id {$id},
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'user',
                password_hash VARCHAR(255) NULL,
                requires_password INTEGER NOT NULL DEFAULT 1,
                loop VARCHAR(40) NOT NULL DEFAULT 'none',
                geography VARCHAR(190) NULL,
                daily_email INTEGER NOT NULL DEFAULT 1,
                autorun INTEGER NOT NULL DEFAULT 1,
                active INTEGER NOT NULL DEFAULT 1,
                ghl_location_id VARCHAR(120) NULL,
                ghl_token TEXT NULL,
                last_login_at VARCHAR(25) NULL,
                created_at VARCHAR(25) NOT NULL,
                updated_at VARCHAR(25) NOT NULL
            ){$suffix}",

            "CREATE TABLE IF NOT EXISTS runs (
                id {$id},
                user_id {$fk} NOT NULL,
                loop VARCHAR(40) NOT NULL,
                run_date VARCHAR(10) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'running',
                trigger_source VARCHAR(20) NOT NULL DEFAULT 'cron',
                vertical VARCHAR(190) NULL,
                geography VARCHAR(190) NULL,
                lead_count INTEGER NOT NULL DEFAULT 0,
                brief_md TEXT NULL,
                error TEXT NULL,
                model VARCHAR(60) NULL,
                input_tokens INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                emailed_at VARCHAR(25) NULL,
                started_at VARCHAR(25) NOT NULL,
                finished_at VARCHAR(25) NULL
            ){$suffix}",

            "CREATE TABLE IF NOT EXISTS leads (
                id {$id},
                user_id {$fk} NOT NULL,
                run_id {$fk} NULL,
                company VARCHAR(190) NOT NULL,
                company_key VARCHAR(190) NOT NULL,
                website VARCHAR(255) NULL,
                vertical VARCHAR(80) NULL,
                door VARCHAR(80) NULL,
                market VARCHAR(120) NULL,
                state VARCHAR(40) NULL,
                decision_maker VARCHAR(190) NULL,
                title VARCHAR(190) NULL,
                email VARCHAR(190) NULL,
                email_confidence VARCHAR(20) NULL,
                phone VARCHAR(60) NULL,
                direct_phone VARCHAR(60) NULL,
                linkedin VARCHAR(255) NULL,
                fit_score INTEGER NOT NULL DEFAULT 0,
                why TEXT NULL,
                hook TEXT NULL,
                evidence TEXT NULL,
                source VARCHAR(190) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'new',
                owner_note TEXT NULL,
                ghl_contact_id VARCHAR(120) NULL,
                ghl_synced_at VARCHAR(25) NULL,
                archived_at VARCHAR(25) NULL,
                created_at VARCHAR(25) NOT NULL,
                updated_at VARCHAR(25) NOT NULL
            ){$suffix}",

            "CREATE TABLE IF NOT EXISTS activities (
                id {$id},
                lead_id {$fk} NOT NULL,
                user_id {$fk} NULL,
                type VARCHAR(40) NOT NULL,
                body TEXT NULL,
                created_at VARCHAR(25) NOT NULL
            ){$suffix}",

            "CREATE TABLE IF NOT EXISTS settings (
                skey VARCHAR(120) NOT NULL PRIMARY KEY,
                svalue TEXT NULL,
                updated_at VARCHAR(25) NOT NULL
            ){$suffix}",

            // One row per lead per cadence step. The opening email is step 1, so
            // drafting one email and building a six-step cadence write to the
            // same place rather than to two parallel systems.
            //
            // due_on is a local date rather than a timestamp: a cadence step is
            // due on a day, and pinning it to an hour would only invent a
            // precision the sender does not have.
            "CREATE TABLE IF NOT EXISTS emails (
                id {$id},
                lead_id {$fk} NOT NULL,
                user_id {$fk} NOT NULL,
                step INTEGER NOT NULL,
                day_offset INTEGER NOT NULL DEFAULT 0,
                purpose VARCHAR(190) NULL,
                subject VARCHAR(255) NULL,
                body TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                due_on VARCHAR(10) NULL,
                approved_at VARCHAR(25) NULL,
                sent_at VARCHAR(25) NULL,
                error TEXT NULL,
                model VARCHAR(60) NULL,
                created_at VARCHAR(25) NOT NULL,
                updated_at VARCHAR(25) NOT NULL
            ){$suffix}",
        ];

        foreach ($tables as $sql) {
            Database::pdo()->exec($sql);
        }

        $indexes = [
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users (email)',
            'CREATE INDEX IF NOT EXISTS idx_leads_user ON leads (user_id)',
            'CREATE INDEX IF NOT EXISTS idx_leads_status ON leads (status)',
            'CREATE INDEX IF NOT EXISTS idx_leads_created ON leads (created_at)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_leads_dedupe ON leads (user_id, company_key)',
            'CREATE INDEX IF NOT EXISTS idx_runs_user_date ON runs (user_id, run_date)',
            'CREATE INDEX IF NOT EXISTS idx_activities_lead ON activities (lead_id)',
            // Unique so regenerating a cadence replaces each step in place. Two
            // step 3s for one lead is not a state worth being able to reach.
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_emails_step ON emails (lead_id, step)',
            'CREATE INDEX IF NOT EXISTS idx_emails_due ON emails (status, due_on)',
            'CREATE INDEX IF NOT EXISTS idx_emails_owner ON emails (user_id, status)',
        ];

        foreach ($indexes as $sql) {
            try {
                Database::pdo()->exec($sql);
            } catch (\PDOException $e) {
                // MySQL below 8.0.29 lacks IF NOT EXISTS on CREATE INDEX; a
                // duplicate-index error there means the index is already in place.
                if (!str_contains($e->getMessage(), 'Duplicate') && !str_contains($e->getMessage(), 'exists')) {
                    throw $e;
                }
            }
        }

        // CREATE TABLE IF NOT EXISTS does nothing to a table that already
        // exists, so columns added after a release need their own step. Keep
        // entries here forever: they are how an already-deployed database
        // catches up on the next request.
        self::addColumns([
            ['users', 'ghl_pipeline_id', 'VARCHAR(120) NULL'],
            // A dig runs in the background, so its state has to outlive the
            // request that started it.
            ['leads', 'dig_status', 'VARCHAR(20) NULL'],
            ['leads', 'dig_result', 'TEXT NULL'],
            ['leads', 'dig_message', 'TEXT NULL'],
            ['leads', 'dig_at', 'VARCHAR(25) NULL'],
        ]);
    }

    /**
     * Add columns that are missing, and leave the ones that are not alone.
     *
     * @param list<array{0: string, 1: string, 2: string}> $columns table, column, definition
     */
    private static function addColumns(array $columns): void
    {
        foreach ($columns as [$table, $column, $definition]) {
            if (self::hasColumn($table, $column)) {
                continue;
            }

            try {
                Database::pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            } catch (\PDOException $e) {
                // Lost a race with another request, which is fine.
                if (!str_contains($e->getMessage(), 'duplicate') && !str_contains($e->getMessage(), 'Duplicate')) {
                    throw $e;
                }
            }
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        if (Database::driver() === 'mysql') {
            $row = Database::first(
                'SELECT COUNT(*) AS n FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
                ['t' => $table, 'c' => $column]
            );

            return (int) ($row['n'] ?? 0) > 0;
        }

        // PRAGMA takes no bound parameters, hence the identifier check rather
        // than a placeholder.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException('Refusing to inspect a table with an unexpected name.');
        }

        foreach (Database::all("PRAGMA table_info({$table})") as $info) {
            if (($info['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }
}
