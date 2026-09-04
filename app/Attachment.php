<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Settings;

/**
 * Files attached to an outbound email.
 *
 * GoHighLevel takes attachments as a list of URLs rather than as uploaded
 * bytes, so an attachment here is a file this application hosts and a link it
 * hands over. The recipient's mail client fetches it, which means the URL has
 * to be absolute, public, and still working tomorrow — these are not temporary.
 *
 * **The extension is an allow-list, and it is the whole security model.** These
 * files cannot be re-encoded the way a signature image can: a PDF is a PDF and
 * there is no safe round trip through GD. So what can be uploaded is named
 * explicitly, and everything else is refused. Two exclusions are worth stating
 * out loud because they look harmless:
 *
 * - **SVG is refused.** It is an image everywhere except in a browser, where it
 *   is a document that can carry script. Served from our own origin, an opened
 *   SVG runs that script as us.
 * - **HTML is refused**, for the same reason and more obviously.
 *
 * Files land under a random directory rather than a random filename, so the
 * name the recipient sees when they save it is the name the sender chose —
 * "proposal.pdf", not "9f2c…e1.pdf" — while the directory keeps the URL
 * unguessable and stops two people's proposal.pdf colliding.
 */
final class Attachment
{
    public const DIR = 'assets/uploads/attachments';

    /** GoHighLevel is more generous than this; a mail server usually is not. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** How many can ride along on one message. */
    public const MAX_FILES = 5;

    /**
     * What may be uploaded, and what each is called on screen.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'pdf' => 'PDF',
        'png' => 'Image',
        'jpg' => 'Image',
        'jpeg' => 'Image',
        'gif' => 'Image',
        'webp' => 'Image',
        'doc' => 'Word',
        'docx' => 'Word',
        'xls' => 'Spreadsheet',
        'xlsx' => 'Spreadsheet',
        'ppt' => 'Slides',
        'pptx' => 'Slides',
        'csv' => 'CSV',
        'txt' => 'Text',
        'zip' => 'Archive',
    ];

    /** @return array{extensions: list<string>, megabytes: int, files: int} */
    public static function limits(): array
    {
        return [
            'extensions' => array_keys(self::ALLOWED),
            'megabytes' => (int) (self::MAX_BYTES / 1024 / 1024),
            'files' => self::MAX_FILES,
        ];
    }

    /** The accept attribute for the file input. */
    public static function accept(): string
    {
        return '.' . implode(',.', array_keys(self::ALLOWED));
    }

    /**
     * Store one uploaded file and return the path to reference it by.
     *
     * @param array<string, mixed> $file a $_FILES entry
     * @return array{ok: bool, path: string, name: string, size: int, message: string}
     */
    public static function store(array $file): array
    {
        $fail = static fn (string $why): array
            => ['ok' => false, 'path' => '', 'name' => '', 'size' => 0, 'message' => $why];

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return $fail('No file was chosen.');
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $fail('That file is bigger than the server accepts.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            return $fail('That upload did not complete. Try again.');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            return $fail('That file is empty.');
        }

        if ($size > self::MAX_BYTES) {
            return $fail('That file is over ' . self::limits()['megabytes'] . 'MB.');
        }

        $original = self::safeName((string) ($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if (!array_key_exists($extension, self::ALLOWED)) {
            return $fail(
                ($extension === '' ? 'That file has no extension' : '.' . $extension . ' files are not accepted')
                . ' — allowed: ' . implode(', ', array_keys(self::ALLOWED)) . '.'
            );
        }

        $folder = bin2hex(random_bytes(16));
        $dir = self::directory() . '/' . $folder;

        if (!self::prepare($dir)) {
            return $fail('Could not write to ' . self::DIR . ' on the server.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $moved = is_uploaded_file($tmp)
            ? move_uploaded_file($tmp, $dir . '/' . $original)
            // Only reachable from a test harness, which fabricates the entry
            // rather than going through a real upload.
            : (PHP_SAPI === 'cli' && @rename($tmp, $dir . '/' . $original));

        if (!$moved) {
            @rmdir($dir);

            return $fail('Could not save that file.');
        }

        return [
            'ok' => true,
            'path' => $folder . '/' . $original,
            'name' => $original,
            'size' => $size,
            'message' => 'Attached.',
        ];
    }

    /**
     * The absolute URL a mail client will fetch, or null if the file is gone.
     *
     * From the remembered public host rather than the current request, for the
     * same reason signature images are: a send from cron has no request.
     */
    public static function url(string $path): ?string
    {
        if (!self::exists($path)) {
            return null;
        }

        return rtrim(Settings::publicUrl(), '/') . '/' . self::DIR . '/'
            . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public static function exists(string $path): bool
    {
        return self::isSafePath($path) && is_file(self::directory() . '/' . $path);
    }

    public static function label(string $path): string
    {
        $name = basename($path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return (self::ALLOWED[$extension] ?? 'File') . ' · ' . $name;
    }

    public static function delete(string $path): void
    {
        if (!self::exists($path)) {
            return;
        }

        $full = self::directory() . '/' . $path;
        @unlink($full);
        @rmdir(dirname($full));
    }

    /**
     * Turn a list of stored paths into the URLs GoHighLevel wants.
     *
     * Anything that has gone missing is dropped rather than sent as a broken
     * link, and the caller is told how many made it.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public static function urls(array $paths): array
    {
        $urls = [];

        foreach (array_slice($paths, 0, self::MAX_FILES) as $path) {
            $url = self::url($path);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    public static function directory(): string
    {
        return dirname(__DIR__) . '/' . self::DIR;
    }

    /**
     * A filename that cannot escape its directory or surprise a web server.
     *
     * Everything outside a conservative set becomes a hyphen, leading dots go
     * so nothing becomes a dotfile, and the result is capped because some
     * filesystems stop at 255 bytes.
     */
    private static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $name) ?? $name;
        $name = preg_replace('/-{2,}/', '-', $name) ?? $name;
        $name = ltrim($name, '.-');
        $name = trim($name);

        if ($name === '') {
            return 'file';
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = mb_substr($stem === '' ? 'file' : $stem, 0, 80);

        return $extension === '' ? $stem : $stem . '.' . $extension;
    }

    /** A stored path is 32 hex characters, a slash, and a safe filename. */
    private static function isSafePath(string $path): bool
    {
        return preg_match('#^[0-9a-f]{32}/[A-Za-z0-9._ -]{1,90}$#', $path) === 1
            && !str_contains($path, '..');
    }

    private static function prepare(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $guard = self::directory() . '/.htaccess';

        if (!is_file($guard)) {
            // Nothing executable can be uploaded, but this makes that true
            // rather than merely intended.
            @file_put_contents(
                $guard,
                "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8\n"
            );
        }

        return is_writable($dir);
    }
}
