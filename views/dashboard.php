<?php

use Prospector\Auth;
use Prospector\Leads;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\View;
use Prospector\Users;

/**
 * @var array<string, int> $stats
 * @var list<array<string, mixed>> $recent
 * @var list<array<string, mixed>> $priority
 * @var list<array{user: array<string, mixed>, run: array<string, mixed>|null}> $todaysRuns
 * @var list<array{date: string, count: int}> $volume
 * @var list<array<string, mixed>> $runs
 * @var string $scheduleText
 * @var array<string, mixed>|null $currentUser
 * @var string $csrf
 */

$isAdmin = Auth::isAdmin();
$peak = 1;
foreach ($volume as $point) {
    $peak = max($peak, $point['count']);
}
$today = Clock::today();
?>

<div class="page-head">
    <div>
        <h1>Good morning<?= $currentUser !== null ? ', ' . View::e(explode(' ', (string) $currentUser['name'])[0]) : '' ?></h1>
        <div class="sub">
            <?= $isAdmin ? 'Every batch across the team.' : View::e(Runs::loopLabel((string) ($currentUser['loop'] ?? 'none'))) ?>
            · New leads land at <?= View::e($scheduleText) ?>
        </div>
    </div>
    <div class="page-head-actions">
        <a class="btn" href="<?= View::e(View::url('runs')) ?>">
            <?php $name = 'zap'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            Batches
        </a>
        <a class="btn btn-primary" href="<?= View::e(View::url('leads', ['status' => 'new'])) ?>">
            Work today's leads
        </a>
    </div>
</div>

<div class="grid grid-tiles mb">
    <div class="tile accent">
        <div class="tile-label">New today</div>
        <div class="tile-value"><?= (int) $stats['today'] ?></div>
        <div class="tile-note">delivered in today's batch</div>
    </div>
    <div class="tile">
        <div class="tile-label">Untouched</div>
        <div class="tile-value"><?= (int) $stats['new'] ?></div>
        <div class="tile-note">waiting on a first call</div>
    </div>
    <div class="tile">
        <div class="tile-label">Meetings booked</div>
        <div class="tile-value"><?= (int) $stats['meeting'] ?></div>
        <div class="tile-note"><?= (int) $stats['signed'] ?> signed</div>
    </div>
    <div class="tile">
        <div class="tile-label">Strong fit</div>
        <div class="tile-value"><?= (int) $stats['high_fit'] ?></div>
        <div class="tile-note">scored 85 or better</div>
    </div>
    <div class="tile">
        <div class="tile-label">In GoHighLevel</div>
        <div class="tile-value"><?= (int) $stats['in_ghl'] ?></div>
        <div class="tile-note">of <?= (int) $stats['total'] ?> total</div>
    </div>
</div>

