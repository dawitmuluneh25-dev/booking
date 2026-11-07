<?php
/** @var string $message */
?>
<section class="panel">
    <p class="eyebrow">Session</p>
    <h1>Your session expired</h1>
    <p class="lede"><?= e($message) ?> The form was not submitted.</p>
    <p><a class="button" href="/bookings/new">Open the form again</a></p>
</section>
