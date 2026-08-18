<?php

use Prospector\Auth;
use Prospector\Support\Clock;
use Prospector\Support\View;

/**
 * @var array<string, mixed> $contact
 * @var string $contactId
 * @var array{ok: bool, notes: list<array<string, mixed>>, error: string} $notes
 * @var array{ok: bool, tasks: list<array<string, mixed>>, error: string} $tasks
 * @var array{ok: bool, conversations: list<array<string, mixed>>, error: string} $conversations
 * @var array{ok: bool, messages: list<array<string, mixed>>, error: string} $messages
 * @var string $conversationId
 * @var array{ok: bool, workflows: list<array<string, mixed>>, error: string} $workflows
 * @var array<string, mixed> $workspaceUser
 * @var bool $viewingOther
 */

require __DIR__ . '/_head.php';

$csrf = Auth::csrfToken();
$post = static function (string $path) use ($wsQuery): string {
    return View::url($path, $wsQuery);
};

$displayName = trim((string) ($contact['contactName']
    ?? trim((string) ($contact['firstName'] ?? '') . ' ' . (string) ($contact['lastName'] ?? ''))));
$email = trim((string) ($contact['email'] ?? ''));
$phone = trim((string) ($contact['phone'] ?? ''));
$tags = is_array($contact['tags'] ?? null) ? $contact['tags'] : [];

/** A panel that failed on its own shows why, without taking the page down. */
$panelError = static function (array $result): string {
    return $result['ok'] ? '' : (string) ($result['error'] ?? 'Unavailable.');
};

$when = static function (mixed $value): string {
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return '';
    }
    $stamp = strtotime($value);

    return $stamp === false ? $value : Clock::display(date('c', $stamp), 'M j, g:i a');
};
?>