<div class="grid grid-2 mb">
    <div class="card">
        <div class="card-head">
            <h2>Today's batches</h2>
            <a class="btn btn-sm btn-ghost" href="<?= View::e(View::url('runs')) ?>">All batches</a>
        </div>
        <div class="card-body tight">
            <?php if ($todaysRuns === []): ?>
                <div class="empty">
                    <p class="muted">No prospecting loop is assigned to your account yet.</p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data">
                        <tbody>
                        <?php foreach ($todaysRuns as $entry): ?>
                            <?php
                            $run = $entry['run'];
                            $owner = $entry['user'];
                            $status = $run === null ? 'pending' : (string) $run['status'];
                            ?>
                            <tr>
                                <td class="shrink">
                                    <div class="avatar"><?= View::e(Users::initials((string) $owner['name'])) ?></div>
                                </td>
                                <td>
                                    <div class="cell-primary"><?= View::e($owner['name']) ?></div>
                                    <div class="cell-sub"><?= View::e(Runs::loopLabel((string) $owner['loop'])) ?></div>
                                </td>
                                <td>
                                    <?php if ($run === null): ?>
                                        <span class="badge badge-neutral">Not run yet</span>
                                        <div class="cell-sub">Scheduled for <?= View::e($scheduleText) ?></div>
                                    <?php elseif ($status === 'running'): ?>
                                        <span class="badge badge-contacted">Running now</span>
                                        <div class="cell-sub">Started <?= View::e(Clock::relative((string) $run['started_at'])) ?></div>
                                    <?php elseif ($status === 'failed'): ?>
                                        <span class="badge badge-not_interested">Failed</span>
                                        <div class="cell-sub"><?= View::e(mb_strimwidth((string) $run['error'], 0, 90, '…')) ?></div>
                                    <?php else: ?>
                                        <span class="badge badge-signed"><?= (int) $run['lead_count'] ?> leads</span>
                                        <div class="cell-sub">
                                            <?= View::e((string) $run['vertical']) ?>
                                            <?php if ($run['emailed_at'] !== null): ?> · emailed<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="shrink nowrap">
                                    <?php if ($run !== null): ?>
                                        <a class="btn btn-sm" href="<?= View::e(View::url('runs/' . $run['id'])) ?>">Open</a>
                                    <?php else: ?>
                                        <form method="post" action="<?= View::e(View::url('runs/start')) ?>"
                                              data-busy="Starting…"
                                              data-confirm="Run the <?= View::e(Runs::loopLabel((string) $owner['loop'])) ?> batch for <?= View::e($owner['name']) ?> now?">
                                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $owner['id'] ?>">
                                            <input type="hidden" name="send_email" value="1">
                                            <button type="submit" class="btn btn-sm">Run now</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Lead volume</h2>
            <span class="muted small">last 14 days</span>
        </div>
        <div class="card-body">
            <div class="sparkline" role="img"
                 aria-label="Leads delivered per day over the last 14 days">
                <?php foreach ($volume as $point): ?>
                    <span class="<?= $point['date'] === $today ? 'is-today' : '' ?>"
                          style="height: <?= max(4, (int) round($point['count'] / $peak * 100)) ?>%"
                          title="<?= View::e($point['date']) ?>: <?= (int) $point['count'] ?>"></span>
                <?php endforeach; ?>
            </div>
            <hr class="divider">
            <dl class="kv">
                <dt>Worked</dt>
                <dd><?= (int) $stats['worked'] ?> of <?= (int) $stats['total'] ?> leads have a disposition</dd>
                <dt>Meeting rate</dt>
                <dd><?= (int) $stats['conversion'] ?>% booked a meeting or signed</dd>
                <dt>Passed on</dt>
                <dd><?= (int) $stats['not_interested'] ?> not interested · <?= (int) $stats['disqualified'] ?> disqualified</dd>
            </dl>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h2>Call these first</h2>
            <span class="muted small">highest fit, not yet contacted</span>
        </div>
        <div class="card-body tight">
            <?php if ($priority === []): ?>
                <div class="empty">
                    <?php $name = 'check'; $size = 30; require __DIR__ . '/partials/icon.php'; ?>
                    <h3>Nothing waiting</h3>
                    <p>Every delivered lead has been worked. The next batch arrives at <?= View::e($scheduleText) ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data">
                        <tbody>
                        <?php foreach ($priority as $lead): ?>
                            <tr>
                                <td class="shrink"><?php $value = $lead['fit_score']; require __DIR__ . '/partials/score.php'; ?></td>
                                <td>
                                    <div class="cell-primary">
                                        <a href="<?= View::e(View::url('leads/' . $lead['id'])) ?>"><?= View::e($lead['company']) ?></a>
                                    </div>
                                    <div class="cell-sub">
                                        <?= View::e($lead['decision_maker'] ?? 'Contact not named') ?>
                                        <?php if (!empty($lead['market'])): ?> · <?= View::e($lead['market']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="shrink nowrap">
                                    <?php if (!empty($lead['door'])): ?>
                                        <span class="badge badge-neutral"><?= View::e($lead['door']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($priority !== []): ?>
            <div class="card-foot">
                <a href="<?= View::e(View::url('leads', ['status' => 'new', 'sort' => 'score'])) ?>">See every untouched lead</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Latest activity</h2>
        </div>
        <div class="card-body tight">
            <?php if ($recent === []): ?>
                <div class="empty">
                    <?php $name = 'inbox'; $size = 30; require __DIR__ . '/partials/icon.php'; ?>
                    <h3>No leads yet</h3>
                    <p>
                        The first batch runs at <?= View::e($scheduleText) ?>. You can also start one by hand
                        from the Batches screen.
                    </p>
                    <a class="btn btn-primary" href="<?= View::e(View::url('runs')) ?>">Go to batches</a>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data">
                        <tbody>
                        <?php foreach ($recent as $lead): ?>
                            <tr>
                                <td>
                                    <div class="cell-primary">
                                        <a href="<?= View::e(View::url('leads/' . $lead['id'])) ?>"><?= View::e($lead['company']) ?></a>
                                    </div>
                                    <div class="cell-sub">
                                        <?= View::e($lead['vertical'] ?? '—') ?>
                                        <?php if (Auth::isAdmin()): ?> · <?= View::e($lead['owner_name']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="shrink nowrap">
                                    <span class="badge badge-<?= View::e($lead['status']) ?>">
                                        <?= View::e(Leads::statusLabel((string) $lead['status'])) ?>
                                    </span>
                                </td>
                                <td class="shrink nowrap muted small"><?= View::e(Clock::relative((string) $lead['updated_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
