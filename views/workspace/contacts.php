<?php

use Prospector\Support\View;

/**
 * @var list<array<string, mixed>> $contacts
 * @var string|null $error
 * @var string $query
 * @var array<string, mixed> $workspaceUser
 * @var bool $viewingOther
 */

require __DIR__ . '/_head.php';

$contactLink = static function (string $id) use ($viewingOther, $workspaceUser): string {
    return View::url('ghl/contact', array_merge(
        $viewingOther ? ['user_id' => (int) $workspaceUser['id']] : [],
        ['id' => $id]
    ));
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
        <h2>Contacts</h2>
        <form method="get" action="<?= View::e(View::url('ghl/contacts')) ?>" class="search">
            <?php if ($viewingOther): ?>
                <input type="hidden" name="user_id" value="<?= (int) $workspaceUser['id'] ?>">
            <?php endif; ?>
            <?php $name = 'search'; $size = 15; require __DIR__ . '/../partials/icon.php'; ?>
            <input type="search" name="q" value="<?= View::e($query) ?>" placeholder="Search name, email, phone…">
        </form>
    </div>

    <?php if ($contacts === []): ?>
        <div class="card-body">
            <div class="empty">
                <?php $name = 'users'; $size = 34; require __DIR__ . '/../partials/icon.php'; ?>
                <h3><?= $query !== '' ? 'Nothing matched' : 'No contacts yet' ?></h3>
                <p>
                    <?= $query !== ''
                        ? 'Try a shorter search, or part of an email address.'
                        : 'Contacts in this GoHighLevel sub-account will show up here. Push a lead from the Leads screen to add one.' ?>
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Email</th>
                        <th class="nowrap">Phone</th>
                        <th>Tags</th>
                        <th class="shrink"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $contact): ?>
                        <?php
                        $id = (string) ($contact['id'] ?? '');
                        $contactName = trim((string) ($contact['contactName']
                            ?? trim((string) ($contact['firstName'] ?? '') . ' ' . (string) ($contact['lastName'] ?? ''))));
                        $tags = is_array($contact['tags'] ?? null) ? $contact['tags'] : [];
                        ?>
                        <tr>
                            <td class="cell-primary">
                                <?php if ($id !== ''): ?>
                                    <a href="<?= View::e($contactLink($id)) ?>"><?= View::e($contactName !== '' ? $contactName : 'Unnamed contact') ?></a>
                                <?php else: ?>
                                    <?= View::e($contactName !== '' ? $contactName : 'Unnamed contact') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= View::e($contact['companyName'] ?? '') ?></td>
                            <td class="cell-clip"><?= View::e($contact['email'] ?? '') ?></td>
                            <td class="nowrap"><?= View::e($contact['phone'] ?? '') ?></td>
                            <td>
                                <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                                    <span class="badge badge-neutral"><?= View::e((string) $tag) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($tags) > 3): ?>
                                    <span class="dim small">+<?= count($tags) - 3 ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="shrink nowrap">
                                <?php if ($id !== ''): ?>
                                    <a class="btn btn-sm" href="<?= View::e($contactLink($id)) ?>">Open</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
