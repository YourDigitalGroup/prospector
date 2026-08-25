<?php

use Prospector\Support\View;

/**
 * @var array{ok: bool, workflows: list<array<string, mixed>>, error: string} $workflows
 * @var array{ok: bool, agents: list<array<string, mixed>>, error: string} $agents
 * @var list<array<string, mixed>> $rules
 * @var array<string, array{label: string, value: string|null, hint: string}> $events
 * @var array<string, string> $statuses
 * @var array<string, mixed> $workspaceUser
 * @var bool $viewingOther
 * @var string $csrf
 */

require __DIR__ . '/_head.php';

$ruleLink = static fn (): string => View::url('ghl/rule');
?>

<div class="card mb">
    <div class="card-head">
        <h2>Automatic enrolment</h2>
        <span class="dim small"><?= count($rules) ?> rule<?= count($rules) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">
        <p class="dim">
            Rules add people to a GoHighLevel automation without anyone clicking. Every enrolment is
            written to that lead's history, so this is never something that happened quietly.
        </p>

        <?php if ($rules === []): ?>
            <p class="muted small mt">No rules yet — everything is added by hand.</p>
        <?php else: ?>
            <div class="table-scroll mt">
                <table class="data">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Add to</th>
                            <th class="nowrap">Added so far</th>
                            <th class="nowrap">Last run</th>
                            <th class="right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rules as $rule): ?>
                            <tr<?= (int) $rule['active'] === 0 ? ' style="opacity:.55"' : '' ?>>
                                <td>
                                    <?= View::e(\Prospector\Automations::describe($rule)) ?>
                                    <?php if ((int) $rule['active'] === 0): ?>
                                        <span class="badge badge-neutral">paused</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= View::e($rule['workflow_name'] ?: $rule['workflow_id']) ?></td>
                                <td class="nowrap small dim"><?= (int) $rule['enrolled_count'] ?></td>
                                <td class="nowrap small dim">
                                    <?= $rule['last_run_at'] === null
                                        ? 'never'
                                        : View::e(\Prospector\Support\Clock::display((string) $rule['last_run_at'], 'M j')) ?>
                                </td>
                                <td class="right nowrap">
                                    <form method="post" action="<?= View::e($ruleLink()) ?>" class="inline-form">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $workspaceUser['id'] ?>">
                                        <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                                        <button class="linkish" type="submit" name="action"
                                                value="<?= (int) $rule['active'] === 1 ? 'pause' : 'resume' ?>">
                                            <?= (int) $rule['active'] === 1 ? 'Pause' : 'Resume' ?>
                                        </button>
                                    </form>
                                    <form method="post" action="<?= View::e($ruleLink()) ?>" class="inline-form"
                                          data-confirm="Delete this rule? Anyone already enrolled stays enrolled.">
                                        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $workspaceUser['id'] ?>">
                                        <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                                        <button class="linkish" type="submit" name="action" value="delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($workflows['ok'] && $workflows['workflows'] !== []): ?>
            <hr class="divider">
            <form method="post" action="<?= View::e($ruleLink()) ?>" class="filters">
                <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $workspaceUser['id'] ?>">
                <input type="hidden" name="action" value="add">

                <div class="field">
                    <label for="on_event">When</label>
                    <select id="on_event" name="on_event">
                        <?php foreach ($events as $key => $spec): ?>
                            <option value="<?= View::e($key) ?>"><?= View::e($spec['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="event_value">Value</label>
                    <input type="text" id="event_value" name="event_value" list="rule-values"
                           placeholder="85, or a status" style="width:150px">
                    <datalist id="rule-values">
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?= View::e($key) ?>"><?= View::e($label) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="field grow">
                    <label for="rule_workflow">Add them to</label>
                    <select id="rule_workflow" name="workflow_id" required
                            onchange="this.form.workflow_name.value = this.options[this.selectedIndex].text">
                        <option value="">Choose an automation…</option>
                        <?php foreach ($workflows['workflows'] as $workflow): ?>
                            <option value="<?= View::e($workflow['id'] ?? '') ?>">
                                <?= View::e($workflow['name'] ?? 'Untitled workflow') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="workflow_name" value="">
                </div>

                <button class="btn btn-primary" type="submit">Add rule</button>
            </form>

            <ul class="notes mt">
                <?php foreach ($events as $key => $spec): ?>
                    <li><strong><?= View::e($spec['label']) ?></strong> — <?= View::e($spec['hint']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="card-foot">
        <form method="post" action="<?= View::e(View::url('ghl/sweep')) ?>" data-busy="Checking…">
            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $workspaceUser['id'] ?>">
            <button class="btn btn-sm" type="submit">Run the rules now</button>
        </form>
        <span class="dim small" style="margin-left:auto">
            Score and new-lead rules also run on their own with the daily schedule.
        </span>
    </div>
</div>

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
                <p class="hint mt">
                    Open a lead to add them to one of these by hand, tick several on the Leads screen
                    to add them in bulk, or set a rule above to do it automatically.
                </p>
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
