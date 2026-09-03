<?php

use Prospector\Auth;
use Prospector\LeadForm;
use Prospector\Support\View;

/**
 * Add one lead by hand.
 *
 * The field list is not written out here — it comes from LeadForm::groups(),
 * so this file renders whatever the form says it has and never needs editing
 * when a field is added. Anything that fails validation comes back with the
 * typing intact and the message attached to the field it belongs to.
 *
 * @var array<string, list<string>>  $groups
 * @var array<string, string>        $values
 * @var array<string, string>        $errors
 * @var list<array<string, mixed>>   $owners
 * @var int                          $targetUserId
 */
?>

<div class="page-head">
    <div>
        <h1>New lead</h1>
        <div class="sub">For somebody you have actually spoken to — only the company is required</div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-ghost" href="<?= View::e(View::url('leads/import')) ?>">
            <?php $name = 'list'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            Upload a list instead
        </a>
        <a class="btn btn-ghost" href="<?= View::e(View::url('leads')) ?>">
            <?php $name = 'arrow-left'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            Back to leads
        </a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php if (count($errors) === 1): ?>
            <?= View::e(reset($errors)) ?>
        <?php else: ?>
            Nothing was saved — <?= count($errors) ?> fields need another look.
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= View::e(View::url('leads/new')) ?>" data-busy>
    <input type="hidden" name="csrf" value="<?= View::e(Auth::csrfToken()) ?>">

    <?php if ($owners !== []): ?>
        <div class="card">
            <div class="card-body">
                <div class="field">
                    <label for="user_id">Whose lead is this?</label>
                    <select id="user_id" name="user_id">
                        <?php foreach ($owners as $owner): ?>
                            <option value="<?= (int) $owner['id'] ?>" <?= (int) $owner['id'] === $targetUserId ? 'selected' : '' ?>>
                                <?= View::e($owner['name']) ?> — <?= View::e($owner['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">De-duplication is per owner, so the same company can sit with more than one of you.</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($groups as $heading => $fields): ?>
        <div class="card">
            <div class="card-head"><h2><?= View::e($heading) ?></h2></div>
            <div class="card-body">
                <div class="field-grid">
                    <?php foreach ($fields as $field): ?>
                        <?php
                        $spec = LeadForm::field($field);
                        $value = (string) ($values[$field] ?? '');
                        $error = $errors[$field] ?? null;
                        $wide = ($spec['type'] ?? 'text') === 'textarea';
                        ?>
                        <div class="field<?= $wide ? ' field-wide' : '' ?>">
                            <label for="f-<?= View::e($field) ?>"><?= View::e($spec['label']) ?></label>

                            <?php if (($spec['type'] ?? '') === 'textarea'): ?>
                                <textarea id="f-<?= View::e($field) ?>" name="<?= View::e($field) ?>"
                                          rows="<?= (int) ($spec['rows'] ?? 3) ?>"
                                          placeholder="<?= View::e($spec['placeholder'] ?? '') ?>"><?= View::e($value) ?></textarea>

                            <?php elseif (($spec['type'] ?? '') === 'select'): ?>
                                <select id="f-<?= View::e($field) ?>" name="<?= View::e($field) ?>">
                                    <?php foreach (($spec['options'] ?? []) as $key => $label): ?>
                                        <option value="<?= View::e((string) $key) ?>" <?= $value === (string) $key ? 'selected' : '' ?>>
                                            <?= View::e((string) $label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php else: ?>
                                <input type="<?= View::e($spec['type'] ?? 'text') ?>"
                                       id="f-<?= View::e($field) ?>" name="<?= View::e($field) ?>"
                                       value="<?= View::e($value) ?>"
                                       <?php if (($spec['type'] ?? '') === 'number'): ?>min="0" max="100"<?php endif; ?>
                                       <?= $field === 'company' ? 'required autofocus' : '' ?>
                                       placeholder="<?= View::e($spec['placeholder'] ?? '') ?>">
                            <?php endif; ?>

                            <?php if ($error !== null): ?>
                                <div class="hint hint-error"><?= View::e($error) ?></div>
                            <?php elseif (isset($spec['hint'])): ?>
                                <div class="hint"><?= View::e($spec['hint']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <div class="card-body form-actions">
            <button type="submit" class="btn btn-primary">
                <?php $name = 'plus'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                Save lead
            </button>
            <?php /* A stack of business cards after an event is the normal
                     case, so there is a way through it that does not go via
                     the leads list every time. */ ?>
            <button type="submit" class="btn" name="and_another" value="1">Save and add another</button>
            <a class="btn btn-ghost" href="<?= View::e(View::url('leads')) ?>">Cancel</a>
        </div>
    </div>
</form>
