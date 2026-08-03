<?php

declare(strict_types=1);

namespace Prospector\Support;

use RuntimeException;

final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    private static ?string $baseUrl = null;

    /** @param array<string, mixed> $data */
    public static function share(array $data): void
    {
        self::$shared = array_merge(self::$shared, $data);
    }

    public static function setBaseUrl(?string $url): void
    {
        if ($url !== null && $url !== '') {
            self::$baseUrl = rtrim($url, '/');
        }
    }

    /** Absolute base URL, auto-detected when not configured. */
    public static function baseUrl(): string
    {
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return self::$baseUrl = $scheme . '://' . $host . $dir;
    }

    /** Build an in-app URL. */
    public static function url(string $path = '', array $query = []): string
    {
        $path = ltrim($path, '/');
        $url = self::baseUrl() . '/' . ($path === '' ? '' : $path);

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $url;
    }

    public static function asset(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return self::url($path) . '?v=' . $version;
    }

    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = []): void
    {
        echo self::renderToString($template, $data);
    }

    /** @param array<string, mixed> $data */
    public static function renderToString(string $template, array $data = []): string
    {
        $file = dirname(__DIR__, 2) . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View '{$template}' not found.");
        }

        $scope = array_merge(self::$shared, $data);

        ob_start();
        (static function () use ($file, $scope): void {
            extract($scope, EXTR_SKIP);
            require $file;
        })();

        return (string) ob_get_clean();
    }

    /**
     * Render a page inside the app chrome.
     *
     * @param array<string, mixed> $data
     */
    public static function page(string $template, array $data = []): void
    {
        $data['content'] = self::renderToString($template, $data);
        echo self::renderToString('layout', $data);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Turn the model's Markdown brief into safe HTML. */
    public static function markdown(string $markdown): string
    {
        return Markdown::toHtml($markdown);
    }
}
