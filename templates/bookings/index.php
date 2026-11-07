<?php
/**
 * @var list<\App\Domain\Booking> $bookings
 * @var string|null               $notice
 * @var string                    $timezone
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Upcoming bookings</h1>
        <a class="button button-secondary" href="/bookings/new">New request</a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($bookings === []): ?>
        <p class="empty">No upcoming bookings yet. <a href="/bookings/new">Create the first request</a>.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Upcoming bookings</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">When</th>
                        <th scope="col">Service</th>
                        <th scope="col">Name</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td><a href="/bookings/<?= e(rawurlencode($booking->reference)) ?>"><?= e($booking->reference) ?></a></td>
                            <td><?= e($booking->scheduledAt($timezone)->format('D j M Y, H:i')) ?></td>
                            <td><?= e($booking->serviceLabel()) ?></td>
                            <td><?= e($booking->patientName) ?></td>
                            <td><span class="badge"><?= e($booking->status) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
