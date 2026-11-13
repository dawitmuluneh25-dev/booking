<?php
/**
 * @var array<string,string> $errors
 */
?>
<section class="auth-card">
    <p class="eyebrow">Account recovery</p>
    <h1>Reset your password</h1>

    <p class="muted">
        Enter the address you sign in with. We will email a link that can be used once and
        expires in an hour.
    </p>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/password/forgot" novalidate>
        <?= \App\Security\Csrf::field() ?>

        <div class="field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" required>
        </div>

        <div class="actions">
            <button class="button" type="submit">Send reset link</button>
        </div>
    </form>

    <p class="muted"><a href="/login">Back to sign in</a></p>
</section>
