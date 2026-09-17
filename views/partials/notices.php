<?php

use Prospector\Notices;
use Prospector\Support\View;

/**
 * The unopened-batch notice.
 *
 * Shown on the dashboard and the leads list. Nothing to dismiss: it is a view
 * of whether the work has been done, so doing the work is what clears it.
 *
 * @var array{batches: list<array<string, mixed>>, hidden: int, hidden_leads: int, unopened: int, stale: bool} $digest
 * @var bool $showOwners  name whose batch it is — worth it for an admin, noise otherwise
 */

if ($digest['batches'] === []) {
    return;
}

$total = (int) $digest['unopened'];
?>

<div class="notice<?= $digest['stale'] ? ' is-stale' : '' ?>">
    <div class="notice-mark">
        <?php $name = $digest['stale'] ? 'alert' : 'zap'; $size = 18; require __DIR__ . '/icon.php'; ?>
    </div>

    <div class="notice-body">
        <strong>
            <?= number_format($total) ?> <?= $total === 1 ? 'lead' : 'leads' ?>
            <?= count($digest['batches']) === 1 ? 'in a batch' : 'across batches' ?>
            nobody has opened
        </strong>

        <ul class="notice-list">
            <?php foreach ($digest['batches'] as $batch): ?>
                <?php
                // The link lands on that one batch, already filtered to the
                // unopened ones — the point of the notice is to get you to the
                // leads, not to a list you then have to narrow yourself.
                $link = View::url('leads', [
                    'run_id' => $batch['run_id'],
                    'unopened' => '1',
                    'owner' => $showOwners ? $batch['user_id'] : '',
                ]);
                ?>
                <li>
                    <a href="<?= View::e($link) ?>">
                        <?= (int) $batch['unopened'] ?> of <?= (int) $batch['total'] ?>
                        from <?= View::e(Notices::whenLabel((string) $batch['run_date'], (int) $batch['age_days'])) ?>
                        <?php if ($showOwners): ?>
                            · <?= View::e($batch['owner_name']) ?>
                        <?php endif; ?>
                    </a>
                    <?php if ($batch['stale']): ?>
                        <span class="badge badge-warning"><?= (int) $batch['age_days'] ?> days</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($digest['hidden'] > 0): ?>
            <div class="hint">
                And <?= (int) $digest['hidden_leads'] ?> more in
                <?= (int) $digest['hidden'] ?> older <?= $digest['hidden'] === 1 ? 'batch' : 'batches' ?>.
            </div>
        <?php endif; ?>
    </div>

    <a class="btn btn-sm btn-primary notice-go" href="<?= View::e(View::url('leads', ['unopened' => '1'])) ?>">
        Open them
    </a>
</div>
