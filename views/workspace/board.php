<?php

use Prospector\Auth;
use Prospector\Support\View;

/**
 * The columnized CRM: one column per pipeline stage. Cards are dragged between
 * them with a mouse, or moved with the picker on the card — which is the only
 * way that works on a touchscreen, where drag events never fire.
 *
 * @var string|null $error
 * @var list<array<string, mixed>> $pipelines
 * @var array<string, mixed>|null $pipeline
 * @var list<array{id: string, name: string}> $stages
 * @var array<string, list<array<string, mixed>>> $cards
 * @var bool $atLimit
 * @var array<string, mixed> $workspaceUser
 * @var bool $viewingOther
 */

require __DIR__ . '/_head.php';

// An admin looking at somebody else's sub-account has to keep ?user_id= on
// every form that posts back, or the move lands in their own account.
$wsQuery = $viewingOther ? ['user_id' => (int) $workspaceUser['id']] : [];

$money = static function (mixed $value): string {
    return is_numeric($value) && (float) $value > 0 ? '$' . number_format((float) $value) : '';
};
?>

<?php if ($error !== null): ?>
    <div class="alert alert-error">
        <?php $name = 'alert'; $size = 17; require __DIR__ . '/../partials/icon.php'; ?>
        <div><?= View::e($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($pipelines !== []): ?>
    <div class="board-bar">
        <form method="get" action="<?= View::e(View::url('ghl')) ?>" class="inline-form">
            <?php if ($viewingOther): ?>
                <input type="hidden" name="user_id" value="<?= (int) $workspaceUser['id'] ?>">
            <?php endif; ?>
            <label for="pipeline" class="inline-label">Pipeline</label>
            <select id="pipeline" name="pipeline" onchange="this.form.submit()">
                <?php foreach ($pipelines as $option): ?>
                    <option value="<?= View::e($option['id'] ?? '') ?>"
                        <?= $pipeline !== null && ($option['id'] ?? '') === ($pipeline['id'] ?? '') ? 'selected' : '' ?>>
                        <?= View::e($option['name'] ?? 'Untitled pipeline') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($atLimit): ?>
            <span class="pill pill-warn" title="GoHighLevel returns at most 100 per request">
                Showing the first 100 deals
            </span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($stages === []): ?>
    <div class="card"><div class="card-body">
        <div class="empty">
            <?php $name = 'list'; $size = 34; require __DIR__ . '/../partials/icon.php'; ?>
            <h3>No stages to show</h3>
            <p>Once this pipeline has stages in GoHighLevel, they appear here as columns.</p>
        </div>
    </div></div>
<?php else: ?>
    <div class="board" data-board
         data-endpoint="<?= View::e(View::url('ghl/move')) ?>"
         data-pipeline="<?= View::e($pipeline['id'] ?? '') ?>"
         data-csrf="<?= View::e(Auth::csrfToken()) ?>">
        <?php foreach ($stages as $stage): ?>
            <?php $stageCards = $cards[$stage['id']] ?? []; ?>
            <section class="board-col" data-stage="<?= View::e($stage['id']) ?>">
                <header class="board-col-head">
                    <span class="board-col-name"><?= View::e($stage['name']) ?></span>
                    <span class="board-count" data-count><?= count($stageCards) ?></span>
                </header>

                <div class="board-drop" data-drop>
                    <?php foreach ($stageCards as $card): ?>
                        <?php
                        $contactId = (string) ($card['contactId'] ?? $card['contact']['id'] ?? '');
                        $value = $money($card['monetaryValue'] ?? null);
                        $status = (string) ($card['status'] ?? 'open');
                        ?>
                        <article class="board-card" draggable="true"
                                 data-card="<?= View::e($card['id'] ?? '') ?>">
                            <div class="board-card-title"><?= View::e($card['name'] ?? 'Untitled') ?></div>

                            <?php if (($card['contact']['name'] ?? '') !== ''): ?>
                                <div class="board-card-sub"><?= View::e($card['contact']['name']) ?></div>
                            <?php endif; ?>

                            <div class="board-card-foot">
                                <?php if ($value !== ''): ?>
                                    <span class="board-value"><?= View::e($value) ?></span>
                                <?php endif; ?>
                                <?php if ($status !== 'open'): ?>
                                    <span class="badge badge-<?= View::e($status) ?>"><?= View::e($status) ?></span>
                                <?php endif; ?>
                                <?php if ($contactId !== ''): ?>
                                    <a class="board-card-link"
                                       href="<?= View::e(View::url('ghl/contact', array_merge(
                                           $viewingOther ? ['user_id' => (int) $workspaceUser['id']] : [],
                                           ['id' => $contactId]
                                       ))) ?>">Open</a>
                                <?php endif; ?>
                            </div>

                            <?php /* Dragging is a mouse gesture. HTML5 drag events do not fire
                                     on touch at all, so on a phone the board was read-only and
                                     the hint under it was telling people to do something that
                                     could not work. This picker does the same job without a
                                     drag, which also makes the board usable from a keyboard.
                                     It posts a normal form, so it works with no JavaScript
                                     either. */ ?>
                            <form method="post" class="board-card-move" draggable="false"
                                  action="<?= View::e(View::url('ghl/move', $wsQuery)) ?>">
                                <input type="hidden" name="csrf" value="<?= View::e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="opportunity_id" value="<?= View::e($card['id'] ?? '') ?>">
                                <input type="hidden" name="pipeline_id" value="<?= View::e($pipeline['id'] ?? '') ?>">
                                <label class="sr-only" for="move-<?= View::e($card['id'] ?? '') ?>">
                                    Move <?= View::e($card['name'] ?? 'this deal') ?> to another stage
                                </label>
                                <select id="move-<?= View::e($card['id'] ?? '') ?>" name="stage_id" data-autosubmit>
                                    <?php foreach ($stages as $option): ?>
                                        <option value="<?= View::e($option['id']) ?>"
                                            <?= $option['id'] === $stage['id'] ? 'selected' : '' ?>>
                                            <?= View::e($option['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <p class="hint mt">
        <span class="pointer-only">Drag a card to another column, or use</span>
        <span class="touch-only">Use</span>
        the stage picker on the card, to move the deal in GoHighLevel.
    </p>
<?php endif; ?>
