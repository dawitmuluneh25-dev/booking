<?php
/**
 * @var list<\App\Domain\Booking> $bookings
 * @var string|null               $notice
 * @var string|null               $error
 * @var string                    $timezone
 * @var bool                      $canManage
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

    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
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
                        <?php if ($canManage): ?>
                            <th scope="col">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td class="cell-ref"><a href="/bookings/<?= e(rawurlencode($booking->reference)) ?>"><?= e($booking->reference) ?></a></td>
                            <td class="cell-when"><?= e($booking->scheduledAt($timezone)->format('D j M Y, H:i')) ?></td>
                            <td><?= e($booking->serviceLabel()) ?></td>
                            <td><?= e($booking->patientName) ?></td>
                            <td>
                                <span class="badge badge-<?= e($booking->status->value) ?>">
                                    <?= e($booking->status->label()) ?>
                                </span>
                            </td>
                            <?php if ($canManage): ?>
                                <td class="row-actions">
                                    <?php if ($booking->status === \App\Domain\BookingStatus::Requested): ?>
                                        <form
                                            method="post"
                                            action="/bookings/<?= e(rawurlencode($booking->reference)) ?>/confirm"
                                            class="inline-form"
                                        >
                                            <?= \App\Security\Csrf::field() ?>
                                            <button class="button button-small" type="submit">Confirm</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!$booking->status->isTerminal()): ?>
                                        <form
                                            method="post"
                                            action="/bookings/<?= e(rawurlencode($booking->reference)) ?>/cancel"
                                            class="inline-form"
                                        >
                                            <?= \App\Security\Csrf::field() ?>
                                            <button class="button button-small button-secondary" type="submit">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
