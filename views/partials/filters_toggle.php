<?php

/**
 * The button that folds the filter panel away on a phone.
 *
 * Hidden above 720px, where the panel is a single row and there is nothing to
 * fold. Sits immediately before the .filters form it controls.
 *
 * It carries the count of filters actually narrowing the list, because a
 * collapsed panel can otherwise hide the reason a screen looks empty. Pass the
 * same $hasFilters / $query pair the page already computes for its "Clear
 * filters" link.
 *
 * @var bool                $hasFilters
 * @var array<string, mixed> $narrowing  the filters that are doing something
 */

$activeCount = isset($narrowing) && is_array($narrowing) ? count($narrowing) : 0;

?>
<button type="button" class="btn btn-sm filters-toggle mobile-only" data-filters-toggle
        aria-expanded="false" aria-controls="filters">
    <?php $name = 'search'; $size = 14; require __DIR__ . '/icon.php'; ?>
    Search and filter
    <?php if ($activeCount > 0): ?>
        <span class="badge badge-new"><?= (int) $activeCount ?></span>
    <?php endif; ?>
</button>
