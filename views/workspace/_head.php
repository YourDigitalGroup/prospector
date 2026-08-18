<?php

use Prospector\Support\View;

/**
 * Shared workspace header: which sub-account is in play, the tab strip, and the
 * admin's user switcher.
 *
 * @var array<string, string>    $tabs
 * @var array<string, mixed>     $workspaceUser
 * @var bool                     $viewingOther
 * @var list<array<string, mixed>> $otherUsers
 * @var string                   $tab
 * @var string                   $title
 */

$wsUserId = (int) $workspaceUser['id'];
$wsQuery = $viewingOther ? ['user_id' => $wsUserId] : [];

/** Build a workspace URL that keeps the admin's ?user_id= context. */
$wsUrl = static function (string $path, array $extra = []) use ($wsQuery): string {
    return View::url(ltrim($path, '/'), array_merge($wsQuery, $extra));
};
?>

<div class="page-head">
    <div>
        <h1><?= View::e($title) ?></h1>
        <div class="sub">
            <?php if ($viewingOther): ?>
                <span class="pill pill-warn">Viewing <?= View::e($workspaceUser['name']) ?>'s sub-account</span>
            <?php else: ?>
                Your GoHighLevel sub-account
            <?php endif; ?>
        </div>
    </div>

    <div class="page-head-actions">
        <?php if ($otherUsers !== []): ?>
            <form method="get" action="<?= View::e(View::url(ltrim(($tab === 'board' ? '/ghl' : '/ghl/' . $tab), '/'))) ?>" class="inline-form">
                <select name="user_id" onchange="this.form.submit()" aria-label="Whose sub-account to view">
                    <?php foreach ($otherUsers as $candidate): ?>
                        <option value="<?= (int) $candidate['id'] ?>" <?= (int) $candidate['id'] === $wsUserId ? 'selected' : '' ?>>
                            <?= View::e($candidate['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <a class="btn" href="<?= View::e($wsUrl('/ghl/connect')) ?>">
            <?php $name = 'link'; $size = 15; require __DIR__ . '/../partials/icon.php'; ?>
            Connection
        </a>
    </div>
</div>

<?php if ($tabs !== []): ?>
    <nav class="tabs" aria-label="Workspace sections">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="tab <?= $tab === $key ? 'is-active' : '' ?>"
               href="<?= View::e($wsUrl($key === 'board' ? '/ghl' : '/ghl/' . $key)) ?>">
                <?= View::e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
