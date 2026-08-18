<?php

use Prospector\Support\View;

/**
 * @var array{ok: bool, workflows: list<array<string, mixed>>, error: string} $workflows
 * @var array{ok: bool, agents: list<array<string, mixed>>, error: string} $agents
 * @var array<string, mixed> $workspaceUser
 * @var bool $viewingOther
 */

require __DIR__ . '/_head.php';
?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h2>Workflows</h2>
            <span class="dim small"><?= count($workflows['workflows']) ?></span>
        </div>
        <div class="card-body">
            <?php if (!$workflows['ok']): ?>
                <div class="alert alert-warning">
                    <?php $name = 'alert'; $size = 17; require __DIR__ . '/../partials/icon.php'; ?>
                    <div>
                        <?= View::e($workflows['error']) ?>
                        <div class="hint">This panel needs the <code>workflows.readonly</code> scope on the token.</div>
                    </div>
                </div>
            <?php elseif ($workflows['workflows'] === []): ?>
                <div class="empty">
                    <?php $name = 'zap'; $size = 34; require __DIR__ . '/../partials/icon.php'; ?>
                    <h3>No workflows</h3>
                    <p>Build one in GoHighLevel and it will show up here, ready to drop contacts into.</p>
                </div>
            <?php else: ?>
                <ul class="listy">
                    <?php foreach ($workflows['workflows'] as $workflow): ?>
                        <li>
                            <div>
                                <div class="listy-title"><?= View::e($workflow['name'] ?? 'Untitled workflow') ?></div>
                                <?php if (($workflow['status'] ?? '') !== ''): ?>
                                    <div class="cell-sub"><?= View::e($workflow['status']) ?></div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="hint mt">Open a contact to add them to one of these.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Conversation AI agents</h2>
            <span class="dim small"><?= count($agents['agents']) ?></span>
        </div>
        <div class="card-body">
            <?php if (!$agents['ok']): ?>
                <div class="alert alert-info">
                    <?php $name = 'alert'; $size = 17; require __DIR__ . '/../partials/icon.php'; ?>
                    <div>
                        Conversation AI is not reachable on this sub-account.
                        <div class="hint">
                            Either the plan does not include it or the token is missing the Conversation AI scopes.
                            GoHighLevel said: <?= View::e($agents['error']) ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($agents['agents'] === []): ?>
                <div class="empty">
                    <?php $name = 'zap'; $size = 34; require __DIR__ . '/../partials/icon.php'; ?>
                    <h3>No AI agents</h3>
                    <p>Agents configured in GoHighLevel show up here with what they are set to do.</p>
                </div>
            <?php else: ?>
                <ul class="listy">
                    <?php foreach ($agents['agents'] as $agent): ?>
                        <?php $actions = is_array($agent['actions'] ?? null) ? $agent['actions'] : []; ?>
                        <li>
                            <div>
                                <div class="listy-title"><?= View::e($agent['name'] ?? 'Untitled agent') ?></div>
                                <div class="cell-sub">
                                    <?= View::e($agent['status'] ?? 'unknown status') ?>
                                    <?php if ($actions !== []): ?>
                                        · <?= count($actions) ?> action<?= count($actions) === 1 ? '' : 's' ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="hint mt">
                Read-only on purpose. GoHighLevel's API can create and configure agents, but nothing documented
                turns a bot on or off for a single contact or conversation — which is the control a rep would
                actually want mid-thread. Do that in GoHighLevel.
            </p>
        </div>
    </div>
</div>
