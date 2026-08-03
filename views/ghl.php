<?php

use Prospector\Auth;
use Prospector\Support\View;

/**
 * @var list<array<string, mixed>> $contacts
 * @var list<array<string, mixed>> $opportunities
 * @var string|null $error
 * @var array{ok: bool, message: string}|null $connection
 * @var array<string, mixed>|null $viewUser
 * @var list<array<string, mixed>> $owners
 * @var int $pending
 */

$money = static function (mixed $value): string {
    if (!is_numeric($value)) {
        return '—';
    }

    return '$' . number_format((float) $value);
};
?>

<div class="page-head">
    <div>
        <h1>GoHighLevel</h1>
        <div class="sub">
            <?php if ($connection !== null && $connection['ok']): ?>
                <span class="dot ok"></span> <?= View::e($connection['message']) ?>
            <?php else: ?>
                <span class="dot bad"></span> Not connected
            <?php endif; ?>
        </div>
    </div>
    <div class="page-head-actions">
        <?php if (Auth::isAdmin() && $owners !== []): ?>
            <form method="get" action="<?= View::e(View::url('ghl')) ?>">
                <select name="user_id" data-autosubmit aria-label="Which account to view">
                    <option value="">Account default</option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= (int) $owner['id'] ?>"
                            <?= (int) ($viewUser['id'] ?? 0) === (int) $owner['id'] ? 'selected' : '' ?>>
                            <?= View::e($owner['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <?php if ($pending > 0): ?>
            <a class="btn btn-primary" href="<?= View::e(View::url('leads', ['in_ghl' => 'no'])) ?>">
                Push <?= (int) $pending ?> waiting <?= $pending === 1 ? 'lead' : 'leads' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-warning">
        <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
        <div><?= View::e($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($connection === null): ?>
    <div class="card">
        <div class="card-body">
            <h2 class="mb">Connect a GoHighLevel sub-account</h2>
            <p class="dim">
                Prospector pushes leads in as contacts — with the fit score, buyer door, evidence and
                opening hook attached as a note — and reads your contacts and opportunities back so you
                can see both sides in one place.
            </p>
            <hr class="divider">
            <h3 class="mb">What to get from GoHighLevel</h3>
            <ol class="dim">
                <li>In the sub-account, open <strong>Settings → Private Integrations</strong>.</li>
                <li>Create an integration and give it these scopes:
                    <code>contacts.readonly</code>, <code>contacts.write</code>,
                    <code>opportunities.readonly</code>, <code>opportunities.write</code>,
                    <code>locations.readonly</code>.</li>
                <li>Copy the token, and copy the Location ID from <strong>Settings → Business Profile</strong>.</li>
                <?php if (Auth::isAdmin()): ?>
                    <li>Paste both into <a href="<?= View::e(View::url('settings')) ?>">Settings → GoHighLevel</a>.</li>
                <?php else: ?>
                    <li>Send both to Scott — he can add them under Settings.</li>
                <?php endif; ?>
            </ol>
            <p class="hint">
                Billy and Darren can each point at their own sub-account: set a per-user token on the
                Users screen and it takes priority over the account-wide one.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-2">
        <div class="card">
            <div class="card-head">
                <h2>Contacts in GoHighLevel</h2>
                <form method="get" action="<?= View::e(View::url('ghl')) ?>" class="row" style="gap:6px">
                    <?php if (!empty($viewUser['id']) && Auth::isAdmin()): ?>
                        <input type="hidden" name="user_id" value="<?= (int) $viewUser['id'] ?>">
                    <?php endif; ?>
                    <input type="search" name="q" value="<?= View::e($_GET['q'] ?? '') ?>" placeholder="Search"
                           style="width:150px" aria-label="Search GoHighLevel contacts">
                    <button type="submit" class="btn btn-sm">Find</button>
                </form>
            </div>
            <div class="card-body tight">
                <?php if ($contacts === []): ?>
                    <div class="empty">
                        <?php $name = 'users'; $size = 30; require __DIR__ . '/partials/icon.php'; ?>
                        <h3>No contacts returned</h3>
                        <p>Either this sub-account is empty or the search matched nothing.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data">
                            <thead>
                            <tr>
                                <th>Contact</th>
                                <th>Company</th>
                                <th>Reach</th>
                                <th>Tags</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($contacts as $contact): ?>
                                <?php
                                $displayName = trim(
                                    (string) ($contact['contactName']
                                        ?? trim((string) ($contact['firstName'] ?? '') . ' ' . (string) ($contact['lastName'] ?? '')))
                                );
                                $tags = $contact['tags'] ?? [];
                                ?>
                                <tr>
                                    <td>
                                        <div class="cell-primary"><?= View::e($displayName !== '' ? $displayName : 'Unnamed') ?></div>
                                        <?php if (!empty($contact['dateAdded'])): ?>
                                            <div class="cell-sub"><?= View::e(substr((string) $contact['dateAdded'], 0, 10)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= View::e($contact['companyName'] ?? '—') ?></td>
                                    <td class="small">
                                        <?php if (!empty($contact['email'])): ?>
                                            <div><a href="mailto:<?= View::e($contact['email']) ?>"><?= View::e($contact['email']) ?></a></div>
                                        <?php endif; ?>
                                        <?php if (!empty($contact['phone'])): ?>
                                            <div class="muted"><?= View::e($contact['phone']) ?></div>
                                        <?php endif; ?>
                                        <?php if (empty($contact['email']) && empty($contact['phone'])): ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php if (is_array($tags) && $tags !== []): ?>
                                            <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                                                <span class="badge badge-neutral"><?= View::e((string) $tag) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="muted">—</span>
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
            <div class="card-head"><h2>Open opportunities</h2></div>
            <div class="card-body tight">
                <?php if ($opportunities === []): ?>
                    <div class="empty">
                        <?php $name = 'target'; $size = 30; require __DIR__ . '/partials/icon.php'; ?>
                        <h3>No opportunities</h3>
                        <p>
                            Set a pipeline and stage under Settings and Prospector will open an
                            opportunity each time a lead is pushed.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="data">
                            <thead>
                            <tr>
                                <th>Opportunity</th>
                                <th>Stage</th>
                                <th class="right">Value</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($opportunities as $opportunity): ?>
                                <tr>
                                    <td>
                                        <div class="cell-primary"><?= View::e($opportunity['name'] ?? 'Untitled') ?></div>
                                        <?php if (!empty($opportunity['contact']['name'])): ?>
                                            <div class="cell-sub"><?= View::e($opportunity['contact']['name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small dim"><?= View::e($opportunity['pipelineStageId'] ?? '—') ?></td>
                                    <td class="right nowrap"><?= View::e($money($opportunity['monetaryValue'] ?? null)) ?></td>
                                    <td class="nowrap">
                                        <span class="badge badge-neutral"><?= View::e($opportunity['status'] ?? '—') ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
