<?php
/**
 * @var string|null $error
 * @var string|null $notice
 * @var string      $email
 * @var string      $next
 */
?>
<section class="auth-card">
    <p class="eyebrow">Account</p>
    <h1>Sign in</h1>
    <p class="muted">Referrals and bookings both need an account.</p>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" novalidate>
        <?= \App\Security\Csrf::field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <div class="field">
            <label for="email">Email</label>
            <input
                id="email"
                name="email"
                type="email"
                value="<?= e($email) ?>"
                autocomplete="username"
                maxlength="254"
                required
            >
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >
        </div>

        <div class="actions">
            <button class="button" type="submit">Sign in</button>
        </div>
    </form>

    <?php /* The reverse of the landing page's link. A clinician who followed a
             bookmark to this page needs the way back to the request form, and
             there is no other route to it from inside the application. */ ?>
    <p class="muted">No account yet? <a href="/request-account">Request a referring account</a>.</p>
</section>
