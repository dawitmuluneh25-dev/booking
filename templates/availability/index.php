<?php
/**
 * @var string      $date
 * @var string      $dateLabel
 * @var string      $previous
 * @var string      $next
 * @var list<array{label: string, booking: \App\Domain\Booking|null, past: bool}> $rows
 * @var int         $freeCount
 * @var int         $bookedCount
 * @var \App\Domain\OpeningHours|null $hours
 * @var string      $timezone
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Availability</h1>
        <div class="day-nav">
            <a class="button button-secondary button-small" href="/availability?date=<?= e($previous) ?>">&larr; Previous</a>
            <a class="button button-secondary button-small" href="/availability?date=<?= e($next) ?>">Next &rarr;</a>
        </div>
    </div>

    <form method="get" action="/availability" class="day-form">
        <label for="date">Show day</label>
        <input id="date" name="date" type="date" value="<?= e($date) ?>" required>
        <button class="button button-small" type="submit">Go</button>
    </form>

    <p class="muted">
        <?= e($dateLabel) ?> · <?= e($hours === null ? 'Closed' : $hours->spans()) ?> (<?= e($timezone) ?>)
        <?php if ($hours !== null): ?>
            · <?= e($freeCount) ?> free · <?= e($bookedCount) ?> booked
        <?php endif; ?>
    </p>

    <?php if ($hours === null): ?>
        <p class="empty">
            The clinic is closed on this day. Change the weekly hours or a one-off closure under
            <a class="link" href="/admin/hours">opening hours</a>.
        </p>
    <?php elseif ($rows === []): ?>
        <p class="empty">No slots are defined for this day.</p>
    <?php else: ?>
        <ul class="slot-grid">
            <?php foreach ($rows as $row): ?>
                <?php
                $booking = $row['booking'];
                $state = $booking !== null ? 'booked' : ($row['past'] ? 'past' : 'free');
                ?>
                <li class="slot slot-<?= e($state) ?>">
                    <span class="slot-time"><?= e($row['label']) ?></span>

                    <?php if ($booking !== null): ?>
                        <a class="slot-ref" href="/bookings/<?= e(rawurlencode($booking->reference)) ?>">
                            <?= e($booking->reference) ?>
                        </a>
                        <span class="slot-meta"><?= e($booking->patientName) ?></span>
                        <span class="badge badge-<?= e($booking->status->value) ?>">
                            <?= e($booking->status->label()) ?>
                        </span>
                    <?php elseif ($row['past']): ?>
                        <span class="slot-meta">Past</span>
                    <?php else: ?>
                        <span class="slot-meta">Free</span>
                        <a class="slot-book" href="/bookings/new">Book</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="muted">
        This view is read-only. It shows the same slot rows the booking transaction
        claims, so the calendar cannot drift from what is actually reserved.
    </p>
</section>
