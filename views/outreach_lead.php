<?php

use Prospector\Support\Clock;
use Prospector\Support\View;

/**
 * One lead's cadence, editable step by step.
 *
 * @var array<string, mixed> $lead
 * @var list<array<string, mixed>> $emails
 * @var array<int, array{day: int, purpose: string}> $cadence
 * @var array{ok: bool, reason: string} $deliverable
 * @var bool $unverified
 * @var string $today
 * @var string $csrf
 */

$leadId = (int) $lead['id'];
$returnTo = '/outreach/lead?id=' . $leadId;

$byStep = [];
foreach ($emails as $email) {
    $byStep[(int) $email['step']] = $email;
}

$statusLabels = [
    'draft' => 'Draft — needs approving',
    'approved' => 'Approved',
    'sent' => 'Sent',
    'failed' => 'Failed',
    'skipped' => 'Skipped',
];
?>

<div class="page-head">
    <div>
        <a class="btn btn-sm btn-ghost mb" href="<?= View::e(View::url('outreach')) ?>">
            <?php $name = 'arrow-left'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            All outreach
        </a>
        <h1><?= View::e($lead['company']) ?></h1>
        <div class="sub">
            <?php if ($deliverable['ok']): ?>
                Sending to <?= View::e($lead['email']) ?>
                <?php if (!empty($lead['decision_maker'])): ?>
                    · <?= View::e($lead['decision_maker']) ?>
                    <?php if (!empty($lead['title'])): ?>, <?= View::e($lead['title']) ?><?php endif; ?>
                <?php endif; ?>
                <span class="badge badge-<?= View::e($lead['email_confidence'] ?? 'pattern') ?>">
                    <?= View::e($lead['email_confidence'] ?? 'pattern') ?>
                </span>
            <?php else: ?>
                <?= View::e($deliverable['reason']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-ghost" href="<?= View::e(View::url('leads/' . $leadId)) ?>">Open the lead</a>
        <?php if ($emails !== []): ?>
            <form method="post" action="<?= View::e(View::url('outreach/step')) ?>" class="inline-form"
                  data-confirm="Clear every unsent email for <?= View::e($lead['company']) ?>? Sent ones are kept.">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="action" value="discard">
                <input type="hidden" name="lead_id" value="<?= $leadId ?>">
                <input type="hidden" name="return" value="/outreach">
                <button class="btn btn-sm btn-danger" type="submit">Clear unsent</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($unverified): ?>
    <div class="alert alert-warning mb">
        <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
        <div>
            <strong>That address was inferred, not confirmed.</strong>
            It was built from the company's email format, so it may bounce. Sending one on purpose is
            fine; the mass send holds these back unless you tick the box, because a run of bounces
            damages the sending domain for everyone.
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($lead['why']) || !empty($lead['hook'])): ?>
    <div class="card mb">
        <div class="card-head"><h2>What the copy was written from</h2></div>
        <div class="card-body">
            <?php if (!empty($lead['why'])): ?>
                <label>Why it qualified</label>
                <p class="dim"><?= View::e($lead['why']) ?></p>
            <?php endif; ?>
            <?php if (!empty($lead['hook'])): ?>
                <label class="mt">Researcher's hook</label>
                <p class="dim"><?= View::e($lead['hook']) ?></p>
            <?php endif; ?>
            <?php if (!empty($lead['door'])): ?>
                <p class="hint">Buyer door: <strong><?= View::e($lead['door']) ?></strong></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($emails === []): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty">
                <p class="muted">No emails written for this lead yet.</p>
                <?php if ($deliverable['ok']): ?>
                    <form method="post" action="<?= View::e(View::url('outreach/build')) ?>" data-busy="Writing…">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="return" value="<?= View::e($returnTo) ?>">
                        <input type="hidden" name="ids[]" value="<?= $leadId ?>">
                        <input type="hidden" name="only_missing" value="0">
                        <div class="btn-row">
                            <button class="btn btn-primary" type="submit" name="steps" value="all">
                                Write the <?= count($cadence) ?>-email cadence
                            </button>
                            <button class="btn" type="submit" name="steps" value="first">
                                Opening email only
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="hint"><?= View::e($deliverable['reason']) ?></p>
                    <a class="btn" href="<?= View::e(View::url('leads/' . $leadId)) ?>">Open the lead to dig for one</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($cadence as $step => $spec): ?>
        <?php
        $email = $byStep[$step] ?? null;
        $status = $email !== null ? (string) $email['status'] : null;
        ?>
        <div class="card mb">
            <div class="card-head">
                <h2>Step <?= (int) $step ?> <span class="dim">· day <?= (int) $spec['day'] ?></span></h2>
                <span class="dim small">
                    <?php if ($status === null): ?>
                        not written
                    <?php else: ?>
                        <?= View::e($statusLabels[$status] ?? $status) ?>
                        <?php if ($status === 'approved' && $email['due_on'] !== null): ?>
                            · <?= ((string) $email['due_on']) <= $today
                                ? 'due now'
                                : 'goes out ' . View::e(date('M j', (int) strtotime((string) $email['due_on']))) ?>
                        <?php elseif ($status === 'sent'): ?>
                            · <?= View::e(Clock::display((string) $email['sent_at'])) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
            </div>

            <div class="card-body">
                <p class="hint"><?= View::e($spec['purpose']) ?></p>

                <?php if ($email === null): ?>
                    <p class="muted small">Nothing written for this step.</p>
                <?php elseif ($status === 'sent'): ?>
                    <label>Subject</label>
                    <p><strong><?= View::e($email['subject']) ?></strong></p>
                    <label class="mt">Body</label>
                    <pre class="sent-body"><?= View::e($email['body']) ?></pre>
                <?php else: ?>
                    <?php if ($status === 'failed' && !empty($email['error'])): ?>
                        <div class="alert alert-error">
                            <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                            <div><?= View::e($email['error']) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= View::e(View::url('outreach/step')) ?>">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int) $email['id'] ?>">
                        <input type="hidden" name="return" value="<?= View::e($returnTo) ?>">

                        <div class="field">
                            <label for="subject-<?= (int) $email['id'] ?>">Subject</label>
                            <input type="text" id="subject-<?= (int) $email['id'] ?>" name="subject"
                                   value="<?= View::e($email['subject']) ?>" maxlength="255">
                        </div>

                        <div class="field">
                            <label for="body-<?= (int) $email['id'] ?>">Body</label>
                            <textarea id="body-<?= (int) $email['id'] ?>" name="body" rows="9"
                                      spellcheck="true"><?= View::e($email['body']) ?></textarea>
                            <div class="hint">
                                Editing sets it back to draft — approval is of the exact words, and
                                these just changed.
                            </div>
                        </div>

                        <div class="btn-row">
                            <button class="btn" type="submit" name="action" value="save">Save changes</button>

                            <?php if ($status === 'approved'): ?>
                                <button class="btn" type="submit" name="action" value="unapprove">Un-approve</button>
                            <?php else: ?>
                                <button class="btn" type="submit" name="action" value="approve">Approve</button>
                            <?php endif; ?>

                            <?php if ($deliverable['ok']): ?>
                                <button class="btn btn-primary" type="submit" name="action" value="send"
                                        data-confirm="Send this to <?= View::e($lead['email']) ?> now?"
                                        data-busy="Sending…">
                                    <?php $name = 'mail'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                                    Approve &amp; send now
                                </button>
                            <?php endif; ?>

                            <button class="btn btn-sm btn-ghost" type="submit" name="action" value="skip"
                                    style="margin-left:auto">Skip this step</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?= View::e(View::url('outreach/build')) ?>" class="btn-row"
                  data-confirm="Rewrite the unsent emails for this lead? Your edits to them are lost."
                  data-busy="Writing…">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="return" value="<?= View::e($returnTo) ?>">
                <input type="hidden" name="ids[]" value="<?= $leadId ?>">
                <input type="hidden" name="only_missing" value="0">
                <button class="btn" type="submit" name="steps" value="all">Rewrite the whole cadence</button>
            </form>
        </div>
    </div>
<?php endif; ?>
