<?php

use Prospector\Support\View;

/**
 * @var string|null $error
 * @var string $email
 * @var bool $needsPassword
 * @var string $csrf
 * @var string $appName
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= View::e($appName ?? 'Prospector') ?></title>
    <?php require __DIR__ . '/partials/favicon.php'; ?>
    <link rel="stylesheet" href="<?= View::e(View::asset('assets/css/app.css')) ?>">
    <meta name="robots" content="noindex, nofollow">
    <script>
        try {
            var t = localStorage.getItem('prospector-theme');
            if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    </script>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand">
            <?php $size = 72; $variant = 'full'; require __DIR__ . '/partials/logo.php'; ?>
            <span><?= View::e($appName ?? 'Prospector') ?></span>
        </div>
        <p class="auth-tag">Qualified leads, dug out and delivered every morning.</p>

        <div class="card">
            <div class="card-body">
                <?php if ($error !== null): ?>
                    <div class="alert alert-error">
                        <?php $name = 'alert'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                        <div><?= View::e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= View::e(View::url('login')) ?>">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">

                    <div class="field">
                        <label for="email">Work email</label>
                        <input type="email" id="email" name="email" value="<?= View::e($email) ?>"
                               placeholder="you@44i.com" autocomplete="username" required autofocus>
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" autocomplete="current-password"
                               <?= $needsPassword ? 'required autofocus' : '' ?>>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="<?= View::e(View::asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
