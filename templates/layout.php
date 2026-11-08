<?php
/**
 * @var string $title
 * @var string $content
 * @var string $appName
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Booking') ?> · <?= e($appName ?? 'Booking') ?></title>
    <meta name="description" content="Request a booking online.">
    <meta name="theme-color" content="#0f4c5c">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/icons/icon-192.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
    <div class="shell header-inner">
        <a class="brand" href="/">
            <span class="brand-mark" aria-hidden="true"></span>
            <span><?= e($appName ?? 'Booking') ?></span>
        </a>
        <nav class="site-nav" aria-label="Main">
            <a href="/bookings/new">New booking</a>
            <a href="/bookings">Upcoming</a>
            <?php if (($currentUser ?? null) !== null && $currentUser->can(\App\Domain\Permission::UsersManage)): ?>
                <a href="/admin/users">Users</a>
            <?php endif; ?>
        </nav>
        <div class="header-account">
            <?php if (($currentUser ?? null) !== null): ?>
                <span class="account-name"><?= e($currentUser->name) ?></span>
                <form method="post" action="/logout" class="inline-form">
                    <?= \App\Security\Csrf::field() ?>
                    <button class="link-button" type="submit">Sign out</button>
                </form>
            <?php else: ?>
                <a class="nav-signin" href="/login">Staff sign in</a>
            <?php endif; ?>
        </div>
        <p class="net-status" id="net-status" role="status" aria-live="polite" hidden>
            <span class="dot" aria-hidden="true"></span><span id="net-status-text">Offline</span>
        </p>
    </div>
</header>

<main id="main" class="shell">
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="shell">
        <p>Requests are reviewed by the team. A submitted request is not a confirmed appointment.</p>
        <p class="muted">&copy; <?= e(gmdate('Y')) ?> <?= e($appName ?? 'Booking') ?></p>
    </div>
</footer>
</body>
</html>
