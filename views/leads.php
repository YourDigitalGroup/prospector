<?php

use Prospector\Auth;
use Prospector\Leads;
use Prospector\Support\Clock;
use Prospector\Support\View;

/**
 * @var list<array<string, mixed>> $leads
 * @var array<string, mixed> $filters
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array{verticals: list<string>, doors: list<string>} $facets
 * @var list<array<string, mixed>> $owners
 * @var bool $ghlReady
 * @var string $csrf
 */

$isAdmin = Auth::isAdmin();

// Everything that should survive pagination and the CSV export link.
$query = array_filter([
    'q' => $filters['search'] ?? '',
    'status' => $filters['status'] ?? '',
    'vertical' => $filters['vertical'] ?? '',
    'door' => $filters['door'] ?? '',
    'min_score' => $filters['min_score'] ?? '',
    'in_ghl' => $filters['in_ghl'] ?? '',
    'sort' => $filters['sort'] ?? '',
    'owner' => $isAdmin ? ($filters['user_id'] ?? '') : '',
    'archived' => !empty($filters['include_archived']) ? '1' : '',
    'run_id' => !empty($filters['run_id']) ? $filters['run_id'] : '',
], static fn ($v): bool => $v !== '' && $v !== null);

// "Clear filters" should only appear when something is actually narrowing the
// list — the default sort order does not count.
$narrowing = $query;
if (($narrowing['sort'] ?? '') === 'newest') {
    unset($narrowing['sort']);
}
$hasFilters = $narrowing !== [];

$returnTo = '/leads' . ($query !== [] ? '?' . http_build_query($query) : '');
?>

