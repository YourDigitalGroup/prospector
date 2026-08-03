<?php

use Prospector\Leads;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\View;

/**
 * @var array<string, mixed> $run
 * @var list<array<string, mixed>> $leads
 */

$status = (string) $run['status'];
?>

<div class="page-head"<?= $status === 'running' ? ' data-poll-seconds="20"' : '' ?>>
    <div>
        <a class="btn btn-sm btn-ghost mb" href="<?= View::e(View::url('runs')) ?>">
            <?php $name = 'arrow-left'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
            All batches
        </a>
        <h1><?= View::e(Runs::loopLabel((string) $run['loop'])) ?></h1>
        <div class="sub">
            <?= View::e($run['owner_name']) ?>
            · <?= View::e(Clock::display((string) $run['started_at'])) ?>
            · <?= View::e($run['vertical'] ?? '') ?>
        </div>
    </div>
    <div class="page-head-actions">
        <?php if ($leads !== []): ?>
            <a class="btn" href="<?= View::e(View::url('leads', ['run_id' => (int) $run['id']])) ?>">
                Work these leads
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'running'): ?>
    <div class="alert alert-info">
        <?php $name = 'refresh'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
        <div>
            <strong>Still researching.</strong>
            Started <?= View::e(Clock::relative((string) $run['started_at'])) ?>. This page refreshes every 20 seconds.
        </div>
    </div>
<?php elseif ($status === 'failed'): ?>
    <div class="alert alert-error">
        <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
        <div>
            <strong>This batch failed.</strong>
            <?= View::e((string) $run['error']) ?>
        </div>
    </div>
<?php elseif ($status === 'partial'): ?>
    <div class="alert alert-warning">
        <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
        <div>
            <strong>Nothing cleared the score floor.</strong>
            The research ran, but no candidate qualified. The brief below explains what was screened.
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-tiles mb">
    <div class="tile accent">
        <div class="tile-label">Leads delivered</div>
        <div class="tile-value"><?= (int) $run['lead_count'] ?></div>
    </div>
    <div class="tile">
        <div class="tile-label">Geography</div>
        <div class="tile-value" style="font-size:15px;font-weight:600;line-height:1.35">
            <?= View::e($run['geography'] ?? '—') ?>
        </div>
    </div>
    <div class="tile">
        <div class="tile-label">Tokens used</div>
        <div class="tile-value"><?= number_format((int) $run['input_tokens'] + (int) $run['output_tokens']) ?></div>
        <div class="tile-note"><?= View::e((string) $run['model']) ?></div>
    </div>
    <div class="tile">
        <div class="tile-label">Email</div>
        <div class="tile-value" style="font-size:16px;font-weight:600">
            <?= $run['emailed_at'] !== null ? 'Sent ' . View::e(Clock::display((string) $run['emailed_at'], 'g:ia')) : 'Not sent' ?>
        </div>
        <div class="tile-note"><?= View::e($run['owner_email']) ?></div>
    </div>
</div>

<?php if ($leads !== []): ?>
    <div class="card mb">
        <div class="card-head"><h2>Delivered leads</h2></div>
        <div class="card-body tight">
            <div class="table-scroll">
                <table class="data">
                    <thead>
                    <tr>
                        <th class="shrink">Fit</th>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Market</th>
                        <th>Opening hook</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td class="shrink"><?php $value = $lead['fit_score']; require __DIR__ . '/partials/score.php'; ?></td>
                            <td>
                                <div class="cell-primary">
                                    <a href="<?= View::e(View::url('leads/' . $lead['id'])) ?>"><?= View::e($lead['company']) ?></a>
                                </div>
                                <div class="cell-sub">
                                    <?= View::e($lead['vertical'] ?? '') ?>
                                    <?php if (!empty($lead['door'])): ?> · <?= View::e($lead['door']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?= View::e($lead['decision_maker'] ?? '—') ?>
                                <div class="cell-sub"><?= View::e($lead['title'] ?? '') ?></div>
                            </td>
                            <td class="nowrap"><?= View::e($lead['market'] ?? '—') ?></td>
                            <td class="cell-clip small dim"><?= View::e($lead['hook'] ?? '') ?></td>
                            <td class="nowrap">
                                <span class="badge badge-<?= View::e($lead['status']) ?>">
                                    <?= View::e(Leads::statusLabel((string) $lead['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($run['brief_md'])): ?>
    <div class="card">
        <div class="card-head">
            <h2>Full brief</h2>
            <span class="muted small">as written by the research pass</span>
        </div>
        <div class="card-body">
            <div class="brief"><?= View::markdown((string) $run['brief_md']) ?></div>
        </div>
    </div>
<?php endif; ?>
