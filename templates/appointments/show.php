<?php

/**
 * One appointment: the facts, the day's timeline, and the record form.
 *
 * The form is built from the same two rules the command enforces — the actor's
 * permission for the kind, and the state the appointment is in — so a hidden option
 * is never the only thing preventing an action. Where nothing can be recorded, the
 * page says which rule is in the way instead of showing an empty form.
 *
 * @var \App\Domain\Appointment $appointment
 * @var \App\Domain\Referral|null $referral
 * @var list<array{name: string, modality: string|null, role: string}> $resources
 * @var list<array{
 *     at: string,
 *     label: string,
 *     by: string,
 *     detail: string|null,
 *     milestone: bool,
 *     outOfOrder: bool
 * }> $timeline
 * @var list<\App\Domain\ProcedureMilestoneRecord> $milestones
 * @var list<\App\Domain\ReferralDocument> $documents
 * @var \App\Domain\ProcedureConsent|null $consent
 * @var list<\App\Domain\ProcedureMilestone> $recordable
 * @var string $timezone
 * @var array<string, string> $fieldErrors
 * @var string|null $formError
 * @var string|null $notice
 * @var \App\Domain\User $currentUser
 */
$clinicZone = new DateTimeZone($timezone);
$clinic = static function (string $utc, string $format = 'H:i') use ($clinicZone): string {
    $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

    return $moment === false ? $utc : $moment->setTimezone($clinicZone)->format($format);
};

