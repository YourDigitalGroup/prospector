<?php

use Prospector\Auth;
use Prospector\Leads;
use Prospector\Runs;
use Prospector\Support\Clock;
use Prospector\Support\View;

/**
 * @var array<string, mixed> $lead
 * @var list<array<string, mixed>> $activities
 * @var list<array<string, mixed>> $owners
 * @var bool $ghlReady
 * @var array<string, mixed>|null $run
 * @var array<string, mixed>|null $opener
 * @var int $cadenceSteps
 * @var array{ok: bool, reason: string} $deliverable
 * @var bool $unverifiedEmail
 * @var array<string, mixed>|null $sendAs
 * @var array{ok: bool, reason: string} $canSend
 * @var array{ok: bool, reason: string} $canEmail
 * @var array{ok: bool, reason: string} $canText
 * @var array<string, string> $signature
 * @var string $signatureHtml
 * @var string $fromAddress
 * @var string $defaultSubject
 * @var list<array<string, mixed>> $thread
 * @var string|null $threadError
 * @var list<array<string, mixed>> $workflows
 * @var list<array<string, mixed>> $enrolments
 * @var string $csrf
 */

$isAdmin = Auth::isAdmin();
$leadUrl = '/leads/' . $lead['id'];
$evidence = [];
if (!empty($lead['evidence'])) {
    $decoded = json_decode((string) $lead['evidence'], true);
    if (is_array($decoded)) {
        $evidence = $decoded;
    }
}
?>

