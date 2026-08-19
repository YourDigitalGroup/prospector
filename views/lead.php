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
 * @var string $csrf
 */

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
                <?php if (\Prospector\Enrich::isThin($lead)): ?>
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

            <?php if ($digMessage !== null): ?>
                <div class="card-body">
                    <div class="alert <?= $digOk && $dig !== null && $dig['found'] !== [] ? 'alert-success' : ($digOk ? 'alert-info' : 'alert-error') ?>">
                        <?php $name = $digOk ? 'check' : 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
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
                                <a class="btn btn-ghost" href="<?= View::e(View::url('leads/' . (int) $lead['id'])) ?>">Discard</a>
                            </div>
                        </form>

                        <?php if ($dig['notes'] !== ''): ?>
                            <p class="hint mt"><?= View::e($dig['notes']) ?></p>
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
                    <?php elseif ($dig !== null && $dig['notes'] !== ''): ?>
                        <p class="hint"><?= View::e($dig['notes']) ?></p>
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
                            <button type="submit" class="btn btn-sm">Restore</button>
                        </form>
                    <?php endif; ?>

                    <?php if (Auth::isAdmin() && $owners !== []): ?>
                        <form method="post" action="<?= View::e(View::url(ltrim($leadUrl, '/') . '/reassign')) ?>" class="row">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="return" value="<?= View::e($leadUrl) ?>">
                            <select name="owner_id" aria-label="Reassign to" style="width:auto">
                                <?php foreach ($owners as $owner): ?>
                                    <option value="<?= (int) $owner['id'] ?>"
                                        <?= (int) $owner['id'] === (int) $lead['user_id'] ? 'selected' : '' ?>>
                                        <?= View::e($owner['name']) ?>
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
