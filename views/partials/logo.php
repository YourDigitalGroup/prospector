<?php

/**
 * The Prospector mark: two crossed pickaxes under a swept arch, with a mountain
 * between them. Drawn with currentColor so it takes the surrounding text colour
 * in both themes.
 *
 * Two variants, because the mountain needs room. Below about 40px its peaks
 * collapse into one blob welded to the arch, so at small sizes the compact
 * variant is used — the same crossed pickaxes, slightly heavier, no mountain.
 * That is the usual way a logo ships its favicon, and the silhouette stays
 * recognisably the same. assets/img/prospector-mark.svg is the full mark as a
 * standalone file, for decks and anywhere it can be shown large.
 *
 * Note the handles stop at y=95.5, not 99: with an 8.4 stroke and a butt cap the
 * paint extends past the path end, and at 99 the bottom of the mark was being
 * clipped by the viewBox.
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
$handle = $isFull ? '8.4' : '10';
$collar = $isFull ? '12.8' : '14.4';
$butt = $isFull ? '11.6' : '12.8';

?>
<svg class="pickaxe brand-mark" viewBox="0 0 100 100" width="<?= $logoSize ?>" height="<?= $logoSize ?>"
     fill="currentColor" aria-hidden="true" focusable="false">
<?php if ($isFull): ?>
    <path d="M2.5 64 C2.5 36 21 7 50 1 L56 13.5 C30 20.5 11.5 41.5 6 58.5 Z"/>
    <path d="M97.5 64 C97.5 36 79 7 50 1 L44 13.5 C70 20.5 88.5 41.5 94 58.5 Z"/>
    <path d="M40 30 L45 19.5 L47.3 22.6 L50 15.5 L54.4 24.2 L56.8 21 L60 30 Z"/>
<?php else: ?>
    <path d="M2.5 63 C2.5 35 21 7 50 1 L56 15.5 C29.5 22 11 41 5.5 57.5 Z"/>
    <path d="M97.5 63 C97.5 35 79 7 50 1 L44 15.5 C70.5 22 89 41 94.5 57.5 Z"/>
<?php endif; ?>
    <g stroke="currentColor" stroke-linecap="butt" fill="none">
        <path d="M26 16 L78 95.5" stroke-width="<?= $handle ?>"/>
        <path d="M74 16 L22 95.5" stroke-width="<?= $handle ?>"/>
        <path d="M27.7 18.7 L32.5 26.3" stroke-width="<?= $collar ?>"/>
        <path d="M72.3 18.7 L67.5 26.3" stroke-width="<?= $collar ?>"/>
        <path d="M74.3 90 L77.5 94.8" stroke-width="<?= $butt ?>"/>
        <path d="M25.7 90 L22.5 94.8" stroke-width="<?= $butt ?>"/>
    </g>
</svg>
