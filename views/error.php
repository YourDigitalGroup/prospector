<?php

use Prospector\Support\View;

/**
 * Error fragment, rendered inside the app chrome for signed-in users.
 *
 * @var int $code
 * @var string $heading
 * @var string $message
 */
?>
<div class="card">
    <div class="card-body center" style="padding:52px 24px">
        <?php $name = 'alert'; $size = 34; require __DIR__ . '/partials/icon.php'; ?>
        <h1 class="mt-sm mb"><?= View::e($heading) ?></h1>
        <p class="dim"><?= View::e($message) ?></p>
        <div class="mt">
            <a class="btn btn-primary" href="<?= View::e(View::url('dashboard')) ?>">Back to home</a>
        </div>
        <p class="muted small mt">Error <?= (int) $code ?></p>
    </div>
</div>
