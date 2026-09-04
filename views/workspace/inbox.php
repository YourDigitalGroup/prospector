<?php

use Prospector\Support\Clock;
use Prospector\Support\View;

/**
 * @var list<array<string, mixed>> $conversations
 * @var int $total
 * @var string $channel
 * @var bool $unreadOnly
 * @var array<string, array<string, mixed>> $leadsByContact
 * @var string|null $error
 * @var array<string, mixed> $workspaceUser
 * @var bool $viewingOther
 */

require __DIR__ . '/_head.php';

$when = static function (mixed $value): string {
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return '';
    }
    // GHL sends both ISO strings and epoch milliseconds here.
    $stamp = is_numeric($value) ? (int) ((float) $value / 1000) : strtotime($value);

    return $stamp === false || $stamp <= 0 ? '' : Clock::display(date('c', $stamp), 'M j, g:i a');
};
?>

<?php if ($error !== null): ?>
    <div class="alert alert-error">
        <?php $name = 'alert'; $size = 17; require __DIR__ . '/../partials/icon.php'; ?>
        <div><?= View::e($error) ?></div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Conversations</h2>
        <span class="dim small">
            <?= count($conversations) ?> shown<?= count($conversations) !== $total ? ' of ' . $total : '' ?>
        </span>
    </div>

    <?php require __DIR__ . '/../partials/filters_toggle.php'; ?>
    <form method="get" action="<?= View::e(View::url('ghl/inbox')) ?>" class="filters collapsible" id="filters">
        <?php if ($viewingOther): ?>
            <input type="hidden" name="user_id" value="<?= (int) $workspaceUser['id'] ?>">
        <?php endif; ?>
        <div class="field">
            <label for="f-channel">Channel</label>
            <select id="f-channel" name="channel" data-autosubmit>
                <option value="">Everything</option>
                <option value="email" <?= $channel === 'email' ? 'selected' : '' ?>>Email</option>
                <option value="sms" <?= $channel === 'sms' ? 'selected' : '' ?>>Text</option>
            </select>
        </div>
        <div class="field">
            <label for="f-unread">Unread</label>
            <select id="f-unread" name="unread" data-autosubmit>
                <option value="">All</option>
                <option value="1" <?= $unreadOnly ? 'selected' : '' ?>>Unread only</option>
            </select>
        </div>
        <button type="submit" class="btn">Apply</button>
    </form>

    <?php if ($conversations === []): ?>
        <div class="card-body">
            <div class="empty">
                <?php $name = 'inbox'; $size = 34; require __DIR__ . '/../partials/icon.php'; ?>
                <h3>Nothing here yet</h3>
                <p>
                    Email and SMS threads from this GoHighLevel sub-account appear here. Open a
                    contact to start one, or clear the filters above if you have narrowed them out.
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data">
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Lead</th>
                        <th>Last message</th>
                        <th class="nowrap">When</th>
                        <th class="shrink"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conversations as $conversation): ?>
                        <?php
                        $contactId = (string) ($conversation['contactId'] ?? '');
                        $unread = (int) ($conversation['unreadCount'] ?? 0);
                        ?>
                        <tr>
                            <td class="cell-primary">
                                <?= View::e($conversation['fullName'] ?? $conversation['contactName'] ?? 'Unknown contact') ?>
                                <?php if ($unread > 0): ?>
                                    <span class="badge badge-new"><?= $unread ?> unread</span>
                                <?php endif; ?>
                                <?php if (($conversation['email'] ?? '') !== ''): ?>
                                    <div class="cell-sub"><?= View::e($conversation['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap small">
                                <?php $lead = $leadsByContact[$contactId] ?? null; ?>
                                <?php if ($lead !== null): ?>
                                    <a href="<?= View::e(View::url('leads/' . (int) $lead['id'])) ?>">
                                        <?= View::e($lead['company']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="muted">not a lead here</span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-clip"><?= View::e($conversation['lastMessageBody'] ?? '') ?></td>
                            <td class="nowrap dim"><?= View::e($when($conversation['lastMessageDate'] ?? '')) ?></td>
                            <td class="shrink nowrap">
                                <?php if ($contactId !== ''): ?>
                                    <a class="btn btn-sm" href="<?= View::e(View::url('ghl/contact', array_merge(
                                        $viewingOther ? ['user_id' => (int) $workspaceUser['id']] : [],
                                        ['id' => $contactId]
                                    ))) ?>">Open</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
