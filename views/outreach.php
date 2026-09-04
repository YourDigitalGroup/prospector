<?php

use Prospector\Auth;
use Prospector\Support\Clock;
use Prospector\Support\View;

/**
 * The Outreach screen: build cadences across leads, then approve and send.
 *
 * @var list<array{lead: array<string, mixed>, cadence: array<string, mixed>, sendable: bool,
 *                 blocked_reason: string, unverified: bool, stage: string}> $rows
 * @var array{drafts: int, approved: int, sent: int, due: int, failed: int} $counts
 * @var list<array<string, mixed>> $owners
 * @var int|null $scopeUserId
 * @var string $stage
 * @var string $search
 * @var array<string, string> $statuses
 * @var string $statusFilter
 * @var array{running: bool, total: int, done: int, steps: int}|null $build
 * @var array<int, array{day: int, purpose: string}> $cadence
 * @var string $model
 * @var string $today
 * @var string $csrf
 */

$isAdmin = Auth::isAdmin();

$query = array_filter([
    'q' => $search,
    'status' => $statusFilter,
    'stage' => $stage,
    'owner' => $isAdmin && $scopeUserId !== null ? (string) $scopeUserId : '',
], static fn (string $v): bool => $v !== '');

$returnTo = '/outreach' . ($query !== [] ? '?' . http_build_query($query) : '');

$stages = [
    '' => 'Any stage',
    'none' => 'No cadence yet',
    'drafts' => 'Drafts to review',
    'approved' => 'Approved, waiting',
    'sending' => 'In flight',
    'blocked' => 'Blocked — no address',
];

$withCadence = 0;
$needing = 0;
foreach ($rows as $row) {
    if ($row['cadence']['steps'] > 0) {
        $withCadence++;
    } elseif ($row['sendable']) {
        $needing++;
    }
}
?>

<div class="page-head">
    <div>
        <h1>Outreach</h1>
        <div class="sub">
            A six-email cadence per lead, written from the reason it qualified.
            Nothing sends until you approve the exact words.
        </div>
    </div>
    <div class="page-head-actions">
        <?php if ($counts['due'] > 0): ?>
            <form method="post" action="<?= View::e(View::url('outreach/send')) ?>" class="inline-form"
                  data-confirm="Send every email that is due right now? This puts real mail in front of real people.">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="confirm" value="1">
                <input type="hidden" name="return" value="<?= View::e($returnTo) ?>">
                <button class="btn btn-primary" type="submit" data-busy="Sending…">
                    <?php $name = 'mail'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                    Send <?= (int) $counts['due'] ?> due now
                </button>
            </form>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= View::e(View::url('leads')) ?>">Back to leads</a>
    </div>
</div>

<div class="grid grid-tiles mb">
    <div class="tile">
        <div class="tile-label">To review</div>
        <div class="tile-value"><?= (int) $counts['drafts'] ?></div>
        <div class="tile-note">drafts nobody has approved</div>
    </div>
    <div class="tile">
        <div class="tile-label">Queued</div>
        <div class="tile-value"><?= (int) $counts['approved'] ?></div>
        <div class="tile-note">approved, waiting for their day</div>
    </div>
    <div class="tile<?= $counts['due'] > 0 ? ' accent' : '' ?>">
        <div class="tile-label">Due now</div>
        <div class="tile-value"><?= (int) $counts['due'] ?></div>
        <div class="tile-note">ready to go out</div>
    </div>
    <div class="tile">
        <div class="tile-label">Sent</div>
        <div class="tile-value"><?= (int) $counts['sent'] ?></div>
        <div class="tile-note"><?= $counts['failed'] > 0 ? (int) $counts['failed'] . ' failed' : 'all time' ?></div>
    </div>
</div>

