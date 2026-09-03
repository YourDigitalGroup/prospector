<?php

declare(strict_types=1);

/**
 * Rebuild the favicons from the logo.
 *
 *   php bin/favicons.php
 *
 * Reads assets/img/logo.png — the supplied artwork — and writes:
 *
 *   assets/img/favicon-32.png    the browser tab
 *   assets/img/favicon-180.png   the iOS home screen
 *
 * Both are a straight downscale of the artwork, not a redrawing of it, so
 * whenever the logo changes this script is the whole of the update: run it,
 * commit the two PNGs, done.
 *
 * The one thing it adds is a ground. The mark is a single fluorescent lime on
 * transparency, which is exactly right inside the app — the chrome behind it is
 * dark — but a favicon has no such luxury. It lands on whatever the browser's
 * tab strip happens to be, and on a light one a lime silhouette all but
 * vanishes. So the icons get the app's own dark surface behind them, which
 * makes them legible on any tab strip and keeps them recognisably Prospector.
 *
 * Apple's icon is squared off and fully opaque on purpose: iOS masks the
 * corners itself and composites nothing, so a rounded, transparent source comes
 * out with black corners.
 */

$root = dirname(__DIR__);
$source = $root . '/assets/img/logo.png';

if (!is_file($source)) {
    fwrite(STDERR, "No artwork at assets/img/logo.png — nothing to build from.\n");
    exit(1);
}

$logo = @imagecreatefrompng($source);

if ($logo === false) {
    fwrite(STDERR, "assets/img/logo.png is not a PNG this build of PHP can read.\n");
    exit(1);
}

imagealphablending($logo, true);

$srcW = imagesx($logo);
$srcH = imagesy($logo);

/** The app's --surface, so the icon reads as a piece of the same product. */
const GROUND = [0x16, 0x18, 0x1c];

/**
 * Draw the mark on a dark ground at the given size.
 *
 * @param float $inset  fraction of the canvas left clear around the mark
 * @param float $round  corner radius as a fraction of the canvas; 0 squares it
 */
function icon(\GdImage $logo, int $size, float $inset, float $round): \GdImage
{
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagealphablending($canvas, true);

    $ground = imagecolorallocate($canvas, GROUND[0], GROUND[1], GROUND[2]);
    $radius = (int) round($size * $round);

    if ($radius > 0) {
        // Rounded square, drawn as a cross of rectangles plus four corner
        // discs. imagefilledarc is the only rounding primitive GD has.
        imagefilledrectangle($canvas, $radius, 0, $size - $radius - 1, $size - 1, $ground);
        imagefilledrectangle($canvas, 0, $radius, $size - 1, $size - $radius - 1, $ground);
        $d = $radius * 2;
        imagefilledarc($canvas, $radius, $radius, $d, $d, 180, 270, $ground, IMG_ARC_PIE);
        imagefilledarc($canvas, $size - $radius - 1, $radius, $d, $d, 270, 360, $ground, IMG_ARC_PIE);
        imagefilledarc($canvas, $radius, $size - $radius - 1, $d, $d, 90, 180, $ground, IMG_ARC_PIE);
        imagefilledarc($canvas, $size - $radius - 1, $size - $radius - 1, $d, $d, 0, 90, $ground, IMG_ARC_PIE);
    } else {
        imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1, $ground);
    }

    // The artwork is a wide-ish triangle in a square frame with air all round
    // it, so it is placed on the canvas whole rather than cropped to its ink.
    $box = (int) round($size * (1 - $inset * 2));
    $at = (int) round(($size - $box) / 2);

    imagecopyresampled($canvas, $logo, $at, $at, 0, 0, $box, $box, imagesx($logo), imagesy($logo));

    return $canvas;
}

$targets = [
    // Tab strip. Rounded, and only a hair of inset — at 32px every pixel of
    // the mark is worth having.
    'favicon-32.png' => ['size' => 32, 'inset' => 0.03, 'round' => 0.19],
    // iOS. Square and opaque; the system rounds it.
    'favicon-180.png' => ['size' => 180, 'inset' => 0.10, 'round' => 0.0],
];

foreach ($targets as $name => $spec) {
    $icon = icon($logo, $spec['size'], $spec['inset'], $spec['round']);
    $path = $root . '/assets/img/' . $name;

    if (!imagepng($icon, $path, 9)) {
        fwrite(STDERR, "Could not write {$path}\n");
        exit(1);
    }

    printf("%-18s %dx%d  %s\n", $name, $spec['size'], $spec['size'], number_format((int) filesize($path)) . ' bytes');
}

echo "Rebuilt from assets/img/logo.png ({$srcW}x{$srcH}).\n";
