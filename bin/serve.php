<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server, for looking at Prospector locally without
 * Apache. Not used in production — the .htaccess rewrite does this job there.
 *
 *   php -S 127.0.0.1:8000 -t . bin/serve.php
 *
 * Serves real files straight off disk and sends everything else to the front
 * controller, mirroring the .htaccess rules — including the deny list, so a
 * local run has the same blind spots as the live site.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$root = dirname(__DIR__);
$file = realpath($root . $path);

$blocked = '#^/(app|storage|vendor|bin|\.git|\.github)(/|$)|/(composer\.(json|lock)|config(\.local)?\.php|README\.md|\.gitignore)$#i';

if (preg_match($blocked, $path) === 1) {
    http_response_code(404);
    echo 'Not found.';

    return true;
}

if ($file !== false && is_file($file) && str_starts_with($file, $root) && !str_ends_with($file, '.php')) {
    return false; // let the built-in server stream it
}

if ($file !== false && is_file($file) && basename($file) === 'cron.php') {
    require $file;

    return true;
}

require $root . '/index.php';

return true;
