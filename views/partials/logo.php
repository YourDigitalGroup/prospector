<?php
/**
 * Inline pickaxe mark: a bold curved head crossing a long diagonal handle.
 * Uses currentColor so it takes the surrounding text colour in both themes.
 *
 * @var int|string|null $size
 */
$logoSize = isset($size) && $size !== null ? (string) $size : '26';
?>
<svg class="pickaxe" viewBox="0 0 32 32" width="<?= htmlspecialchars($logoSize) ?>"
     height="<?= htmlspecialchars($logoSize) ?>" fill="none" stroke="currentColor"
     stroke-linecap="round" aria-hidden="true" focusable="false">
    <path d="M11.5 4 Q 23.5 4.5 27 14.5" stroke-width="3.6"/>
    <path d="M5.5 27.5 L 20.5 8" stroke-width="3"/>
</svg>
