<?php

/**
 * @var string $date
 * @var string $dateLabel
 * @var string $previous
 * @var string $next
 * @var int    $duration
 * @var list<array{resource: \App\Domain\Resource, appointments: list<\App\Domain\Appointment>, freeCount: int}> $rows
 * @var string $timezone
 * @var string|null $notice
 * @var string|null $error
 * @var array<string, string> $fieldErrors
 * @var bool $canBook
 * @var bool $canCancel
 */

/*
 * Stored in UTC, shown on the clinic's clock.
 *
 * The column is labelled with the zone it is actually rendering in, so a 09:00
 * appointment reads 09:00 here exactly as it does on the availability page and in
 * the booking wizard. Printing raw UTC under a "Time (UTC)" heading was accurate
 * and unreadable: the desk would see 06:00 for a nine o'clock case.
 */
$clinicZone = new DateTimeZone($timezone);
$clinicTime = static function (string $utc) use ($clinicZone): string {
    $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

    return $moment === false ? $utc : $moment->setTimezone($clinicZone)->format('H:i');
};
?>
<section class="panel">
    <p class="eyebrow">Reception calendar · <?= e($timezone) ?></p>
    <h1><?= e($dateLabel) ?></h1>

    <?php if ($notice !== null): ?>
        <p class="alert alert-success" role="status"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($fieldErrors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($fieldErrors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="toolbar">
        <a class="button button-secondary" href="/schedule?date=<?= e($previous) ?>&duration_minutes=<?= e($duration) ?>">Previous day</a>
        <a class="button button-secondary" href="/schedule?date=<?= e($next) ?>&duration_minutes=<?= e($duration) ?>">Next day</a>

        <form method="get" action="/schedule" class="inline-form">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <label for="duration">Slot length</label>
            <select id="duration" name="duration_minutes">
                <?php foreach ([15, 30, 45, 60, 90, 120, 180, 240] as $minutes): ?>
                    <option value="<?= e($minutes) ?>"<?= $minutes === $duration ? ' selected' : '' ?>>
                        <?= e($minutes) ?> min
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="button button-secondary" type="submit">Show</button>
        </form>

        <?php if ($canBook): ?>
            <a class="button" href="/schedule/book?date=<?= e($date) ?>&duration_minutes=<?= e($duration) ?>">Book</a>
        <?php endif; ?>
    </div>

    <?php foreach ($rows as $row): ?>
        <h2><?= e($row['resource']->name) ?> <span class="muted"><?= e($row['resource']->modality ?? '') ?></span></h2>

        <?php if ($row['appointments'] === []): ?>
            <p class="muted">Nothing booked. <?= e($row['freeCount']) ?> slot(s) of <?= e($duration) ?> minutes are free.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Appointments in <?= e($row['resource']->name) ?></caption>
                    <thead>
                    <tr>
                        <th scope="col">Time (<?= e($timezone) ?>)</th>
                        <th scope="col">Reference</th>
                        <th scope="col">Patient</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($row['appointments'] as $appointment): ?>
                        <tr>
                            <td>
                                <?= e($clinicTime($appointment->startsAtUtc)) ?>–<?= e($clinicTime($appointment->endsAtUtc)) ?>
                            </td>
                            <td><?= e($appointment->reference) ?></td>
                            <td><?= e($appointment->patientName) ?></td>
                            <td><?= e($appointment->status->label()) ?></td>
                            <td>
                                <?php if ($canBook): ?>
                                    <a class="link" href="/schedule/<?= e(rawurlencode($appointment->reference)) ?>/move?date=<?= e($date) ?>">Move</a>
                                <?php endif; ?>

                                <?php if ($canCancel): ?>
                                    <form method="post" action="/schedule/<?= e(rawurlencode($appointment->reference)) ?>/cancel" class="inline-form">
                                        <?= \App\Security\Csrf::field() ?>
                                        <input type="hidden" name="date" value="<?= e($date) ?>">
                                        <label for="reason-<?= e($appointment->reference) ?>">Reason</label>
                                        <input id="reason-<?= e($appointment->reference) ?>" name="reason" maxlength="255" required>
                                        <button class="button button-secondary" type="submit">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted"><?= e($row['freeCount']) ?> slot(s) of <?= e($duration) ?> minutes still free.</p>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($rows === []): ?>
        <p class="muted">
            No active rooms or suites. Add one in the database, or activate an existing resource.
        </p>
    <?php endif; ?>
</section>
