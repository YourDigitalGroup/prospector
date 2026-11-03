<?php

use Prospector\Auth;
use Prospector\Support\View;

/**
 * Edit a lead that already exists.
 *
 * The same field cards as adding one, filled in from the record. Status is
 * not here — it has its own control on the lead, which writes the timeline and
 * starts automations. Clearing a field clears it: unlike an upload, a blank
 * here is someone deleting a wrong value on purpose.
 *
 * @var array<string, mixed>        $lead
 * @var array<string, list<string>> $groups
 * @var array<string, string>       $values
 * @var array<string, string>       $errors
 */

$leadUrl = 'leads/' . (int) $lead['id'];
// Editing usually means changing one thing somewhere down the form; jumping
// focus to the company box would scroll past it.
$noAutofocus = true;
// An empty field on an existing lead should look empty, not show an example
// that reads like the lead's own data.
$noPlaceholders = true;
?>

<div class="page-head">
    <div>
        <h1>Edit <?= View::e($lead['company']) ?></h1>
        <div class="sub">Every change is written to the lead's history with what it was before</div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-ghost" href="<?= View::e(View::url($leadUrl)) ?>">
            <?php $name = 'arrow-left'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
            Back to the lead
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

<form method="post" action="<?= View::e(View::url($leadUrl . '/edit')) ?>" data-busy>
    <input type="hidden" name="csrf" value="<?= View::e(Auth::csrfToken()) ?>">

    <?php require __DIR__ . '/partials/lead_fields.php'; ?>

    <div class="card">
        <div class="card-body form-actions">
            <button type="submit" class="btn btn-primary">
                <?php $name = 'check'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                Save changes
            </button>
            <a class="btn btn-ghost" href="<?= View::e(View::url($leadUrl)) ?>">Cancel</a>
        </div>
    </div>
</form>
