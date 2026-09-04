<?php

declare(strict_types=1);

namespace Prospector;

use Prospector\Support\Database;
use Prospector\Support\Settings;
use Prospector\Support\View;

/**
 * The sign-off on the end of a seller's outbound email.
 *
 * Structured fields rather than a free-text box, for two reasons. A signature
 * has to render as HTML for the logo to appear at all, and letting people paste
 * their own HTML into something that goes out over their own sending domain is
 * a way to end up debugging somebody's stray </table> at nine in the morning.
 * Fields in, a consistent block out, both HTML and plain text.
 *
 * Three things about the image are worth knowing before touching it.
 *
 * **It is re-encoded, never stored as uploaded.** The file is decoded with GD
 * and written back out as a fresh PNG, so whatever was in the original — an
 * EXIF payload, a polyglot file that is also valid script, a malformed header
 * aimed at an image parser — does not survive the round trip. The bytes served
 * are bytes this application wrote.
 *
 * **The URL has to be absolute and publicly reachable**, because it is fetched
 * by the recipient's mail client from outside. That is also why it cannot use
 * View::baseUrl() directly: a cadence email sent by cron has no HTTP request to
 * derive a host from and would embed http://localhost. Settings remembers the
 * last host a real browser used instead.
 *
 * **A data: URI would be easier and would not work.** Gmail, Outlook and Apple
 * Mail all refuse them in HTML mail, so the image would simply be missing for
 * most recipients.
 */
final class Signature
{
    /** Where re-encoded logos live. Web-served, so mail clients can fetch them. */
    public const DIR = 'assets/uploads/signatures';

    /**
     * The logo box.
     *
     * Deliberately small. A signature logo sits beside four lines of type in a
     * reading pane that is often 500px wide, so anything wider pushes the name
     * and phone number off the side — which is exactly what 480px did. A
     * square headshot lands at 90x90 here, which is also about right.
     */
    private const MAX_WIDTH = 260;
    private const MAX_HEIGHT = 90;

    /** Uploads above this are refused before GD is asked to decode them. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * The fields, in the order they appear on the form and in the rendering.
     *
     * @var array<string, array{label: string, hint?: string, placeholder?: string}>
     */
    private const FIELDS = [
        'name' => ['label' => 'Name', 'placeholder' => 'Scott Ecklund'],
        'title' => ['label' => 'Title', 'placeholder' => 'Owner'],
        'company' => ['label' => 'Company', 'placeholder' => '44i'],
        'phone' => ['label' => 'Phone', 'placeholder' => '(605) 555-0100'],
        'email' => ['label' => 'Email', 'placeholder' => 'scott@44interactive.com'],
        'website' => ['label' => 'Website', 'placeholder' => '44i.com'],
        'tagline' => [
            'label' => 'One more line',
            'placeholder' => 'Full-service digital for broadcasters and agencies',
            'hint' => 'Optional. A strapline, an office address, a booking link.',
        ],
    ];

    /**
     * The logo box, for a screen that wants to say what it is.
     *
     * Read rather than written out in the template, because a hint that says
     * 480x160 next to code that stores 260x90 is worse than no hint.
     *
     * @return array{width: int, height: int, megabytes: int}
     */
    public static function limits(): array
    {
        return [
            'width' => self::MAX_WIDTH,
            'height' => self::MAX_HEIGHT,
            'megabytes' => (int) (self::MAX_BYTES / 1024 / 1024),
        ];
    }

    /** @return array<string, array{label: string, hint?: string, placeholder?: string}> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * This person's signature as stored.
     *
     * @param array<string, mixed>|null $user
     * @return array<string, string>
     */
    public static function forUser(?array $user): array
    {
        $blank = array_fill_keys(array_keys(self::FIELDS), '');
        $blank['image'] = '';
        $blank['image_w'] = '';
        $blank['image_h'] = '';

        if ($user === null) {
            return $blank;
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) ($user['signature_json'] ?? ''), true);

        if (!is_array($decoded)) {
            // Nothing structured yet. The old free-text sign-off, if there is
            // one, becomes the name line rather than being thrown away — it was
            // usually a name and a company to begin with.
            $legacy = trim((string) ($user['email_signature'] ?? ''));

            if ($legacy !== '') {
                $lines = preg_split('/\R/', $legacy) ?: [];
                $blank['name'] = trim((string) ($lines[0] ?? ''));
                $blank['company'] = trim((string) ($lines[1] ?? ''));
                $blank['email'] = trim((string) ($lines[2] ?? ''));
            }

            return $blank;
        }

