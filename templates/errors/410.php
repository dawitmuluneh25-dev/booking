<?php
/** @var string $message */
?>
<section class="panel">
    <p class="eyebrow">Link expired</p>
    <h1>This link no longer works</h1>
    <p class="lede"><?= e($message) ?></p>
    <p class="muted">Reset links are single-use and expire after one hour.</p>
    <p><a class="button button-secondary" href="/login">Back to sign in</a></p>
</section>
