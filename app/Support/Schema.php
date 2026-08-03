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
    }
}
