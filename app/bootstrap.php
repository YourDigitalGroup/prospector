<?php

declare(strict_types=1);

/**
 * Shared boot sequence for the web front controller and the CLI runner.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Prospector needs PHP 8.1 or newer. This server is running ' . PHP_VERSION . '.');
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Dependencies are missing. Upload the vendor/ directory, or run "composer install".');
}
require $autoload;

use Prospector\Auth;
use Prospector\Support\Clock;
use Prospector\Support\Database;
use Prospector\Support\Schema;
use Prospector\Support\Settings;
use Prospector\Support\View;

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/config.php';

Database::configure($config);
Clock::setTimezone((string) ($config['timezone'] ?? 'America/Chicago'));
date_default_timezone_set('UTC');
View::setBaseUrl($config['base_url'] ?? null);

foreach (['storage', 'storage/logs', 'storage/cache'] as $dir) {
    $path = dirname(__DIR__) . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

Schema::install();

// config.php values become the defaults for anything not set in the UI.
$defaults = [
    'run_hour' => (string) ($config['run_hour'] ?? 7),
    'run_minute' => (string) ($config['run_minute'] ?? 30),
    'run_weekdays_only' => !empty($config['run_weekdays_only']) ? '1' : '0',
    'batch_size' => (string) ($config['batch_size'] ?? 10),
    'min_fit_score' => (string) ($config['min_fit_score'] ?? 70),
    'model' => (string) ($config['model'] ?? 'claude-opus-5'),
    'dedupe_days' => (string) ($config['dedupe_days'] ?? 365),
];

foreach ($defaults as $key => $value) {
    if (Settings::get($key) === '') {
        Settings::set($key, $value);
    }
}

Auth::seed();

View::share([
    'appName' => (string) ($config['app_name'] ?? 'Prospector'),
    'config' => $config,
]);

return $config;
