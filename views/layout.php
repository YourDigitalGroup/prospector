<?php

use Prospector\Auth;
use Prospector\Emails;
use Prospector\Leads;
use Prospector\Support\View;
use Prospector\Users;

/**
 * @var string $content
 * @var string $appName
 * @var string $currentPath
 * @var array<string, mixed>|null $currentUser
 * @var list<array{type: string, message: string}> $flash
 * @var string|null $title
 */

$user = $currentUser ?? null;
$isAdmin = ($user['role'] ?? '') === 'admin';
$scopeUserId = $isAdmin ? null : (int) ($user['id'] ?? 0);

$openLeads = Leads::count(['user_id' => $scopeUserId, 'open_only' => true]);
$newLeads = Leads::count(['user_id' => $scopeUserId, 'status' => 'new']);

// Emails whose day has come and which nobody has sent yet. Worth a badge:
// an approved cadence that silently stops is the failure mode here.
$dueEmails = Emails::counts($scopeUserId)['due'];

$nav = [
    'Prospect' => [
        ['path' => '/dashboard', 'label' => 'Home', 'icon' => 'home'],
        ['path' => '/leads', 'label' => 'Leads', 'icon' => 'list', 'count' => $openLeads > 0 ? $openLeads : null],
    ],
    'Pipeline' => [
        ['path' => '/runs', 'label' => 'Daily batches', 'icon' => 'zap'],
        ['path' => '/outreach', 'label' => 'Outreach', 'icon' => 'mail', 'count' => $dueEmails > 0 ? $dueEmails : null],
    ],
    // The GoHighLevel workspace. Its own group because these are the screens
    // Billy and Darren live in once a lead is in play, not somewhere they dip
    // into occasionally.
    'GoHighLevel' => [
        ['path' => '/ghl', 'label' => 'Pipeline board', 'icon' => 'link'],
        ['path' => '/ghl/contacts', 'label' => 'Contacts', 'icon' => 'users'],
        ['path' => '/ghl/inbox', 'label' => 'Inbox', 'icon' => 'inbox'],
        ['path' => '/ghl/automations', 'label' => 'Automations', 'icon' => 'zap'],
    ],
];

if ($isAdmin) {
    $nav['Admin'] = [
        ['path' => '/users', 'label' => 'Users', 'icon' => 'users'],
        ['path' => '/settings', 'label' => 'Settings', 'icon' => 'settings'],
    ];
}

$active = static function (string $path) use ($currentPath): bool {
    if ($path === '/dashboard') {
        return $currentPath === '/dashboard' || $currentPath === '/';
    }

    // /ghl is the board, and every other workspace screen hangs off it, so the
    // usual prefix rule would light up the board for all of them.
    if ($path === '/ghl') {
        return $currentPath === '/ghl';
    }

    return $currentPath === $path || str_starts_with($currentPath, $path . '/');
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e(($title ?? 'Home') . ' · ' . $appName) ?></title>
    <link rel="icon" href="<?= View::e(View::url('assets/img/pickaxe.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= View::e(View::asset('assets/css/app.css')) ?>">
    <meta name="robots" content="noindex, nofollow">
    <script>
        // Set the theme before first paint so there is no flash of the wrong one.
        try {
            var t = localStorage.getItem('prospector-theme');
            if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    </script>
</head>
<body>
<div class="shell">
    <aside class="sidebar" data-collapsed="true">
        <div class="brand">
            <?php require __DIR__ . '/partials/logo.php'; ?>
            <div>
                <span class="brand-name"><?= View::e($appName) ?></span>
                <span class="brand-sub">44i lead generation</span>
            </div>
            <button type="button" class="icon-btn mobile-only" data-sidebar-toggle aria-expanded="false"
                    aria-label="Toggle navigation" style="margin-left:auto">
                <?php $name = 'menu'; $size = 16; require __DIR__ . '/partials/icon.php'; ?>
            </button>
        </div>

        <nav class="nav">
            <?php foreach ($nav as $group => $items): ?>
                <div class="nav-group">
                    <div class="nav-label"><?= View::e($group) ?></div>
                    <?php foreach ($items as $item): ?>
                        <a class="nav-item<?= $active($item['path']) ? ' is-active' : '' ?>"
                           href="<?= View::e(View::url(ltrim($item['path'], '/'))) ?>">
                            <?php $name = $item['icon']; $size = 16; require __DIR__ . '/partials/icon.php'; ?>
                            <span><?= View::e($item['label']) ?></span>
                            <?php if (!empty($item['count'])): ?>
                                <span class="nav-count"><?= (int) $item['count'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <div class="user-chip">
                <div class="avatar"><?= View::e(Users::initials((string) ($user['name'] ?? '?'))) ?></div>
                <div class="user-chip-meta">
                    <div class="user-chip-name truncate"><?= View::e($user['name'] ?? '') ?></div>
                    <div class="user-chip-role truncate">
                        <?= $isAdmin ? 'Admin' : View::e(\Prospector\Runs::loopLabel((string) ($user['loop'] ?? 'none'))) ?>
                    </div>
                </div>
                <a class="icon-btn" href="<?= View::e(View::url('logout')) ?>" title="Sign out" aria-label="Sign out">
                    <?php $name = 'external'; $size = 15; require __DIR__ . '/partials/icon.php'; ?>
                </a>
            </div>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <form class="search" method="get" action="<?= View::e(View::url('leads')) ?>" role="search">
                <?php $name = 'search'; $size = 16; require __DIR__ . '/partials/icon.php'; ?>
                <input type="search" name="q" placeholder="Search leads by company, person, market…"
                       value="<?= View::e($_GET['q'] ?? '') ?>" aria-label="Search leads">
            </form>

            <div class="topbar-right">
                <span class="pill" title="Daily batches are delivered on this schedule">
                    <?php $name = 'clock'; $size = 13; require __DIR__ . '/partials/icon.php'; ?>
                    <?= View::e(\Prospector\Mailer::scheduleDescription()) ?>
                </span>
                <button type="button" class="icon-btn" data-theme-toggle title="Switch light / dark theme"
                        aria-label="Switch light or dark theme">
                    <?php $name = 'sun'; $size = 16; require __DIR__ . '/partials/icon.php'; ?>
                </button>
            </div>
        </header>

        <main class="content">
            <?php foreach ($flash ?? [] as $message): ?>
                <div class="alert alert-<?= View::e($message['type'] === 'error' ? 'error' : $message['type']) ?>">
                    <?php $name = $message['type'] === 'error' ? 'alert' : 'check'; $size = 17; require __DIR__ . '/partials/icon.php'; ?>
                    <div><?= View::e($message['message']) ?></div>
                </div>
            <?php endforeach; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= View::e(View::asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
