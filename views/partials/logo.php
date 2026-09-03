<?php

/**
 * The Prospector mark.
 *
 * Prefers the supplied artwork. Drop the real logo in as one of:
 *
 *     assets/img/logo.svg      (best — scales, and can inherit currentColor)
 *     assets/img/logo.png      (fine — used at its own colours)
 *
 * and it is used everywhere the mark appears, with no code change. Nothing
 * else in the app cares which one is in play.
 *
 * Failing that it falls back to the drawing below, which was made by eye from a
 * picture of the artwork rather than traced from the file — the image rendered
 * in conversation but its bytes never reached this machine. It is a rendition,
 * not a copy, and it is meant to be replaced.
 *
 * Two variants of the fallback, because detail needs room. Below about 44px the
 * lightning notches in the slopes silt up into a smudge and the thin blade tip
 * disappears, so small sizes get the compact variant: the same silhouette with
 * the notches dropped. A supplied SVG is used at every size as-is; a supplied
 * PNG likewise, so make sure it is big enough to survive the 26px sidebar mark
 * being a downscale rather than an upscale.
 *
 * @var int|string|null $size     pixel size; defaults to 26
 * @var string|null     $variant  'auto' (default), 'compact', or 'full'
 */

$logoSize = isset($size) && $size !== null ? (int) $size : 26;
$logoVariant = isset($variant) && is_string($variant) ? $variant : 'auto';

if (!in_array($logoVariant, ['compact', 'full'], true)) {
    $logoVariant = $logoSize >= 44 ? 'full' : 'compact';
}

$isFull = $logoVariant === 'full';

// SVG first: it scales cleanly and, if it is drawn with currentColor, still
// takes the surrounding colour. A PNG is used as-is.
$logoDir = dirname(__DIR__, 2) . '/assets/img/';
$suppliedSvg = is_file($logoDir . 'logo.svg') ? $logoDir . 'logo.svg' : null;
$suppliedPng = is_file($logoDir . 'logo.png') ? $logoDir . 'logo.png' : null;

if ($suppliedSvg !== null) {
    $markup = (string) file_get_contents($suppliedSvg);

    // Strip anything that cannot legally sit inside a document body, then force
    // the size so one file serves the 26px sidebar and the 72px sign-in screen.
    $markup = preg_replace('/<\?xml.*?\?>/is', '', $markup) ?? $markup;
    $markup = preg_replace('/<!DOCTYPE.*?>/is', '', $markup) ?? $markup;
    $markup = preg_replace('/\s(width|height)="[^"]*"/i', '', $markup, 2) ?? $markup;
    $markup = preg_replace(
        '/<svg\b/i',
        '<svg class="pickaxe brand-mark" width="' . $logoSize . '" height="' . $logoSize
            . '" aria-hidden="true" focusable="false"',
        trim($markup),
        1
    ) ?? $markup;

    echo $markup;

    return;
}

if ($suppliedPng !== null) {
    printf(
        '<img class="pickaxe brand-mark" src="%s" width="%d" height="%d" alt="" aria-hidden="true">',
        \Prospector\Support\View::e(\Prospector\Support\View::url('assets/img/logo.png')),
        $logoSize,
        $logoSize
    );

    return;
}

?><svg class="pickaxe brand-mark" viewBox="0 0 100 100" width="<?= $logoSize ?>" height="<?= $logoSize ?>"
     fill="currentColor" aria-hidden="true" focusable="false">
    <?php /* The blade. One long crescent: a point at the left tip, thickening
             over the top, and tapering to a second point down at the right. */ ?>
    <path d="M15.4 16.6
             C31.2 8.8 51.4 10.2 65.0 19.4
             C75.4 26.8 81.4 37.0 83.0 47.6
             L74.6 43.2
             C72.0 36.2 66.4 28.4 57.8 23.2
             C45.4 16.2 29.0 16.0 18.8 21.2 Z"/>

    <?php /* The head — the chunky angled block the handle is driven into. */ ?>
    <path d="M54.0 11.6 L65.2 16.0 L61.2 26.4 L50.0 22.0 Z"/>

    <?php /* The handle, running down-left and tucking behind the near peak. */ ?>
    <path d="M56.8 21.8 L62.6 24.2 L38.4 62.0 L32.8 57.6 Z"/>

    <?php if ($isFull): ?>
        <?php /* The far peak: tall, apex left of centre, with a lightning
                 notch bitten out of the long right slope. */ ?>
        <path d="M62.6 36.4
                 L95.8 84.8
                 L84.0 79.2
                 L79.2 69.6
                 L75.8 74.8
                 L68.4 58.0
                 L56.4 79.2
                 L44.6 79.2 Z"/>

        <?php /* The near peak. Asymmetric on purpose: a long shallow slope
                 falling away to the left and a short steep one on the right,
                 which is what stops the two peaks reading as a matched pair. */ ?>
        <path d="M33.2 50.6
                 L46.8 82.8
                 L34.0 82.8
                 L24.2 74.0
                 L21.4 78.4
                 L17.2 72.6
                 L13.2 80.6
                 L7.4 85.0 Z"/>
    <?php else: ?>
        <?php /* Compact: the notches drop out, or they silt up at 26px. */ ?>
        <path d="M62.6 36.4 L95.8 84.8 L56.4 79.2 L44.6 79.2 Z"/>
        <path d="M33.2 50.6 L46.8 82.8 L34.0 82.8 L7.4 85.0 Z"/>
    <?php endif; ?>

    <?php /* The valley floor: a shallow crescent, thin at both ends. */ ?>
    <path d="M3.6 90.8
             C29.6 79.6 70.4 79.6 96.4 90.8
             C70.8 83.4 29.2 83.4 3.6 90.8 Z"/>
</svg>
