<?php

/**
 * The day's list: what is booked, where each case has got to, and what the next
 * expected event is.
 *
 * @var string $date
 * @var string $dateLabel
 * @var list<array{
 *     appointment: \App\Domain\Appointment,
 *     recorded: int,
 *     next: \App\Domain\ProcedureMilestone|null,
 *     outOfOrder: int
 * }> $rows
 * @var string $timezone
 * @var string|null $notice
 * @var string|null $error
 * @var \App\Domain\User $currentUser
 */
$clinicZone = new DateTimeZone($timezone);
$clinicTime = static function (string $utc) use ($clinicZone): string {
    $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

    return $moment === false ? $utc : $moment->setTimezone($clinicZone)->format('H:i');
};

$previous = (new DateTimeImmutable($date, $clinicZone))->modify('-1 day');
$next = (new DateTimeImmutable($date, $clinicZone))->modify('+1 day');
?>
<section class="panel">
    <p class="eyebrow">Procedure day · <?= e($timezone) ?></p>
    <h1><?= e($dateLabel) ?></h1>

    <?php if ($notice !== null): ?>
        <p class="alert alert-success" role="status"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <div class="toolbar">
        <a class="button button-secondary" href="/appointments?date=<?= e($previous->format('Y-m-d')) ?>">Previous day</a>
        <a class="button button-secondary" href="/appointments?date=<?= e($next->format('Y-m-d')) ?>">Next day</a>
        <a class="button button-secondary" href="/appointments">Today</a>
        <a class="button button-secondary" href="/schedule?date=<?= e($date) ?>">Room schedule</a>
    </div>

    <?php if ($rows === []): ?>
        <p class="muted">
            Nothing is booked for this day. A cancelled or missed case is not a procedure
            and does not appear here.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Procedures booked on <?= e($dateLabel) ?></caption>
                <thead>
                <tr>
                    <th scope="col">Time (<?= e($timezone) ?>)</th>
                    <th scope="col">Reference</th>
                    <th scope="col">Patient</th>
                    <th scope="col">Room state</th>
                    <th scope="col">Recorded</th>
                    <th scope="col">Next expected</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($clinicTime($row['appointment']->startsAtUtc)) ?>–<?= e($clinicTime($row['appointment']->endsAtUtc)) ?></td>
                        <td><?= e($row['appointment']->reference) ?></td>
                        <td><?= e($row['appointment']->patientName) ?></td>
                        <td><?= e($row['appointment']->status->label()) ?></td>
                        <td>
                            <?= e($row['recorded']) ?> of <?= e(count(\App\Domain\ProcedureMilestone::cases())) ?>
                            <?php if ($row['outOfOrder'] > 0): ?>
                                <span class="muted block">
                                    <?= e($row['outOfOrder']) ?> recorded out of sequence
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['next'] === null): ?>
                                <span class="muted">Nothing outstanding</span>
                            <?php else: ?>
                                <?= e($row['next']->label()) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="link" href="/appointments/<?= e(rawurlencode($row['appointment']->reference)) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="alert alert-warning" role="note">
        <strong>This list records what happened; it does not certify that a case is ready.</strong>
        The pre-procedure readiness checklist (§14 — its items, owners, versions and stop
        classifications) is not configured in this release. Until it is, nothing here says a
        case is fit to proceed, and a missing event on this page is not a decision about the
        patient.
    </p>
</section>
