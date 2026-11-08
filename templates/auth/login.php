<?php
/**
 * @var string|null $error
 * @var string      $email
 * @var string      $next
 */
?>
<section class="auth-card">
    <p class="eyebrow">Staff access</p>
    <h1>Sign in</h1>
    <p class="muted">Managing bookings requires an account.</p>

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
</section>
