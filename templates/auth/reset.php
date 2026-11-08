<?php
/**
 * @var string              $token
 * @var array<string,string> $errors
 */
?>
<section class="auth-card">
    <p class="eyebrow">Account recovery</p>
    <h1>Set a new password</h1>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/password/reset/<?= e(rawurlencode($token)) ?>" novalidate>
        <?= \App\Security\Csrf::field() ?>

        <div class="field">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
            <p class="field-hint">At least 12 characters.</p>
        </div>

        <div class="field">
            <label for="password_confirmation">Repeat password</label>
            <input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                minlength="12"
                required
            >
        </div>

        <div class="actions">
            <button class="button" type="submit">Update password</button>
        </div>
    </form>
</section>
