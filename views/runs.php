<?php

use Prospector\Auth;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\View;
use Prospector\Users;

/**
 * @var list<array<string, mixed>> $runs
 * @var list<array<string, mixed>> $loopUsers
 * @var string $scheduleText
 * @var bool $canDetach
 * @var string $csrf
 */

$hasRunning = false;
foreach ($runs as $run) {
    if ((string) $run['status'] === 'running') {
        $hasRunning = true;
        break;
    }
}
?>

<div class="page-head"<?= $hasRunning ? ' data-poll-seconds="20"' : '' ?>>
    <div>
        <h1>Daily batches</h1>
        <div class="sub">
            Each batch researches, scores and enriches a fresh set of prospects, then emails the brief.
            Automatic delivery: <?= View::e($scheduleText) ?>.
        </div>
    </div>
</div>

<?php if ($hasRunning): ?>
    <div class="alert alert-info">
        <?php $name = 'refresh'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
        <div>
            <strong>A batch is running.</strong>
            Research takes a few minutes — this page refreshes itself every 20 seconds.
        </div>
    </div>
<?php endif; ?>

<?php if ($loopUsers !== []): ?>
    <div class="card mb">
        <div class="card-head">
            <h2>Run one now</h2>
            <span class="muted small">uses today's rotation slot</span>
        </div>
        <div class="card-body tight">
            <div class="table-scroll">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Owner</th>
                        <th>Loop</th>
                        <th>Today's focus</th>
                        <th>Geography</th>
                        <th class="right">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($loopUsers as $owner): ?>
                        <?php
                        $loop = (string) $owner['loop'];
                        $todaysRun = Runs::forUserOnDate((int) $owner['id'], Clock::today());
                        $geography = trim((string) ($owner['geography'] ?? '')) !== ''
                            ? (string) $owner['geography']
                            : Runs::geographyFor($loop);
                        ?>
                        <tr>
                            <td>
                                <div class="row" style="gap:9px">
                                    <div class="avatar"><?= View::e(Users::initials((string) $owner['name'])) ?></div>
                                    <div>
                                        <div class="cell-primary"><?= View::e($owner['name']) ?></div>
                                        <div class="cell-sub"><?= View::e($owner['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="nowrap"><?= View::e(Runs::loopLabel($loop)) ?></td>
                            <td><?= View::e(Runs::verticalFor($loop)) ?></td>
                            <td class="small dim"><?= View::e($geography) ?></td>
                            <td class="right nowrap">
                                <?php if ($todaysRun !== null && (string) $todaysRun['status'] === 'running'): ?>
                                    <span class="badge badge-contacted">Running…</span>
                                <?php else: ?>
                                    <form method="post" action="<?= View::e(View::url('runs/start')) ?>"
                                          data-busy="Starting…"
                                          data-confirm="<?= $todaysRun !== null
                                              ? 'This owner already has a batch today. Run another one? Companies already delivered are skipped automatically.'
                                              : 'Start the batch for ' . View::e($owner['name']) . ' now?' ?>">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $owner['id'] ?>">
                                        <input type="hidden" name="send_email" value="1">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <?php $name = 'zap'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                                            <?= $todaysRun !== null ? 'Run again' : 'Run now' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-foot">
            <?php if ($canDetach): ?>
                A manual batch runs in the background — you can leave this page and come back.
            <?php else: ?>
                This server runs batches in the foreground, so the page will sit and load for several
                minutes. Don't close the tab until it finishes.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Batch history</h2></div>
    <div class="card-body tight">
        <?php if ($runs === []): ?>
            <div class="empty">
                <?php $name = 'zap'; $size = 32; require __DIR__ . '/partials/icon.php'; ?>
                <h3>No batches yet</h3>
                <p>The first automatic batch runs at <?= View::e($scheduleText) ?>.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <?php if (Auth::isAdmin()): ?><th>Owner</th><?php endif; ?>
                        <th>Loop</th>
                        <th>Focus</th>
                        <th>Result</th>
                        <th>Email</th>
                        <th class="right nowrap">Tokens</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($runs as $run): ?>
                        <tr>
                            <td class="nowrap">
                                <div class="cell-primary"><?= View::e(Clock::display((string) $run['started_at'], 'M j, Y')) ?></div>
                                <div class="cell-sub"><?= View::e(Clock::display((string) $run['started_at'], 'g:ia')) ?>
                                    · <?= View::e($run['trigger_source'] === 'cron' ? 'scheduled' : 'manual') ?></div>
                            </td>
                            <?php if (Auth::isAdmin()): ?>
                                <td class="nowrap"><?= View::e($run['owner_name']) ?></td>
                            <?php endif; ?>
                            <td class="nowrap"><?= View::e(Runs::loopLabel((string) $run['loop'])) ?></td>
                            <td class="small dim"><?= View::e($run['vertical'] ?? '—') ?></td>
                            <td>
                                <?php $status = (string) $run['status']; ?>
                                <?php if ($status === 'running'): ?>
                                    <span class="badge badge-contacted">Running</span>
                                <?php elseif ($status === 'failed'): ?>
                                    <span class="badge badge-not_interested">Failed</span>
                                    <div class="cell-sub"><?= View::e(mb_strimwidth((string) $run['error'], 0, 110, '…')) ?></div>
                                <?php elseif ($status === 'partial'): ?>
                                    <span class="badge badge-no_answer">Nothing qualified</span>
                                <?php else: ?>
                                    <span class="badge badge-signed"><?= (int) $run['lead_count'] ?> leads</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap small">
                                <?php if ($run['emailed_at'] !== null): ?>
                                    <span class="dot ok"></span> sent
                                <?php elseif ($status === 'success'): ?>
                                    <span class="dot warn"></span> not sent
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="right nowrap small muted">
                                <?= number_format((int) $run['input_tokens'] + (int) $run['output_tokens']) ?>
                            </td>
                            <td class="right nowrap">
                                <a class="btn btn-sm" href="<?= View::e(View::url('runs/' . $run['id'])) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
