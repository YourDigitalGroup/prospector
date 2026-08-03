<?php

declare(strict_types=1);

namespace Prospector\Support;

/**
 * A prospecting batch takes minutes — well past a normal request. Where the
 * host runs PHP-FPM we finish the HTTP response first and keep working after
 * the browser has been let go. Everywhere else we raise the limits and run
 * synchronously, which still works but makes the caller wait.
 */
final class Background
{
    public static function canDetach(): bool
    {
        return function_exists('fastcgi_finish_request');
    }

    /**
     * Send the response now, then continue executing.
     *
     * @param array<string, string> $headers
     */
    public static function respondThenContinue(string $body = '', int $status = 200, array $headers = []): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);

        if (!headers_sent()) {
            http_response_code($status);
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
            header('Content-Length: ' . strlen($body));
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        echo $body;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }

        // Non-FPM fallback: flush what we have and keep going. The connection
        // may stay open until the work finishes.
        flush();
    }

    /** Raise limits for work that runs inside the request. */
    public static function extendLimits(int $seconds = 900): void
    {
        ignore_user_abort(true);
        @set_time_limit($seconds);
        @ini_set('max_execution_time', (string) $seconds);
        @ini_set('memory_limit', '512M');
    }

    public static function log(string $message): void
    {
        $file = dirname(__DIR__, 2) . '/storage/logs/prospector.log';
        $line = '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $message . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