<?php if ($build !== null): ?>
    <div class="card mb">
        <div class="card-body" data-poll-seconds="6">
            <div class="alert alert-info">
                <?php $name = 'clock'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                <div>
                    <strong>Writing copy for <?= (int) $build['total'] ?>
                        <?= $build['total'] === 1 ? 'lead' : 'leads' ?>.</strong>
                    This page refreshes on its own — cadences appear as they are written.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card mb">
    <div class="card-head">
        <h2>Write the copy</h2>
        <span class="dim small">
            <?= View::e($model) ?> · one call per lead writes all <?= count($cadence) ?> emails
        </span>
    </div>
    <div class="card-body">
        <p class="dim">
            <?php if ($needing > 0): ?>
                <strong><?= $needing ?></strong> <?= $needing === 1 ? 'lead has' : 'leads have' ?>
                an address and no cadence yet.
            <?php else: ?>
                Every lead on this screen with an address already has a cadence.
            <?php endif; ?>
            <?php if ($withCadence > 0): ?>
                <?= $withCadence ?> <?= $withCadence === 1 ? 'has' : 'have' ?> one already.
            <?php endif; ?>
        </p>

        <form method="post" action="<?= View::e(View::url('outreach/build')) ?>" class="btn-row"
              data-busy="Starting…">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="return" value="<?= View::e($returnTo) ?>">
            <input type="hidden" name="q" value="<?= View::e($search) ?>">
            <input type="hidden" name="status" value="<?= View::e($statusFilter) ?>">
            <input type="hidden" name="only_missing" value="1">
            <button class="btn btn-primary" type="submit" name="steps" value="all"
                    <?= $needing === 0 ? 'disabled' : '' ?>>
                Build the <?= count($cadence) ?>-email cadence
            </button>
            <button class="btn" type="submit" name="steps" value="first"
                    <?= $needing === 0 ? 'disabled' : '' ?>>
                Opening email only
            </button>
        </form>

        <details class="mt">
            <summary class="linkish">What the six emails are</summary>
            <ul class="notes mt-sm">
                <?php foreach ($cadence as $step => $spec): ?>
                    <li>
                        <strong>Step <?= (int) $step ?></strong>
                        <span class="muted">· day <?= (int) $spec['day'] ?></span>
                        — <?= View::e($spec['purpose']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="hint">
                Day offsets count from the day you approve, not from when the copy was written.
                Steps go out on their day when the scheduler runs, or when you press
                <em>Send due now</em>.
            </p>
        </details>
    </div>
</div>

<div class="card">
    <?php require __DIR__ . '/partials/filters_toggle.php'; ?>
    <form method="get" action="<?= View::e(View::url('outreach')) ?>" class="filters collapsible" id="filters">
        <div class="field grow">
            <label for="f-q">Search</label>
            <input type="search" id="f-q" name="q" value="<?= View::e($search) ?>"
                   placeholder="Company, person, market">
        </div>

        <?php if ($isAdmin): ?>
            <div class="field">
                <label for="f-owner">Owner</label>
                <select id="f-owner" name="owner" data-autosubmit>
                    <option value="">Everyone</option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= (int) $owner['id'] ?>"
                            <?= $scopeUserId === (int) $owner['id'] ? 'selected' : '' ?>>
                            <?= View::e($owner['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="f-stage">Stage</label>
            <select id="f-stage" name="stage" data-autosubmit>
                <?php foreach ($stages as $key => $label): ?>
                    <option value="<?= View::e($key) ?>" <?= $stage === $key ? 'selected' : '' ?>>
                        <?= View::e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="f-status">Lead status</label>
            <select id="f-status" name="status" data-autosubmit>
                <option value="">Any status</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= View::e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>>
                        <?= View::e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn">Apply</button>
    </form>

    <?php if ($rows === []): ?>
        <div class="empty">
            <p class="muted">No leads match.</p>
            <a class="btn" href="<?= View::e(View::url('outreach')) ?>">Clear filters</a>
        </div>
    <?php else: ?>
        <form method="post" action="<?= View::e(View::url('outreach/build')) ?>" data-bulk-form
              data-outreach-form>
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="return" value="<?= View::e($returnTo) ?>">

            <div class="bulkbar hidden" data-bulk-bar>
                <span class="count" data-bulk-count>0 leads selected</span>

                <button class="btn btn-sm" type="submit"
                        formaction="<?= View::e(View::url('outreach/build')) ?>"
                        name="only_missing" value="0" data-busy="Starting…">
                    Rewrite cadence
                </button>

                <button class="btn btn-sm" type="submit"
                        formaction="<?= View::e(View::url('outreach/approve')) ?>">
                    Approve drafts
                </button>

                <button class="btn btn-sm btn-primary" type="submit"
                        formaction="<?= View::e(View::url('outreach/send')) ?>"
                        name="confirm" value="1" data-send-selected data-busy="Sending…">
                    Send what is due
                </button>

                <label class="check" style="margin-left:auto">
                    <input type="checkbox" name="include_unverified" value="1">
                    <span class="small">Include unverified addresses</span>
                </label>
            </div>

            <div class="table-scroll">
                <table class="data">
                    <thead>
                        <tr>
                            <th class="shrink">
                                <input type="checkbox" data-check-all aria-label="Select all leads"
                                       class="check-box">
                            </th>
                            <th>Company</th>
                            <th>Sending to</th>
                            <th class="nowrap">Cadence</th>
                            <th class="nowrap">Next</th>
                            <?php if ($isAdmin): ?><th class="secondary">Owner</th><?php endif; ?>
                            <th class="right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $lead = $row['lead'];
                            $c = $row['cadence'];
                            $leadId = (int) $lead['id'];
                            ?>
                            <tr>
                                <td class="shrink">
                                    <?php if ($row['sendable']): ?>
                                        <input type="checkbox" name="ids[]" value="<?= $leadId ?>" data-check-row
                                               aria-label="Select <?= View::e($lead['company']) ?>"
                                               class="check-box">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="cell-primary">
                                        <a href="<?= View::e(View::url('outreach/lead', ['id' => $leadId])) ?>">
                                            <?= View::e($lead['company']) ?>
                                        </a>
                                    </div>
                                    <div class="cell-sub">
                                        <?= View::e($lead['vertical'] ?? '—') ?>
                                        <?php if (!empty($lead['door'])): ?> · <?= View::e($lead['door']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!$row['sendable']): ?>
                                        <span class="muted small"><?= View::e($row['blocked_reason']) ?></span>
                                        <div class="cell-sub">
                                            <a class="linkish" href="<?= View::e(View::url('leads/' . $leadId)) ?>">Open the lead</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="small"><?= View::e($lead['email']) ?></div>
                                        <div class="cell-sub">
                                            <?php if (!empty($lead['decision_maker'])): ?>
                                                <?= View::e($lead['decision_maker']) ?>
                                            <?php endif; ?>
                                            <span class="badge badge-<?= View::e($lead['email_confidence'] ?? 'pattern') ?>">
                                                <?= View::e($lead['email_confidence'] ?? 'pattern') ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap small">
                                    <?php if ($c['steps'] === 0): ?>
                                        <span class="muted">none yet</span>
                                    <?php else: ?>
                                        <?= (int) $c['sent'] ?> sent
                                        · <?= (int) $c['approved'] ?> queued
                                        <?php if ($c['drafts'] > 0): ?>
                                            · <strong><?= (int) $c['drafts'] ?> to review</strong>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap small dim">
                                    <?php if ($c['next_due'] === null): ?>
                                        —
                                    <?php elseif ($c['next_due'] <= $today): ?>
                                        <span style="color:var(--accent)">due now</span>
                                    <?php else: ?>
                                        <?= View::e(date('M j', (int) strtotime((string) $c['next_due']))) ?>
                                    <?php endif; ?>
                                </td>
                                <?php if ($isAdmin): ?>
                                    <td class="nowrap small dim secondary"><?= View::e($lead['owner_name'] ?? '') ?></td>
                                <?php endif; ?>
                                <td class="right nowrap">
                                    <a class="btn btn-sm" href="<?= View::e(View::url('outreach/lead', ['id' => $leadId])) ?>">
                                        <?= $c['steps'] === 0 ? 'Set up' : 'Review' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</div>
