<?php
/**
 * @var \App\Domain\Booking $booking
 * @var string              $timezone
 */
?>
<section class="panel">
    <p class="eyebrow">Request received</p>
    <h1>Booking <?= e($booking->reference) ?></h1>
    <p class="lede">
        Keep this reference. The team will contact you on
        <?= e($booking->patientPhone) ?> to confirm the appointment.
    </p>

    <dl class="details">
        <div>
            <dt>Status</dt>
            <dd><span class="badge badge-pending"><?= e($booking->status) ?></span></dd>
        </div>
        <div>
            <dt>Requested time</dt>
            <dd><?= e($booking->scheduledAt($timezone)->format('l j F Y, H:i')) ?> (<?= e($timezone) ?>)</dd>
        </div>
        <div>
            <dt>Service</dt>
            <dd><?= e($booking->serviceLabel()) ?></dd>
        </div>
        <div>
            <dt>Name</dt>
            <dd><?= e($booking->patientName) ?></dd>
        </div>
        <div>
            <dt>Phone</dt>
            <dd><?= e($booking->patientPhone) ?></dd>
        </div>
        <?php if ($booking->notes !== null): ?>
            <div>
                <dt>Notes</dt>
                <dd><?= nl2br(e($booking->notes)) ?></dd>
            </div>
        <?php endif; ?>
    </dl>

    <p class="muted">
        A request is not a confirmed appointment until the team confirms it with you.
    </p>

    <p>
        <a class="button button-secondary" href="/bookings">All upcoming bookings</a>
        <a class="link" href="/bookings/new">Make another request</a>
    </p>
</section>
