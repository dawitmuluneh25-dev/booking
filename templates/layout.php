<?php
/**
 * @var string $title
 * @var string $content
 * @var string $appName
 */
$navItems = [];
$signedIn = ($currentUser ?? null) !== null;

if ($signedIn) {
    if ($currentUser->can(\App\Domain\Permission::ReferralTriage)) {
        $navItems[] = ['href' => '/referrals', 'label' => 'Referrals'];
    }

    if ($currentUser->can(\App\Domain\Permission::ReferralReadOwn)) {
        $navItems[] = ['href' => '/my-referrals', 'label' => 'My referrals'];
    }

    if ($currentUser->can(\App\Domain\Permission::ClinicalReview)) {
        $navItems[] = ['href' => '/review', 'label' => 'Review'];
    }

    // The register the review screen depends on (AC-009). Only the clinical role
    // holds this, so the link appears for the people whose approvals it gates.
    if ($currentUser->can(\App\Domain\Permission::ClinicalCredentialManage)) {
        $navItems[] = ['href' => '/practitioners', 'label' => 'Credentials'];
    }

    if ($currentUser->can(\App\Domain\Permission::UrgentAcknowledge)) {
        $navItems[] = ['href' => '/urgent-cases', 'label' => 'Urgent'];
    }

    // The day's list, for the roles that work it. Same permission as the room
    // schedule above it, because it is the same audience on the same day.
    if ($currentUser->can(\App\Domain\Permission::ScheduleManage)) {
        $navItems[] = ['href' => '/appointments', 'label' => 'Procedure day'];
    }

    if ($currentUser->can(\App\Domain\Permission::NotificationRead)) {
        $navItems[] = ['href' => '/notifications', 'label' => 'Notifications'];
    }

    $navItems[] = ['href' => '/bookings/new', 'label' => 'New booking'];
    $navItems[] = ['href' => '/bookings', 'label' => 'Upcoming'];
    $navItems[] = ['href' => '/availability', 'label' => 'Availability'];

    if ($currentUser->can(\App\Domain\Permission::SystemMaintain)) {
        $navItems[] = ['href' => '/admin', 'label' => 'Admin'];
    }
}
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
        <?php /*
         * The disclosure button is only rendered when there is something to
         * disclose. Without a script it stays hidden by CSS, so a browser with
         * JavaScript disabled keeps the plain wrapped link bar rather than an
         * unopenable menu.
         */ ?>
        <?php if ($navItems !== []): ?>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav-panel">
                <span class="nav-toggle-bars" aria-hidden="true"></span>
                <span>Menu</span>
            </button>
        <?php endif; ?>
        <?php /*
         * The links and the account actions share one panel because they do not
         * fit side by side at 320px. Keeping them together means the narrow
         * header is one row instead of three, and the panel opens as a single
         * block rather than two independent ones.
         */ ?>
        <?php /*
         * With no links there is nothing to fold away, so the panel stays inline
         * and the anonymous header is one row instead of two.
         */ ?>
        <div class="<?= $navItems === [] ? 'header-nav-panel header-nav-panel--inline' : 'header-nav-panel' ?>" id="site-nav-panel">
            <nav class="site-nav" aria-label="Main">
                <?php foreach ($navItems as $item): ?>
                    <a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="header-account">
                <?php if ($signedIn): ?>
                    <span class="account-name"><?= e($currentUser->name) ?></span>
                    <form method="post" action="/logout" class="inline-form">
                        <?= \App\Security\Csrf::field() ?>
                        <button class="link-button" type="submit">Sign out</button>
                    </form>
                <?php else: ?>
                    <a class="nav-signin" href="/login">Staff sign in</a>
                <?php endif; ?>
            </div>
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
        <p><a href="/services">Services and procedures</a></p>
        <p>Requests are reviewed by the team. A submitted request is not a confirmed appointment.</p>
        <p class="muted">&copy; <?= e(gmdate('Y')) ?> <?= e($appName ?? 'Booking') ?></p>
    </div>
</footer>
</body>
</html>
