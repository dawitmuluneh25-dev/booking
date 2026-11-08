<?php
/**
 * @var list<\App\Domain\BookingEvent> $events
 * @var list<\App\Domain\RetentionRun> $runs
 * @var int                            $total
 * @var string                         $reference
 * @var bool                           $unavailable
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Audit trail</h1>
        <a class="button button-secondary button-small" href="/admin">Back</a>
    </div>

    <?php if ($unavailable): ?>
        <div class="alert alert-error" role="alert">The audit trail cannot be read right now.</div>
    <?php else: ?>
        <form method="get" action="/admin/audit" class="day-form">
            <label for="reference">Booking reference</label>
            <input id="reference" name="reference" type="search" value="<?= e($reference) ?>"
                   placeholder="BK-…" autocomplete="off">
            <button class="button button-small" type="submit">Filter</button>
            <?php if ($reference !== ''): ?>
                <a class="link" href="/admin/audit">Clear</a>
            <?php endif; ?>
        </form>

        <p class="muted">
            <?= e($total) ?> event<?= $total === 1 ? '' : 's' ?> recorded<?= $reference === '' ? '' : ' for ' . e($reference) ?>.
            Events are append-only: a correction is another event, never an edit.
        </p>

        <?php if ($events === []): ?>
            <p class="empty">No lifecycle events match this filter.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Booking lifecycle events</caption>
                    <thead>
                        <tr>
                            <th scope="col">When (UTC)</th>
                            <th scope="col">Booking</th>
                            <th scope="col">From</th>
                            <th scope="col">To</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= e(str_replace('T', ' ', substr($event->createdAtUtc, 0, 19))) ?></td>
                                <td>
                                    <a href="/bookings/<?= e(rawurlencode($event->reference)) ?>">
                                        <?= e($event->reference) ?>
                                    </a>
                                </td>
                                <td><?= e($event->fromStatus) ?></td>
                                <td><?= e($event->toStatus) ?></td>
                                <td><?= e($event->actor) ?></td>
                                <td class="muted"><?= e($event->reason ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Retention sweeps</h2>
        <?php if ($runs === []): ?>
            <p class="empty">
                No sweep has run. Check the plan with <code>php bin/console retention:plan</code>.
            </p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Executed retention sweeps</caption>
                    <thead>
                        <tr>
                            <th scope="col">Ran (UTC)</th>
                            <th scope="col">Cutoff</th>
                            <th scope="col">Bookings redacted</th>
                            <th scope="col">Actor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $run): ?>
                            <tr>
                                <td><?= e(str_replace('T', ' ', substr($run->ranAtUtc, 0, 19))) ?></td>
                                <td><?= e($run->cutoffDate) ?></td>
                                <td><?= e($run->bookingsRedacted) ?></td>
                                <td><?= e($run->actor) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