$error = static fn (string $field): ?string => $fieldErrors[$field] ?? null;
$attr = static function (string $field) use ($fieldErrors): string {
    return isset($fieldErrors[$field])
        ? 'aria-describedby="' . e($field . '-error') . '" aria-invalid="true"'
        : '';
};
?>
<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Appointment · <?= e($timezone) ?></p>
            <h1><?= e($appointment->reference) ?></h1>
        </div>
        <a class="button button-secondary button-small" href="/appointments">Today</a>
    </div>

    <?php if ($notice !== null): ?>
        <p class="alert alert-success" role="status"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($formError !== null): ?>
        <p class="alert alert-error" role="alert"><?= e($formError) ?></p>
    <?php endif; ?>

    <?php if ($fieldErrors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That was not recorded. Correct the marked field.</p>
            <ul>
                <?php foreach ($fieldErrors as $field => $message): ?>
                    <li><a href="#<?= e((string) $field) ?>"><?= e($message) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <caption class="visually-hidden">Appointment details</caption>
            <tbody>
                <tr>
                    <th scope="row">Patient</th>
                    <td>
                        <?= e($appointment->patientName) ?>
                        <span class="muted block"><?= e($appointment->patientPhone) ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Time</th>
                    <td>
                        <?= e($clinic($appointment->startsAtUtc, 'l j F Y, H:i')) ?>–<?= e($clinic($appointment->endsAtUtc, 'H:i')) ?>
                        <span class="muted block"><?= e($appointment->durationMinutes) ?> minutes · clinic time</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Room state</th>
                    <td><?= e($appointment->status->label()) ?></td>
                </tr>
                <tr>
                    <th scope="row">Resources</th>
                    <td>
                        <?php if ($resources === []): ?>
                            <span class="muted">No resource recorded against this appointment.</span>
                        <?php else: ?>
                            <?php foreach ($resources as $resource): ?>
                                <span class="block">
                                    <?= e($resource['name']) ?>
                                    <span class="muted">
                                        <?= e($resource['role']) ?><?= $resource['modality'] === null
                                            ? ''
                                            : ' · ' . e($resource['modality']) ?>
                                    </span>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Referral</th>
                    <td>
                        <?php if ($referral === null): ?>
                            <span class="muted">
                                Not linked to an elective referral. An urgent case gets an
                                appointment without one.
                            </span>
                        <?php else: ?>
                            <a class="link" href="/referrals/<?= e(rawurlencode($referral->reference)) ?>">
                                <?= e($referral->reference) ?>
                            </a>
                            <span class="muted block">
                                <?= e($referral->procedureCode) ?> · <?= e($referral->status->label()) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Consent</th>
                    <td>
                        <?php if ($consent === null): ?>
                            <span class="muted">
                                No consent discussion is recorded for this referral. A consent
                                check at the point of care has nothing to verify until there is one.
                            </span>
                        <?php else: ?>
                            <?= e($consent->standingLabel()) ?>
                            <span class="muted block">
                                Recorded <?= e($clinic($consent->consentedAtUtc, 'j F Y')) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>What happened</h2>

    <?php if ($timeline === []): ?>
        <p class="muted">
            Nothing has been recorded for this appointment yet. Booking it is the last thing
            that happened.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Procedure day timeline, oldest first</caption>
                <thead>
                <tr>
                    <th scope="col">Time (<?= e($timezone) ?>)</th>
                    <th scope="col">Event</th>
                    <th scope="col">Recorded by</th>
                    <th scope="col">Note</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($timeline as $entry): ?>
                    <tr>
                        <td><?= e($clinic($entry['at'])) ?></td>
                        <td>
                            <?= e($entry['label']) ?>
                            <?php if ($entry['outOfOrder']): ?>
                                <span class="muted block">Recorded out of sequence</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($entry['by']) ?></td>
                        <td>
                            <?php if ($entry['detail'] === null): ?>
                                <span class="muted">—</span>
                            <?php else: ?>
                                <?= e($entry['detail']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="muted">
        A room-state change appears here only when no recorded event explains it, so the
        same minute is never printed twice. Cancellations and deferrals have no event of
        their own and always appear.
    </p>
</section>

<section class="panel">
    <h2>Record an event</h2>

    <?php if ($recordable === []): ?>
        <p class="muted">
            Nothing can be recorded from this screen right now.
            The appointment is <strong><?= e($appointment->status->label()) ?></strong>, and the
            events left in the day are not reachable from that state — or your role may not
            record them. Reception records arrival; procedure coordination records the rest.
        </p>
    <?php else: ?>
        <form method="post" action="/appointments/<?= e(rawurlencode($appointment->reference)) ?>/milestones">
            <?= \App\Security\Csrf::field() ?>

            <label for="kind">Event</label>
            <select id="kind" name="kind" required <?= $attr('kind') ?>>
                <?php foreach ($recordable as $kind): ?>
                    <option value="<?= e($kind->value) ?>"><?= e($kind->label()) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($error('kind') !== null): ?>
                <p class="field-error" id="kind-error"><?= e((string) $error('kind')) ?></p>
            <?php endif; ?>

            <label for="note">Note (optional)</label>
            <textarea id="note" name="note" rows="3" maxlength="500" <?= $attr('note') ?>></textarea>
            <?php if ($error('note') !== null): ?>
                <p class="field-error" id="note-error"><?= e((string) $error('note')) ?></p>
            <?php endif; ?>

            <label for="out_of_order_reason">Reason, if this is out of sequence</label>
            <textarea id="out_of_order_reason" name="out_of_order_reason" rows="2" maxlength="255" <?= $attr('out_of_order_reason') ?>></textarea>
            <p class="muted">
                Required only when an earlier event is missing — for example a procedure
                started before arrival was recorded. At least ten characters. It is stored
                with the record and shown on the timeline.
            </p>
            <?php if ($error('out_of_order_reason') !== null): ?>
                <p class="field-error" id="out_of_order_reason-error"><?= e((string) $error('out_of_order_reason')) ?></p>
            <?php endif; ?>

            <button class="button" type="submit">Record</button>
        </form>
    <?php endif; ?>

    <p class="alert alert-warning" role="note">
        <strong>Recording an event is not a readiness decision.</strong>
        The pre-procedure readiness checklist (§14) is not configured in this release, so
        nothing on this page certifies that the room, the equipment, the staff or the
        specimen pathway are ready. That is BL-D2, and it is a separate slice.
    </p>
</section>

<section class="panel">
    <h2>Documents</h2>

    <?php if ($referral === null): ?>
        <p class="muted">Documents are held against a referral, and this appointment has none.</p>
    <?php elseif ($documents === []): ?>
        <p class="muted">No documents are attached to this referral.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Documents attached to the referral</caption>
                <thead>
                <tr>
                    <th scope="col">File</th>
                    <th scope="col">Kind</th>
                    <th scope="col">Uploaded</th>
                    <th scope="col">Scan</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td><?= e($document->originalName) ?></td>
                        <td><?= e($document->kind->label()) ?></td>
                        <td><?= e($clinic($document->createdAtUtc, 'j F Y H:i')) ?></td>
                        <td><?= e($document->scanStatus->label()) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">
            Opening a file is a separate, recorded act on the referral page. This list shows
            what exists, not what it contains.
        </p>
    <?php endif; ?>
</section>
