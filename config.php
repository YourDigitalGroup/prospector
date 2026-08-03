<?php

declare(strict_types=1);

/**
 * Prospector configuration.
 *
 * Everything here has a working default, so the app runs immediately after
 * upload with no edits. Operational secrets (Anthropic key, SMTP, GoHighLevel)
 * are NOT stored here — they are entered in Settings and encrypted in the
 * database. See config.local.php.sample to override any value below on a
 * per-server basis without touching version control.
 */

$config = [
    // Public base URL, used in emails. Auto-detected when null.
    'base_url' => null,

    'app_name' => 'Prospector',

    // 'sqlite' needs no setup at all. Switch to 'mysql' and fill the creds
    // below if you would rather use a cPanel MySQL database.
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/storage/prospector.sqlite',
        'host' => 'localhost',
        'port' => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
    ],

    // Timezone every schedule and displayed date is evaluated in.
    'timezone' => 'America/Chicago',

    // Daily batch delivery time, local to the timezone above.
    'run_hour' => 7,
    'run_minute' => 30,

    // Skip Saturday and Sunday batches. Business prospecting on a weekend
    // just ages the lead before anyone can call it.
    'run_weekdays_only' => true,

    // Leads requested per user per batch.
    'batch_size' => 10,

    // Minimum fit score that may be delivered. Below this the batch comes
    // back short rather than padded.
    'min_fit_score' => 70,

    'model' => 'claude-opus-5',

    // How far back to look when de-duplicating against already-sent leads.
    'dedupe_days' => 365,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    /** @var array $overrides */
    $overrides = require $local;
    if (is_array($overrides)) {
        $config = array_replace_recursive($config, $overrides);
    }
}

return $config;