<div class="page-head">
    <div>
        <a class="btn btn-sm btn-ghost mb" href="<?= View::e(View::url('leads')) ?>">
            <?php $name = 'arrow-left'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
            All leads
        </a>
        <h1><?= View::e($lead['company']) ?></h1>
        <div class="sub">
            <?php $value = $lead['fit_score']; require __DIR__ . '/partials/score.php'; ?>
            <span class="badge badge-<?= View::e($lead['status']) ?>">
                <?= View::e(Leads::statusLabel((string) $lead['status'])) ?>
            </span>
            <?php if (!empty($lead['vertical'])): ?>
                <span class="badge badge-neutral"><?= View::e($lead['vertical']) ?></span>
            <?php endif; ?>
            <?php if (!empty($lead['door'])): ?>
                <span class="badge badge-neutral"><?= View::e($lead['door']) ?></span>
            <?php endif; ?>
            <?php if ($lead['archived_at'] !== null): ?>
                <span class="badge badge-disqualified">Archived</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-head-actions">
        <?php if (!empty($lead['website'])): ?>
            <a class="btn" href="<?= View::e($lead['website']) ?>" target="_blank" rel="noopener noreferrer">
                <?php $name = 'external'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                Website
            </a>
        <?php endif; ?>

        <?php if ($lead['ghl_contact_id'] === null && $ghlReady): ?>
            <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/ghl')) ?>" data-busy="Pushing…">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">
                <button type="submit" class="btn btn-primary">
                    <?php $name = 'link'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                    Push to GoHighLevel
                </button>
            </form>
        <?php elseif ($lead['ghl_contact_id'] !== null): ?>
            <span class="pill">
                <?php $name = 'check'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                In GoHighLevel · <?= View::e(Clock::display((string) $lead['ghl_synced_at'], 'M j')) ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2">
    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Why this one</h2></div>
            <div class="card-body">
                <?php if (!empty($lead['why'])): ?>
                    <p><?= View::e($lead['why']) ?></p>
                <?php else: ?>
                    <p class="muted">No evidence was recorded for this lead.</p>
                <?php endif; ?>

                <?php if (!empty($lead['hook'])): ?>
                    <hr class="divider">
                    <label>Opening hook</label>
                    <p><?= View::e($lead['hook']) ?></p>
                <?php endif; ?>

                <?php if ($evidence !== []): ?>
                    <hr class="divider">
                    <label>Sources</label>
                    <ul class="small">
                        <?php foreach ($evidence as $item): ?>
                            <li><?= View::e(is_scalar($item) ? (string) $item : json_encode($item)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Contact</h2>
                <?php if ($digStatus === 'running'): ?>
                    <span class="pill"><span class="spinner"></span> Digging…</span>
                <?php elseif (\Prospector\Enrich::isThin($lead)): ?>
                    <form method="post" action="<?= View::e(View::url('leads/' . (int) $lead['id'] . '/dig')) ?>"
                          class="inline-form" data-dig-form>
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <button class="btn btn-sm" type="submit" data-dig-button>
                            <?php $name = 'search'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                            Dig for contact details
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($digStatus === 'running'): ?>
                <div class="card-body" data-poll-seconds="5">
                    <div class="alert alert-info">
                        <span class="spinner"></span>
                        <div>
                            Searching the company's own site, filings and press coverage for contact
                            details. This usually takes under a minute — you can leave this page and
                            come back, the result will be waiting.
                        </div>
                    </div>
                </div>
            <?php elseif ($digStatus !== null && $digMessage !== null): ?>
                <div class="card-body">
                    <div class="alert <?= $digStatus === 'done' && $dig !== null && ($dig['found'] ?? []) !== [] ? 'alert-success' : ($digStatus === 'done' ? 'alert-info' : 'alert-error') ?>">
                        <?php $name = $digStatus === 'done' ? 'check' : 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                        <div><?= View::e($digMessage) ?></div>
                    </div>

                    <?php if ($dig !== null && $dig['found'] !== []): ?>
                        <form method="post" action="<?= View::e(View::url('leads/' . (int) $lead['id'] . '/dig-apply')) ?>">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">

                            <p class="hint mb">Tick what you want saved. Nothing is written until you do.</p>

                            <?php foreach ($dig['found'] as $label => $finding): ?>
                                <?php $key = str_replace(' ', '_', $label); ?>
                                <div class="finding">
                                    <div class="check">
                                        <input type="checkbox" id="apply_<?= View::e($key) ?>"
                                               name="apply_<?= View::e($key) ?>" value="1" checked>
                                        <label for="apply_<?= View::e($key) ?>">
                                            <span class="finding-label"><?= View::e($label) ?></span>
                                            <span class="finding-value"><?= View::e($finding['value']) ?></span>
                                            <?php if (isset($finding['confidence'])): ?>
                                                <span class="badge badge-<?= View::e($finding['confidence']) ?>">
                                                    <?= View::e($finding['confidence']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                    </div>

                                    <div class="finding-source">
                                        <?php if ($finding['source'] !== ''): ?>
                                            Found at
                                            <a href="<?= View::e($finding['source']) ?>" target="_blank" rel="noopener noreferrer">
                                                <?= View::e($finding['source']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="warn-text">No source URL — treat as unconfirmed.</span>
                                        <?php endif; ?>
                                    </div>

                                    <input type="hidden" name="value_<?= View::e($key) ?>" value="<?= View::e($finding['value']) ?>">
                                    <input type="hidden" name="source_<?= View::e($key) ?>" value="<?= View::e($finding['source']) ?>">
                                    <?php if (isset($finding['confidence'])): ?>
                                        <input type="hidden" name="confidence_<?= View::e($key) ?>" value="<?= View::e($finding['confidence']) ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php if (isset($dig['found']['email']) && ($dig['found']['email']['confidence'] ?? '') === 'pattern'): ?>
                                <div class="alert alert-warning">
                                    <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                                    <div>
                                        That address was <strong>inferred from the company's format, not found</strong>.
                                        It will be saved as <code>pattern</code>, which keeps it out of the GoHighLevel
                                        email field. Confirm it before any bulk send.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="btn-row">
                                <button class="btn btn-primary" type="submit">Save the ticked details</button>
                                <button class="btn btn-ghost" type="submit"
                                        formaction="<?= View::e(View::url('leads/' . (int) $lead['id'] . '/dig-dismiss')) ?>">
                                    Discard
                                </button>
                            </div>
                        </form>

                        <?php if (($dig['notes'] ?? '') !== ''): ?>
                            <p class="hint mt"><?= View::e($dig['notes']) ?></p>
                        <?php endif; ?>

                        <?php if (isset($dig['cost']['dollars'])): ?>
                            <p class="hint mt">
                                Cost about <strong>$<?= number_format((float) $dig['cost']['dollars'], 2) ?></strong>
                                — <?= number_format((int) $dig['cost']['input']) ?> in,
                                <?= number_format((int) $dig['cost']['output']) ?> out,
                                <?= (int) $dig['cost']['searches'] ?> search<?= (int) $dig['cost']['searches'] === 1 ? '' : 'es' ?>,
                                on <?= View::e($dig['cost']['model']) ?>.
                            </p>
                        <?php endif; ?>

                        <?php if ($dig['pages'] !== []): ?>
                            <details class="mt">
                                <summary class="hint">Pages opened (<?= count($dig['pages']) ?>)</summary>
                                <ul class="notes mt-sm">
                                    <?php foreach ($dig['pages'] as $page): ?>
                                        <li><a href="<?= View::e($page) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($page) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($dig !== null && ($dig['notes'] ?? '') !== ''): ?>
                            <p class="hint"><?= View::e($dig['notes']) ?></p>
                        <?php endif; ?>
                        <form method="post" action="<?= View::e(View::url('leads/' . (int) $lead['id'] . '/dig-dismiss')) ?>">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Dismiss</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card-body">
                <dl class="kv">
                    <dt>Decision-maker</dt>
                    <dd>
                        <?= View::e($lead['decision_maker'] ?? '—') ?>
                        <?php if (!empty($lead['title'])): ?>
                            <div class="muted small"><?= View::e($lead['title']) ?></div>
                        <?php endif; ?>
                    </dd>

                    <dt>Email</dt>
                    <dd>
                        <?php if (!empty($lead['email'])): ?>
                            <a href="mailto:<?= View::e($lead['email']) ?>"><?= View::e($lead['email']) ?></a>
                            <button type="button" class="btn btn-sm btn-ghost" data-copy="<?= View::e($lead['email']) ?>"
                                    title="Copy email">
                                <?php $name = 'copy'; $size = 13; require __DIR__ . '/partials/icon.php'; ?>
                            </button>
                            <?php if (!empty($lead['email_confidence'])): ?>
                                <span class="badge badge-<?= View::e($lead['email_confidence']) ?>">
                                    <?= View::e($lead['email_confidence']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($lead['email_confidence'] === 'pattern'): ?>
                                <div class="hint">
                                    Inferred from the company's email format — verify it before any bulk send.
                                    Bounces damage the sending domain.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">Not found — call the main line and ask for marketing.</span>
                        <?php endif; ?>
                    </dd>

                    <dt>Direct phone</dt>
                    <dd>
                        <?php if (!empty($lead['direct_phone'])): ?>
                            <a href="tel:<?= View::e(preg_replace('/[^0-9+]/', '', (string) $lead['direct_phone'])) ?>">
                                <?= View::e($lead['direct_phone']) ?>
                            </a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </dd>

                    <dt>Main phone</dt>
                    <dd>
                        <?php if (!empty($lead['phone'])): ?>
                            <a href="tel:<?= View::e(preg_replace('/[^0-9+]/', '', (string) $lead['phone'])) ?>">
                                <?= View::e($lead['phone']) ?>
                            </a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </dd>

                    <dt>LinkedIn</dt>
                    <dd>
                        <?php if (!empty($lead['linkedin'])): ?>
                            <a href="<?= View::e($lead['linkedin']) ?>" target="_blank" rel="noopener noreferrer">Profile</a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </dd>

                    <dt>Market</dt>
                    <dd><?= View::e($lead['market'] ?? '—') ?><?= !empty($lead['state']) ? ' (' . View::e($lead['state']) . ')' : '' ?></dd>

                    <dt>Website</dt>
                    <dd>
                        <?php if (!empty($lead['website'])): ?>
                            <a href="<?= View::e($lead['website']) ?>" target="_blank" rel="noopener noreferrer">
                                <?= View::e(preg_replace('#^https?://#', '', (string) $lead['website'])) ?>
                            </a>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </dd>

                    <dt>Source</dt>
                    <dd><?= View::e($lead['source'] ?? '—') ?></dd>

                    <dt>Owner</dt>
                    <dd><?= View::e($lead['owner_name']) ?></dd>

                    <dt>Delivered</dt>
                    <dd>
                        <?= View::e(Clock::display((string) $lead['created_at'])) ?>
                        <?php if ($run !== null): ?>
                            · <a href="<?= View::e(View::url('runs/' . $run['id'])) ?>">
                                <?= View::e(Runs::loopLabel((string) $run['loop'])) ?> batch
                            </a>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>Update this lead</h2></div>
            <div class="card-body">
                <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/status')) ?>">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">

                    <div class="field">
                        <label for="status">Disposition</label>
                        <select id="status" name="status">
                            <?php foreach (Leads::STATUSES as $key => $label): ?>
                                <option value="<?= View::e($key) ?>" <?= $lead['status'] === $key ? 'selected' : '' ?>>
                                    <?= View::e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="note">What happened <span class="muted" style="font-weight:500">(optional)</span></label>
                        <textarea id="note" name="note" placeholder="Left a voicemail; gatekeeper says he handles all vendor calls Thursdays."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">Save disposition</button>
                </form>

                <?php if (!empty($lead['owner_note'])): ?>
                    <hr class="divider">
                    <label>Last note</label>
                    <p class="small dim"><?= View::e($lead['owner_note']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-foot">
                <div class="btn-row">
                    <?php if ($lead['archived_at'] === null): ?>
                        <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/archive')) ?>"
                              data-confirm="Archive <?= View::e($lead['company']) ?>? It stays searchable but leaves the working list.">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="return" value="/leads">
                            <button type="submit" class="btn btn-sm">
                                <?php $name = 'archive'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                                Archive
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/restore')) ?>">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">
                            <button type="submit" class="btn btn-sm">
                                <?php $name = 'unarchive'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                                Unarchive
                            </button>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/delete')) ?>"
                          data-confirm="Delete <?= View::e($lead['company']) ?> for good? This cannot be undone, and the notes and history go with it. Archive instead if you only want it out of the working list.">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">
                            <?php $name = 'trash'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                            Delete
                        </button>
                    </form>

                    <?php if (Auth::isAdmin() && $owners !== []): ?>
                        <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/reassign')) ?>" class="row">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">
                            <select name="owner_id" aria-label="Reassign to" style="width:auto">
                                <?php foreach ($owners as $candidate): ?>
                                    <option value="<?= (int) $candidate['id'] ?>"
                                        <?= (int) $candidate['id'] === (int) $lead['user_id'] ? 'selected' : '' ?>>
                                        <?= View::e($candidate['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm">Reassign</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Opening email</h2>
                <?php if ($opener !== null): ?>
                    <span class="dim small">
                        <?php if ((string) $opener['status'] === 'sent'): ?>
                            sent <?= View::e(Clock::display((string) $opener['sent_at'])) ?>
                        <?php elseif ((string) $opener['status'] === 'approved'): ?>
                            approved, queued
                        <?php elseif ((string) $opener['status'] === 'failed'): ?>
                            failed
                        <?php else: ?>
                            draft
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$deliverable['ok']): ?>
                    <p class="muted small"><?= View::e($deliverable['reason']) ?></p>
                    <p class="hint">Nothing to send to yet — dig for an address and this opens up.</p>

                <?php elseif ($opener === null): ?>
                    <p class="dim small">
                        Written from why this one qualified — the door, the evidence, and the hook
                        above. You get to read it before anything goes anywhere.
                    </p>
                    <form method="post" action="<?= View::e(View::url('outreach/build')) ?>"
                          class="btn-row mt" data-busy="Writing…">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="ids[]" value="<?= (int) $lead['id'] ?>">
                        <input type="hidden" name="only_missing" value="0">
                        <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">
                        <button class="btn btn-primary btn-sm" type="submit" name="steps" value="first">
                            <?php $name = 'mail'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                            Draft the opening email
                        </button>
                        <button class="btn btn-sm" type="submit" name="steps" value="all">
                            Draft all <?= (int) $cadenceSteps ?>
                        </button>
                    </form>

                <?php elseif ((string) $opener['status'] === 'sent'): ?>
                    <label>Subject</label>
                    <p><strong><?= View::e($opener['subject']) ?></strong></p>
                    <pre class="sent-body mt"><?= View::e($opener['body']) ?></pre>
                    <a class="btn btn-sm mt" href="<?= View::e(View::url('outreach/lead', ['id' => (int) $lead['id']])) ?>">
                        See the rest of the cadence
                    </a>

                <?php else: ?>
                    <?php if ($unverifiedEmail): ?>
                        <p class="hint">
                            <?= View::e($lead['email']) ?> was inferred from the company's format,
                            not confirmed. It may bounce.
                        </p>
                    <?php endif; ?>

                    <?php if ((string) $opener['status'] === 'failed' && !empty($opener['error'])): ?>
                        <div class="alert alert-error">
                            <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                            <div><?= View::e($opener['error']) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= View::e(View::url('outreach/step')) ?>">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int) $opener['id'] ?>">
                        <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">

                        <div class="field">
                            <label for="opener-subject">Subject</label>
                            <input type="text" id="opener-subject" name="subject"
                                   value="<?= View::e($opener['subject']) ?>" maxlength="255">
                        </div>

                        <div class="field">
                            <label for="opener-body">Body</label>
                            <textarea id="opener-body" name="body" rows="8"><?= View::e($opener['body']) ?></textarea>
                        </div>

                        <div class="btn-row">
                            <button class="btn btn-sm" type="submit" name="action" value="save">Save</button>
                            <button class="btn btn-sm btn-primary" type="submit" name="action" value="send"
                                    data-confirm="Send this to <?= View::e($lead['email']) ?> now?"
                                    data-busy="Sending…">
                                <?php $name = 'mail'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                                Approve &amp; send
                            </button>
                            <a class="btn btn-sm btn-ghost" style="margin-left:auto"
                               href="<?= View::e(View::url('outreach/lead', ['id' => (int) $lead['id']])) ?>">
                                Full cadence
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php /* Writing to them directly, as opposed to the drafted cadence
                 above. Shown for every lead, not only ones already pushed to
                 GoHighLevel — sending creates the contact if there is not one
                 yet. Gated on the owner's private integration, because that is
                 what the mail actually leaves through.

                 The form lives in a <dialog> so the whole message is on screen
                 at once instead of squeezed into the side of a long page. The
                 dialog is real markup, not built by script: with JavaScript off
                 the button is a link to #compose and the form is still there
                 and still posts. */ ?>
        <div class="card" id="send">
            <div class="card-head">
                <h2>Send a message</h2>
                <?php if ($canSend['ok']): ?>
                    <span class="dim small">
                        as <?= View::e($sendAs['name'] ?? 'the owner') ?> · via GoHighLevel
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$canSend['ok']): ?>
                    <p class="muted small"><?= View::e($canSend['reason']) ?></p>
                    <p class="hint">
                        Mail goes out through the seller's own sub-account, so their replies,
                        sending domain and unsubscribes all stay in one place.
                    </p>
                    <a class="btn btn-sm mt" href="<?= View::e(View::url('ghl/connect', $isAdmin ? ['user_id' => (int) $lead['user_id']] : [])) ?>">
                        <?php $name = 'link'; $size = 14; require __DIR__ . '/partials/icon.php'; ?>
                        Connect GoHighLevel
                    </a>

                <?php elseif (!$canEmail['ok'] && !$canText['ok']): ?>
                    <p class="muted small"><?= View::e($canEmail['reason']) ?></p>
                    <p class="hint">No way to reach them yet — dig for contact details and this opens up.</p>

                <?php else: ?>
                    <div class="btn-row">
                        <?php if ($canEmail['ok']): ?>
                            <a class="btn btn-primary" href="#compose" data-open-dialog="compose">
                                <?php $name = 'mail'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                                Send email
                            </a>
                        <?php endif; ?>
                        <?php if ($canText['ok']): ?>
                            <a class="btn" href="#compose-sms" data-open-dialog="compose-sms">
                                <?php $name = 'phone'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                                Send a text
                            </a>
                        <?php endif; ?>
                    </div>
                    <p class="hint mt">
                        <?php if (!$canText['ok']): ?><?= View::e($canText['reason']) ?>
                        <?php elseif (!$canEmail['ok']): ?><?= View::e($canEmail['reason']) ?>
                        <?php else: ?>Goes immediately — there is no draft step here.<?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canSend['ok'] && $canEmail['ok']): ?>
            <dialog class="sheet" id="compose">
                <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/reply')) ?>"
                      data-busy="Sending…">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="return" value="<?= View::e($leadUrl . '#send') ?>">
                    <input type="hidden" name="channel" value="Email">

                    <div class="sheet-head">
                        <h2>Send email</h2>
                        <button type="button" class="icon-btn" data-close-dialog aria-label="Close">&times;</button>
                    </div>

                    <div class="sheet-body">
                        <div class="field">
                            <label>From</label>
                            <?php if ($fromAddress !== ''): ?>
                                <p class="fixed-value"><?= View::e($fromAddress) ?></p>
                            <?php else: ?>
                                <p class="fixed-value muted">Your GoHighLevel sub-account</p>
                            <?php endif; ?>
                            <div class="hint">
                                Set in GoHighLevel, not here — it is the sending domain their
                                replies come back to.
                            </div>
                        </div>

                        <div class="field">
                            <label for="send-to">To</label>
                            <input type="email" id="send-to" name="to" required
                                   value="<?= View::e($lead['email']) ?>">
                            <div class="hint">
                                Changing this corrects the address on the lead and in
                                GoHighLevel, so the next message goes to the right place too.
                            </div>
                        </div>

                        <div class="field">
                            <label for="send-subject">Subject</label>
                            <input type="text" id="send-subject" name="subject" maxlength="255"
                                   placeholder="<?= View::e($defaultSubject) ?>">
                        </div>

                        <?php
                        $id = 'send';
                        $signatureLink = View::url('ghl/connect', $isAdmin ? ['user_id' => (int) $lead['user_id']] : []);
                        require __DIR__ . '/partials/composer.php';
                        ?>

                    </div>

                    <div class="sheet-foot">
                        <button type="submit" class="btn btn-primary">
                            <?php $name = 'mail'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                            Send now
                        </button>
                        <button type="button" class="btn btn-ghost" data-close-dialog>Cancel</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>

        <?php if ($canSend['ok'] && $canText['ok']): ?>
            <dialog class="sheet" id="compose-sms">
                <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/reply')) ?>"
                      data-busy="Sending…">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="return" value="<?= View::e($leadUrl . '#send') ?>">
                    <input type="hidden" name="channel" value="SMS">

                    <div class="sheet-head">
                        <h2>Send a text</h2>
                        <button type="button" class="icon-btn" data-close-dialog aria-label="Close">&times;</button>
                    </div>

                    <div class="sheet-body">
                        <div class="field">
                            <label>To</label>
                            <p class="fixed-value"><?= View::e($lead['direct_phone'] ?: $lead['phone']) ?></p>
                        </div>

                        <div class="field">
                            <label for="sms-body">Message</label>
                            <textarea id="sms-body" name="body" rows="5" required
                                      placeholder="Following up on our call — free Thursday?"></textarea>
                            <div class="hint">No signature on a text; it would eat the message.</div>
                        </div>
                    </div>

                    <div class="sheet-foot">
                        <button type="submit" class="btn btn-primary">Send now</button>
                        <button type="button" class="btn btn-ghost" data-close-dialog>Cancel</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>

        <?php if ($lead['ghl_contact_id'] !== null): ?>
            <div class="card">
                <div class="card-head">
                    <h2>Conversation</h2>
                    <span class="dim small">
                        <?= $thread === [] ? 'nothing yet' : count($thread) . ' message' . (count($thread) === 1 ? '' : 's') ?>
                        · from GoHighLevel
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($threadError !== null): ?>
                        <div class="alert alert-error">
                            <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                            <div><?= View::e($threadError) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($thread === []): ?>
                        <p class="muted small">
                            No messages with this contact yet. Anything sent from here or from a
                            cadence shows up in this thread.
                        </p>
                    <?php else: ?>
                        <ul class="thread">
                            <?php foreach ($thread as $message): ?>
                                <?php
                                $inbound = strtolower((string) ($message['direction'] ?? '')) === 'inbound';
                                $type = (string) ($message['messageType'] ?? $message['type'] ?? '');
                                ?>
                                <li class="thread-msg <?= $inbound ? 'is-in' : 'is-out' ?>">
                                    <div class="thread-meta">
                                        <strong><?= $inbound ? View::e($lead['decision_maker'] ?: $lead['company']) : 'Us' ?></strong>
                                        <?php if ($type !== ''): ?>
                                            <span class="badge badge-neutral"><?= View::e($type) ?></span>
                                        <?php endif; ?>
                                        <span class="muted"><?= View::e(Clock::display((string) ($message['dateAdded'] ?? ''), 'M j, g:ia')) ?></span>
                                    </div>
                                    <?php if (!empty($message['subject'])): ?>
                                        <div class="thread-subject"><?= View::e($message['subject']) ?></div>
                                    <?php endif; ?>
                                    <div class="thread-body"><?= View::e(mb_strimwidth((string) ($message['body'] ?? ''), 0, 900, '…')) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>Automations</h2>
                    <span class="dim small"><?= count($enrolments) ?> active</span>
                </div>
                <div class="card-body">
                    <?php if ($enrolments !== []): ?>
                        <ul class="listy mb">
                            <?php foreach ($enrolments as $enrolment): ?>
                                <li class="row" style="justify-content:space-between;gap:10px">
                                    <span>
                                        <?= View::e($enrolment['workflow_name'] ?: $enrolment['workflow_id']) ?>
                                        <span class="muted small">
                                            · <?= (string) $enrolment['source'] === 'rule' ? 'by a rule' : 'added by hand' ?>
                                        </span>
                                    </span>
                                    <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/unenrol')) ?>"
                                          class="inline-form">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">
                                        <input type="hidden" name="workflow_id" value="<?= View::e($enrolment['workflow_id']) ?>">
                                        <button class="linkish" type="submit">Remove</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($workflows === []): ?>
                        <p class="muted small">
                            No automations found in this GoHighLevel sub-account.
                        </p>
                    <?php else: ?>
                        <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/enrol')) ?>"
                              class="row" data-busy="Adding…">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">
                            <select name="workflow_id" aria-label="Automation" required
                                    onchange="this.form.workflow_name.value = this.options[this.selectedIndex].text">
                                <option value="">Add to an automation…</option>
                                <?php foreach ($workflows as $workflow): ?>
                                    <option value="<?= View::e($workflow['id'] ?? '') ?>">
                                        <?= View::e($workflow['name'] ?? $workflow['id'] ?? 'Unnamed') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="workflow_name" value="">
                            <button class="btn btn-sm" type="submit">Add</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head"><h2>History</h2></div>
            <div class="card-body">
                <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/note')) ?>" class="mb">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">
                    <div class="field">
                        <textarea name="note" placeholder="Add a note…" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm">Add note</button>
                </form>

                <hr class="divider">

                <?php if ($activities === []): ?>
                    <p class="muted small">Nothing logged yet.</p>
                <?php else: ?>
                    <ul class="timeline">
                        <?php foreach ($activities as $activity): ?>
                            <li class="is-<?= View::e($activity['type']) ?>">
                                <div><?= View::e($activity['body']) ?></div>
                                <div class="timeline-meta">
                                    <?= View::e($activity['actor_name'] ?? 'Prospector') ?>
                                    · <?= View::e(Clock::display((string) $activity['created_at'])) ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
