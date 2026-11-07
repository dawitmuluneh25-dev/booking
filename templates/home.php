<?php
/**
 * @var array<string, string> $services
 */
?>
<section class="hero">
    <p class="eyebrow">Online booking</p>
    <h1>Request an appointment in a few steps.</h1>
    <p class="lede">
        Send your details and preferred time. The team reviews every request and confirms the
        slot with you — the request itself does not reserve a time.
    </p>
    <p><a class="button" href="/bookings/new">Start a booking request</a></p>
</section>

<section class="grid" aria-labelledby="services-heading">
    <h2 id="services-heading" class="section-title">What you can request</h2>
    <ul class="cards">
        <?php foreach ($services as $key => $label): ?>
            <li class="card">
                <h3><?= e($label) ?></h3>
                <p class="muted">Choose this when you submit the form. You will pick a preferred date and time.</p>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="panel">
    <h2>How it works</h2>
    <ol class="steps">
        <li><strong>Submit the form.</strong> You receive a booking reference immediately.</li>
        <li><strong>We review it.</strong> The team checks availability and contact details.</li>
        <li><strong>You are confirmed.</strong> A confirmed appointment is communicated separately.</li>
    </ol>
</section>
