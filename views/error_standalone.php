<?php

use Prospector\Support\View;

/**
 * Full-page error, used when there is no signed-in session to wrap it in.
 *
 * @var int $code
 * @var string $heading
 * @var string $message
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($heading) ?> · Prospector</title>
    <?php require __DIR__ . '/partials/favicon.php'; ?>
    <link rel="stylesheet" href="<?= View::e(View::asset('assets/css/app.css')) ?>">
    <meta name="robots" content="noindex, nofollow">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand">
            <?php $size = 30; require __DIR__ . '/partials/logo.php'; ?>
            <span>Prospector</span>
        </div>
        <div class="card">
            <div class="card-body center">
                <h1 class="mb"><?= View::e($heading) ?></h1>
                <p class="dim"><?= View::e($message) ?></p>
                <div class="mt">
                    <a class="btn btn-primary" href="<?= View::e(View::url('')) ?>">Back to Prospector</a>
                </div>
            </div>
        </div>
        <p class="auth-foot">Error <?= (int) $code ?></p>
    </div>
</div>
</body>
</html>