        foreach (array_keys($blank) as $key) {
            $blank[$key] = trim((string) ($decoded[$key] ?? ''));
        }

        return $blank;
    }

    /**
     * What a signature starts as for someone who has not set one up.
     *
     * @param array<string, mixed> $user
     * @return array<string, string>
     */
    public static function suggested(array $user): array
    {
        return array_merge(array_fill_keys(array_keys(self::FIELDS), ''), [
            'name' => trim((string) $user['name']),
            'company' => '44i',
            'email' => trim((string) $user['email']),
            'image' => '',
            'image_w' => '',
            'image_h' => '',
        ]);
    }

    /**
     * Save it. Only the known fields are kept, so nothing else can be smuggled
     * into the JSON by adding a form input.
     *
     * @param array<string, string> $values
     */
    public static function save(int $userId, array $values, string $image = '', int $width = 0, int $height = 0): void
    {
        $clean = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $value = trim($values[$field] ?? '');
            if ($value !== '') {
                $clean[$field] = mb_substr($value, 0, 190);
            }
        }

        if ($image !== '') {
            $clean['image'] = $image;
            if ($width > 0 && $height > 0) {
                $clean['image_w'] = (string) $width;
                $clean['image_h'] = (string) $height;
            }
        }

        Database::update('users', [
            'signature_json' => $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_SLASHES),
        ], ['id' => $userId]);
    }

    public static function isEmpty(array $signature): bool
    {
        foreach ($signature as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------- rendering

    /**
     * The plain-text sign-off, for a text message and as the fallback body of
     * an email whose recipient does not take HTML.
     *
     * @param array<string, string> $signature
     */
    public static function text(array $signature): string
    {
        $lines = [];

        $first = array_filter([$signature['name'] ?? '', $signature['title'] ?? '']);
        if ($first !== []) {
            $lines[] = implode(', ', $first);
        }

        foreach (['company', 'phone', 'email', 'website', 'tagline'] as $field) {
            $value = trim((string) ($signature[$field] ?? ''));
            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The HTML sign-off.
     *
     * Table-based and inline-styled on purpose. Mail clients strip <style>
     * blocks, ignore flexbox, and Outlook renders through Word — a table with
     * inline styles is the only layout that survives all of them.
     *
     * @param array<string, string> $signature
     */
    public static function html(array $signature): string
    {
        if (self::isEmpty($signature)) {
            return '';
        }

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $muted = 'color:#6b7280;';
        $rows = [];

        $name = trim((string) ($signature['name'] ?? ''));
        $title = trim((string) ($signature['title'] ?? ''));

        if ($name !== '' || $title !== '') {
            $rows[] = '<div style="font-weight:700;color:#14171c;">' . $e($name)
                . ($title !== '' ? '<span style="font-weight:400;' . $muted . '"> · ' . $e($title) . '</span>' : '')
                . '</div>';
        }

        $company = trim((string) ($signature['company'] ?? ''));
        if ($company !== '') {
            $rows[] = '<div style="' . $muted . '">' . $e($company) . '</div>';
        }

        $contact = [];
        $phone = trim((string) ($signature['phone'] ?? ''));
        if ($phone !== '') {
            $contact[] = '<a href="tel:' . $e(preg_replace('/[^0-9+]/', '', $phone) ?? $phone)
                . '" style="color:#6b7280;text-decoration:none;">' . $e($phone) . '</a>';
        }

        $email = trim((string) ($signature['email'] ?? ''));
        if ($email !== '') {
            $contact[] = '<a href="mailto:' . $e($email) . '" style="color:#6b7280;text-decoration:none;">'
                . $e($email) . '</a>';
        }

        $website = trim((string) ($signature['website'] ?? ''));
        if ($website !== '') {
            $href = preg_match('#^https?://#i', $website) === 1 ? $website : 'https://' . ltrim($website, '/');
            $contact[] = '<a href="' . $e($href) . '" style="color:#6b7280;text-decoration:none;">'
                . $e(preg_replace('#^https?://#i', '', $website) ?? $website) . '</a>';
        }

        if ($contact !== []) {
            $rows[] = '<div style="' . $muted . '">' . implode(' &nbsp;·&nbsp; ', $contact) . '</div>';
        }

        $tagline = trim((string) ($signature['tagline'] ?? ''));
        if ($tagline !== '') {
            $rows[] = '<div style="' . $muted . 'padding-top:2px;">' . $e($tagline) . '</div>';
        }

        $block = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;">'
            . implode('', $rows) . '</div>';

        $imageUrl = self::imageUrl($signature['image'] ?? '');
        $width = (int) ($signature['image_w'] ?? 0);
        $height = (int) ($signature['image_h'] ?? 0);

        if ($imageUrl !== null) {
            // Two cells rather than a float: Outlook does not float.
            return '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
                . '<tr>'
                . '<td style="padding:0 14px 0 0;vertical-align:top;">'
                . '<img src="' . $e($imageUrl) . '" alt="' . $e($company !== '' ? $company : $name) . '"'
                . ($width > 0 ? ' width="' . $width . '"' : '')
                . ($height > 0 ? ' height="' . $height . '"' : '')
                . ' style="display:block;border:0;max-width:' . self::MAX_WIDTH . 'px;height:auto;">'
                . '</td>'
                . '<td style="vertical-align:top;">' . $block . '</td>'
                . '</tr></table>';
        }

        return $block;
    }

    /**
     * The absolute URL of a stored logo, or null if there is not one.
     *
     * Absolute because a mail client fetches it from outside; from the
     * remembered public host rather than the current request, because a cadence
     * email is sent by cron, where there is no request.
     */
    public static function imageUrl(string $image): ?string
    {
        if ($image === '' || !self::imageExists($image)) {
            return null;
        }

        return rtrim(Settings::publicUrl(), '/') . '/' . self::DIR . '/' . $image;
    }

    public static function imageExists(string $image): bool
    {
        return self::isSafeName($image) && is_file(self::directory() . '/' . $image);
    }

    // ------------------------------------------------------------- uploading

    /**
     * Take an uploaded file and store a re-encoded copy.
     *
     * @param array<string, mixed> $file a $_FILES entry
     * @return array{ok: bool, image: string, width?: int, height?: int, message: string}
     */
    public static function storeUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'image' => '', 'message' => 'No file was chosen.'];
        }

        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'image' => '', 'message' => 'That upload did not complete. Try again.'];
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'image' => '', 'message' => 'That image is over 2MB. A signature logo wants to be much smaller.'];
        }

        $path = (string) ($file['tmp_name'] ?? '');

        // getimagesize reads the header rather than trusting the browser's
        // content-type, which is supplied by whoever is uploading.
        $info = @getimagesize($path);

        if ($info === false) {
            return ['ok' => false, 'image' => '', 'message' => 'That file is not an image this server can read.'];
        }

        $source = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if ($source === false) {
            return ['ok' => false, 'image' => '', 'message' => 'Use a PNG, JPEG, GIF or WebP.'];
        }

        $resized = self::fit($source);
        $name = bin2hex(random_bytes(16)) . '.png';
        $dir = self::directory();

        if (!self::prepareDirectory($dir)) {
            return ['ok' => false, 'image' => '', 'message' => 'Could not write to ' . self::DIR . ' on the server.'];
        }

        // Always PNG, whatever came in: one format to serve, transparency kept,
        // and the re-encode is what makes the stored bytes ours rather than
        // whatever was uploaded.
        if (!imagepng($resized, $dir . '/' . $name, 8)) {
            return ['ok' => false, 'image' => '', 'message' => 'Could not save the image.'];
        }

        return [
            'ok' => true,
            'image' => $name,
            'width' => imagesx($resized),
            'height' => imagesy($resized),
            'message' => 'Logo saved.',
        ];
    }

    public static function deleteImage(string $image): void
    {
        if (self::imageExists($image)) {
            @unlink(self::directory() . '/' . $image);
        }
    }

    public static function directory(): string
    {
        return dirname(__DIR__) . '/' . self::DIR;
    }

    /** Scale down to fit the box, preserving aspect and transparency. */
    private static function fit(\GdImage $source): \GdImage
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(self::MAX_WIDTH / $w, self::MAX_HEIGHT / $h, 1);

        if ($scale >= 1) {
            return $source;
        }

        $out = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
        imagecopyresampled($out, $source, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);

        return $out;
    }

    /**
     * A stored name is 32 hex characters and .png, because this code wrote it.
     * Checked anyway before it is ever concatenated onto a path.
     */
    private static function isSafeName(string $image): bool
    {
        return preg_match('/^[0-9a-f]{32}\.png$/', $image) === 1;
    }

    private static function prepareDirectory(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        // Belt and braces. Everything written here is a PNG produced by GD, so
        // there should never be anything to execute; this makes that true even
        // if that stops being the case.
        $guard = $dir . '/.htaccess';

        if (!is_file($guard)) {
            @file_put_contents($guard, "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8\n");
        }

        return is_writable($dir);
    }
}