<div class="page-head">
    <div>
        <h1>Leads</h1>
        <div class="sub">
            <?= number_format($total) ?> <?= $total === 1 ? 'lead' : 'leads' ?> match
            <?= !empty($filters['include_archived']) ? ' (including archived)' : '' ?>
        </div>
    </div>
    <div class="page-head-actions">
        <a class="btn" href="<?= View::e(View::url('leads/import')) ?>">
            <?php $name = 'plus'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            Upload leads
        </a>
        <a class="btn" href="<?= View::e(View::url('leads/export', $query)) ?>">
            <?php $name = 'download'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            Export CSV
        </a>
        <?php if ($hasFilters): ?>
            <a class="btn btn-ghost" href="<?= View::e(View::url('leads')) ?>">Clear filters</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <form method="get" action="<?= View::e(View::url('leads')) ?>" class="filters">
        <div class="field grow">
            <label for="f-q">Search</label>
            <input type="search" id="f-q" name="q" value="<?= View::e($filters['search'] ?? '') ?>"
                   placeholder="Company, person, market, evidence">
        </div>

        <?php if ($isAdmin): ?>
            <div class="field">
                <label for="f-owner">Owner</label>
                <select id="f-owner" name="owner" data-autosubmit>
                    <option value="">Everyone</option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= (int) $owner['id'] ?>"
                            <?= (int) ($filters['user_id'] ?? 0) === (int) $owner['id'] ? 'selected' : '' ?>>
                            <?= View::e($owner['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="f-status">Status</label>
            <select id="f-status" name="status" data-autosubmit>
                <option value="">Any status</option>
                <?php foreach (Leads::STATUSES as $key => $label): ?>
                    <option value="<?= View::e($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= View::e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($facets['verticals'] !== []): ?>
            <div class="field">
                <label for="f-vertical">Vertical</label>
                <select id="f-vertical" name="vertical" data-autosubmit>
                    <option value="">All verticals</option>
                    <?php foreach ($facets['verticals'] as $vertical): ?>
                        <option value="<?= View::e($vertical) ?>" <?= ($filters['vertical'] ?? '') === $vertical ? 'selected' : '' ?>>
                            <?= View::e($vertical) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <?php if ($facets['doors'] !== []): ?>
            <div class="field">
                <label for="f-door">Buyer door</label>
                <select id="f-door" name="door" data-autosubmit>
                    <option value="">All doors</option>
                    <?php foreach ($facets['doors'] as $door): ?>
                        <option value="<?= View::e($door) ?>" <?= ($filters['door'] ?? '') === $door ? 'selected' : '' ?>>
                            <?= View::e($door) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="f-score">Min fit</label>
            <select id="f-score" name="min_score" data-autosubmit>
                <option value="">Any score</option>
                <?php foreach ([70, 75, 80, 85, 90] as $score): ?>
                    <option value="<?= $score ?>" <?= (string) ($filters['min_score'] ?? '') === (string) $score ? 'selected' : '' ?>>
                        <?= $score ?>+
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="f-ghl">GoHighLevel</label>
            <select id="f-ghl" name="in_ghl" data-autosubmit>
                <option value="">Any</option>
                <option value="yes" <?= ($filters['in_ghl'] ?? '') === 'yes' ? 'selected' : '' ?>>Pushed</option>
                <option value="no" <?= ($filters['in_ghl'] ?? '') === 'no' ? 'selected' : '' ?>>Not pushed</option>
            </select>
        </div>

        <div class="field">
            <label for="f-sort">Sort</label>
            <select id="f-sort" name="sort" data-autosubmit>
                <?php
                $sorts = [
                    'newest' => 'Newest first',
                    'score' => 'Highest fit',
                    'status' => 'By status',
                    'company' => 'Company A–Z',
                    'oldest' => 'Oldest first',
                ];
                foreach ($sorts as $key => $label): ?>
                    <option value="<?= View::e($key) ?>" <?= ($filters['sort'] ?? 'newest') === $key ? 'selected' : '' ?>>
                        <?= View::e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="f-archived">Archived</label>
            <select id="f-archived" name="archived" data-autosubmit>
                <option value="">Hidden</option>
                <option value="1" <?= !empty($filters['include_archived']) ? 'selected' : '' ?>>Included</option>
            </select>
        </div>

        <button type="submit" class="btn">Apply</button>
    </form>

    <?php if ($leads === []): ?>
        <div class="empty">
            <?php $name = 'inbox'; $size = 32; require __DIR__ . '/partials/icon.php'; ?>
            <h3>No leads match</h3>
            <p>
                <?php if ($hasFilters): ?>
                    Loosen a filter, or clear them all to see everything delivered so far.
                <?php else: ?>
                    Nothing has been delivered yet. Batches run automatically each weekday morning, or you
                    can start one now from the Batches screen.
                <?php endif; ?>
            </p>
            <?php if ($hasFilters): ?>
                <a class="btn" href="<?= View::e(View::url('leads')) ?>">Clear filters</a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?= View::e(View::url('runs')) ?>">Go to batches</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <form method="post" action="<?= View::e(View::url('leads/bulk')) ?>" data-bulk-form>
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="return" value="<?= View::e($returnTo) ?>">

            <div class="bulkbar hidden" data-bulk-bar>
                <span class="count" data-bulk-count>0 leads selected</span>
                <select name="bulk_action" aria-label="Bulk action">
                    <option value="">Choose an action…</option>
                    <?php foreach (Leads::STATUSES as $key => $label): ?>
                        <option value="<?= View::e($key) ?>">Mark <?= View::e(strtolower($label)) ?></option>
                    <?php endforeach; ?>
                    <?php if ($ghlReady): ?>
                        <option value="ghl">Push to GoHighLevel</option>
                    <?php endif; ?>
                    <option value="archive">Archive</option>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Apply to selected</button>
            </div>

            <div class="table-scroll">
                <table class="data">
                    <thead>
                    <tr>
                        <th class="shrink">
                            <input type="checkbox" data-check-all aria-label="Select all leads on this page"
                                   style="width:15px;height:15px;accent-color:var(--accent)">
                        </th>
                        <th class="shrink">Fit</th>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Market</th>
                        <th>Why them</th>
                        <th>Status</th>
                        <?php if ($isAdmin): ?><th>Owner</th><?php endif; ?>
                        <th class="right nowrap">Delivered</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <tr<?= $lead['archived_at'] !== null ? ' style="opacity:.6"' : '' ?>>
                            <td class="shrink">
                                <input type="checkbox" name="ids[]" value="<?= (int) $lead['id'] ?>" data-check-row
                                       aria-label="Select <?= View::e($lead['company']) ?>"
                                       style="width:15px;height:15px;accent-color:var(--accent)">
                            </td>
                            <td class="shrink"><?php $value = $lead['fit_score']; require __DIR__ . '/partials/score.php'; ?></td>
                            <td>
                                <div class="cell-primary">
                                    <a href="<?= View::e(View::url('leads/' . $lead['id'])) ?>"><?= View::e($lead['company']) ?></a>
                                    <?php if ($lead['ghl_contact_id'] !== null): ?>
                                        <span class="badge badge-high" title="In GoHighLevel">GHL</span>
                                    <?php endif; ?>
                                </div>
                                <div class="cell-sub">
                                    <?= View::e($lead['vertical'] ?? '—') ?>
                                    <?php if (!empty($lead['door'])): ?> · <?= View::e($lead['door']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($lead['decision_maker'])): ?>
                                    <div><?= View::e($lead['decision_maker']) ?></div>
                                    <div class="cell-sub"><?= View::e($lead['title'] ?? '') ?></div>
                                <?php else: ?>
                                    <span class="muted">Not named</span>
                                <?php endif; ?>
                                <?php if (!empty($lead['email'])): ?>
                                    <div class="cell-sub">
                                        <a href="mailto:<?= View::e($lead['email']) ?>"><?= View::e($lead['email']) ?></a>
                                        <?php if (!empty($lead['email_confidence'])): ?>
                                            <span class="badge badge-<?= View::e($lead['email_confidence']) ?>">
                                                <?= View::e($lead['email_confidence']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (\Prospector\Enrich::isThin($lead)): ?>
                                    <form method="post" class="inline-form"
                                          action="<?= View::e(View::url('leads/' . $lead['id'] . '/dig')) ?>">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <button class="linkish" type="submit"
                                                title="Search business sources for a work email or phone">
                                            <?php $name = 'search'; $size = 12; require __DIR__ . '/partials/icon.php'; ?>
                                            Dig for details
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?= View::e($lead['market'] ?? '—') ?></td>
                            <td class="cell-clip small dim"><?= View::e(mb_strimwidth((string) ($lead['why'] ?? ''), 0, 150, '…')) ?></td>
                            <td class="nowrap">
                                <span class="badge badge-<?= View::e($lead['status']) ?>">
                                    <?= View::e(Leads::statusLabel((string) $lead['status'])) ?>
                                </span>
                            </td>
                            <?php if ($isAdmin): ?>
                                <td class="nowrap small dim"><?= View::e($lead['owner_name']) ?></td>
                            <?php endif; ?>
                            <td class="right nowrap small muted"><?= View::e(Clock::display((string) $lead['created_at'], 'M j')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php if ($pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= View::e(View::url('leads', $query + ['page' => $page - 1])) ?>">Previous</a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($pages, $start + 4);
                for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="is-current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= View::e(View::url('leads', $query + ['page' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $pages): ?>
                    <a href="<?= View::e(View::url('leads', $query + ['page' => $page + 1])) ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