<div class="grid grid-2 contact-grid">
    <div class="stack">
        <div class="card">
            <div class="card-head">
                <h2><?= View::e($displayName !== '' ? $displayName : 'Unnamed contact') ?></h2>
                <a class="btn btn-sm" href="<?= View::e(View::url('ghl/contacts', $wsQuery)) ?>">
                    <?php $name = 'arrow-left'; $size = 14; require __DIR__ . '/../partials/icon.php'; ?>
                    All contacts
                </a>
            </div>
            <div class="card-body">
                <dl class="kv">
                    <?php if (($contact['companyName'] ?? '') !== ''): ?>
                        <dt>Company</dt><dd><?= View::e($contact['companyName']) ?></dd>
                    <?php endif; ?>
                    <?php if ($email !== ''): ?>
                        <dt>Email</dt><dd><a href="mailto:<?= View::e($email) ?>"><?= View::e($email) ?></a></dd>
                    <?php endif; ?>
                    <?php if ($phone !== ''): ?>
                        <dt>Phone</dt><dd><a href="tel:<?= View::e($phone) ?>"><?= View::e($phone) ?></a></dd>
                    <?php endif; ?>
                    <?php if (($contact['website'] ?? '') !== ''): ?>
                        <dt>Website</dt>
                        <dd><a href="<?= View::e($contact['website']) ?>" target="_blank" rel="noopener noreferrer">
                            <?= View::e($contact['website']) ?></a></dd>
                    <?php endif; ?>
                    <?php if ($tags !== []): ?>
                        <dt>Tags</dt>
                        <dd><?php foreach ($tags as $tag): ?>
                            <span class="badge badge-neutral"><?= View::e((string) $tag) ?></span>
                        <?php endforeach; ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Conversation -->
        <div class="card">
            <div class="card-head"><h2>Conversation</h2></div>
            <div class="card-body">
                <?php if ($panelError($conversations) !== ''): ?>
                    <div class="alert alert-warning">
                        <?php $name = 'alert'; $size = 17; require __DIR__ . '/../partials/icon.php'; ?>
                        <div><?= View::e($panelError($conversations)) ?></div>
                    </div>
                <?php elseif ($panelError($messages) !== ''): ?>
                    <div class="alert alert-warning">
                        <?php $name = 'alert'; $size = 17; require __DIR__ . '/../partials/icon.php'; ?>
                        <div><?= View::e($panelError($messages)) ?></div>
                    </div>
                <?php elseif ($messages['messages'] === []): ?>
                    <p class="dim">No messages yet. The first one you send starts the thread.</p>
                <?php else: ?>
                    <div class="thread">
                        <?php foreach (array_reverse($messages['messages']) as $message): ?>
                            <?php
                            // GHL marks direction as inbound/outbound; anything
                            // else is treated as inbound so it is never styled
                            // as something we sent.
                            $outbound = ($message['direction'] ?? '') === 'outbound';
                            $body = trim((string) ($message['body'] ?? ''));
                            ?>
                            <div class="msg <?= $outbound ? 'msg-out' : 'msg-in' ?>">
                                <div class="msg-meta">
                                    <span><?= View::e($message['messageType'] ?? $message['type'] ?? 'Message') ?></span>
                                    <span><?= View::e($when($message['dateAdded'] ?? '')) ?></span>
                                </div>
                                <div class="msg-body"><?= nl2br(View::e($body !== '' ? $body : '(no text)')) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= View::e($post('ghl/send')) ?>" class="send-form" data-send-form>
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="contact_id" value="<?= View::e($contactId) ?>">
                    <input type="hidden" name="confirm" value="0" data-confirm>

                    <div class="field-row">
                        <div class="field">
                            <label for="send_type">Send as</label>
                            <select id="send_type" name="type" data-send-type>
                                <option value="Email" <?= $email === '' ? 'disabled' : '' ?>>
                                    Email<?= $email === '' ? ' (no address on file)' : '' ?>
                                </option>
                                <option value="SMS" <?= $phone === '' ? 'disabled' : '' ?>>
                                    Text<?= $phone === '' ? ' (no number on file)' : '' ?>
                                </option>
                            </select>
                        </div>
                        <div class="field" data-subject-field>
                            <label for="send_subject">Subject</label>
                            <input type="text" id="send_subject" name="subject" placeholder="Subject line">
                        </div>
                    </div>

                    <div class="field">
                        <label for="send_body">Message</label>
                        <textarea id="send_body" name="body" rows="4"
                                  placeholder="This sends from your GoHighLevel sub-account to the real contact."></textarea>
                    </div>

                    <div class="btn-row">
                        <button class="btn btn-primary" type="submit" data-send-button
                            <?= $email === '' && $phone === '' ? 'disabled' : '' ?>>
                            Send
                        </button>
                        <span class="hint">
                            <?php if ($email === '' && $phone === ''): ?>
                                This contact has no email address or phone number.
                            <?php else: ?>
                                Goes to <?= View::e($email !== '' ? $email : $phone) ?>. You will be asked to confirm.
                            <?php endif; ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="stack">
        <!-- Notes -->
        <div class="card">
            <div class="card-head"><h2>Notes</h2></div>
            <div class="card-body">
                <form method="post" action="<?= View::e($post('ghl/note')) ?>" class="mb">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="contact_id" value="<?= View::e($contactId) ?>">
                    <div class="field">
                        <textarea name="body" rows="3" placeholder="Add a note…"></textarea>
                    </div>
                    <button class="btn btn-sm btn-primary" type="submit">Add note</button>
                </form>

                <?php if ($panelError($notes) !== ''): ?>
                    <p class="dim small"><?= View::e($panelError($notes)) ?></p>
                <?php elseif ($notes['notes'] === []): ?>
                    <p class="dim small">No notes yet.</p>
                <?php else: ?>
                    <ul class="timeline">
                        <?php foreach ($notes['notes'] as $note): ?>
                            <li>
                                <div class="timeline-meta"><?= View::e($when($note['dateAdded'] ?? '')) ?></div>
                                <div><?= nl2br(View::e((string) ($note['body'] ?? ''))) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tasks -->
        <div class="card">
            <div class="card-head"><h2>Tasks</h2></div>
            <div class="card-body">
                <form method="post" action="<?= View::e($post('ghl/task')) ?>" class="mb">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                    <input type="hidden" name="contact_id" value="<?= View::e($contactId) ?>">
                    <div class="field-row">
                        <div class="field">
                            <input type="text" name="title" placeholder="Follow up call…">
                        </div>
                        <div class="field">
                            <input type="date" name="due_date">
                        </div>
                    </div>
                    <button class="btn btn-sm" type="submit">Add task</button>
                </form>

                <?php if ($panelError($tasks) !== ''): ?>
                    <p class="dim small"><?= View::e($panelError($tasks)) ?></p>
                <?php elseif ($tasks['tasks'] === []): ?>
                    <p class="dim small">Nothing outstanding.</p>
                <?php else: ?>
                    <ul class="tasklist">
                        <?php foreach ($tasks['tasks'] as $task): ?>
                            <li class="<?= !empty($task['completed']) ? 'is-done' : '' ?>">
                                <form method="post" action="<?= View::e($post('ghl/task')) ?>" class="inline-form">
                                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                    <input type="hidden" name="contact_id" value="<?= View::e($contactId) ?>">
                                    <input type="hidden" name="task_id" value="<?= View::e($task['id'] ?? '') ?>">
                                    <button class="linklike" type="submit" title="Mark complete"
                                        <?= !empty($task['completed']) ? 'disabled' : '' ?>>
                                        <?php $name = 'check'; $size = 14; require __DIR__ . '/../partials/icon.php'; ?>
                                    </button>
                                </form>
                                <div>
                                    <div><?= View::e($task['title'] ?? 'Task') ?></div>
                                    <?php if (($task['dueDate'] ?? '') !== ''): ?>
                                        <div class="timeline-meta">Due <?= View::e($when($task['dueDate'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Automations -->
        <div class="card">
            <div class="card-head"><h2>Add to an automation</h2></div>
            <div class="card-body">
                <?php if ($panelError($workflows) !== ''): ?>
                    <p class="dim small"><?= View::e($panelError($workflows)) ?></p>
                <?php elseif ($workflows['workflows'] === []): ?>
                    <p class="dim small">No workflows in this sub-account.</p>
                <?php else: ?>
                    <form method="post" action="<?= View::e($post('ghl/enroll')) ?>">
                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="contact_id" value="<?= View::e($contactId) ?>">
                        <div class="field">
                            <select name="workflow_id" aria-label="Automation">
                                <?php foreach ($workflows['workflows'] as $workflow): ?>
                                    <option value="<?= View::e($workflow['id'] ?? '') ?>">
                                        <?= View::e($workflow['name'] ?? 'Untitled workflow') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-sm" type="submit"
                                onclick="return confirm('Add this contact to the automation? It starts running immediately.')">
                            Add to automation
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
