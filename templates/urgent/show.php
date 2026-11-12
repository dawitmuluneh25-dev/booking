<?php
/**
 * One urgent case: the facts, the four acts, and everything that has happened.
 *
 * The forms are shown or hidden from the same transition tables the domain refuses
 * with, so a hidden form is never the only thing preventing an action.
 *
 * @var \App\Domain\UrgentCase $case
 * @var string                 $receivedAt
 * @var list<array{entry: \App\Domain\UrgentTimelineEntry, at: string}> $timeline
 * @var array<string, string>  $errors
 * @var string|null            $formError
 * @var string|null            $notice
 * @var array<string, string>  $values
 * @var bool                   $mayAcknowledge
 * @var bool                   $mayEscalate
 * @var bool                   $mayClose
 * @var \App\Domain\User       $currentUser
 */
$error = static fn (string $field): ?string => $errors[$field] ?? null;
$errorId = static fn (string $field): string => $field . '-error';

$acknowledged = $case->coordination !== \App\Domain\UrgentCoordination::Unacknowledged;
$resolved = $case->coordination === \App\Domain\UrgentCoordination::Resolved;
$closed = $case->urgency === \App\Domain\UrgentStatus::Closed;
?>
<section class="panel">
    <div class="panel-head">
        <h1>Urgent case <?= e($case->reference) ?></h1>
        <a class="button button-secondary button-small" href="/urgent-cases">Board</a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><p><?= e($notice) ?></p></div>
    <?php endif; ?>

    <?php if ($formError !== null): ?>
        <div class="alert alert-error" role="alert"><p><?= e($formError) ?></p></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That was not recorded. Correct the marked fields.</p>
            <ul>
                <?php foreach ($errors as $field => $message): ?>
                    <li><a href="#<?= e((string) $field) ?>"><?= e($message) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="alert alert-warning" role="note">
        <?php if (!$acknowledged): ?>
            <strong>No acknowledgement has been recorded.</strong> The referring team must keep
            contacting the treating hospital directly. Nothing on this page tells them the case
            has been picked up, and a recorded case is not an accepted case.
        <?php elseif (!$closed): ?>
            Acknowledged by a named person. That is not the same as a clinical decision, and it is
            not acceptance: the receiving clinician records the decision and the next responsible
            team.
        <?php else: ?>
            This case is closed. The outcome below is the record of what happened; it is not a
            discharge instruction.
        <?php endif; ?>
    </p>

    <div class="table-wrap">
        <table>
            <caption class="visually-hidden">Case details</caption>
            <tbody>
                <tr>
                    <th scope="row">Patient</th>
                    <td>
                        <?= e($case->patientName) ?>
                        <span class="muted block">
                            <?= e($case->patientAgeMonths !== null
                                ? $case->patientAgeMonths . ' months'
                                : ('Born ' . ($case->patientDob ?? 'unknown'))) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Guardian</th>
                    <td>
                        <?= e($case->guardianName ?? 'Not recorded') ?>
                        <span class="muted block">
                            <?= e($case->guardianPhone) ?><?= $case->guardianRelationship === null
                                ? ''
                                : ' · ' . e($case->guardianRelationship) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Referring</th>
                    <td>
                        <?= e($case->referringPhysicianName ?? 'Not recorded') ?>
                        <span class="muted block">
                            <?= e($case->referringFacility ?? 'Facility not recorded') ?><?= $case->referringPhysicianPhone === null
                                ? ''
                                : ' · ' . e($case->referringPhysicianPhone) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Receiving hospital</th>
                    <td><?= e($case->receivingHospitalName ?? '—') ?></td>
                </tr>
                <tr>
                    <th scope="row">Clinical context</th>
                    <td><?= nl2br(e($case->clinicalSummary)) ?></td>
                </tr>
                <tr>
                    <th scope="row">Imaging</th>
                    <td><?= $case->imagingSummary === null ? '—' : nl2br(e($case->imagingSummary)) ?></td>
                </tr>
                <tr>
                    <th scope="row">Clinical status</th>
                    <td><span class="badge"><?= e($case->urgency->label()) ?></span></td>
                </tr>
                <tr>
                    <th scope="row">Coordination</th>
                    <td><span class="badge"><?= e($case->coordination->label()) ?></span></td>
                </tr>
                <tr>
                    <th scope="row">Received</th>
                    <td><?= e($receivedAt) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if ($mayAcknowledge && !$acknowledged): ?>
        <h2 class="section-title">Acknowledge</h2>
        <p class="muted">
            Acknowledging says you have seen this case and are acting on it. It is recorded
            against your name, it cannot be produced by a notification, and it does not accept
            the case clinically.
        </p>
        <form method="post" action="/urgent-cases/<?= e(rawurlencode($case->reference)) ?>/acknowledge" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="grid">
                <div class="field">
                    <label for="ack_channel">How you saw it</label>
                    <select id="ack_channel" name="channel">
                        <?php foreach (['application', 'telephone', 'in_person', 'other'] as $channel): ?>
                            <option value="<?= e($channel) ?>"<?= $channel === 'application' ? ' selected' : '' ?>>
                                <?= e($channel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="ack_note">Note (optional)</label>
                    <input id="ack_note" name="note" type="text" maxlength="1000">
                </div>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">Acknowledge case</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($mayAcknowledge && $acknowledged && !$resolved && !$closed): ?>
        <h2 class="section-title">Record a contact attempt</h2>
        <p class="muted">
            Record every attempt, including the ones that failed. A telephone attempt needs the
            number that was dialled, and a call that reached somebody needs their name.
        </p>
        <form method="post" action="/urgent-cases/<?= e(rawurlencode($case->reference)) ?>/contact" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="grid">
                <div class="field">
                    <label for="contact_role">Who you tried to reach</label>
                    <select id="contact_role" name="contact_role">
                        <?php foreach (\App\Domain\UrgentContactAttempt::ROLES as $role): ?>
                            <option value="<?= e($role) ?>"><?= e($role) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="contact_channel">Channel</label>
                    <select id="contact_channel" name="channel">
                        <?php foreach (\App\Domain\UrgentContactAttempt::CHANNELS as $channel): ?>
                            <option value="<?= e($channel) ?>"><?= e($channel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="outcome">Outcome</label>
                    <select id="outcome" name="outcome">
                        <?php foreach (\App\Domain\UrgentContactAttempt::OUTCOMES as $outcome): ?>
                            <option value="<?= e($outcome) ?>"><?= e($outcome) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="contact_phone">Number dialled</label>
                    <input id="contact_phone" name="contact_phone" type="tel" maxlength="32"
                           value="<?= e($case->referringPhysicianPhone ?? $case->guardianPhone) ?>">
                </div>
                <div class="field">
                    <label for="contact_name">Who you spoke to</label>
                    <input id="contact_name" name="contact_name" type="text" maxlength="120">
                </div>
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="2" maxlength="2000"></textarea>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">Record attempt</button>
            </div>
        </form>
    <?php endif; ?>

    <h2 class="section-title">Escalation</h2>
    <p class="muted">
        <strong>No escalation policy is configured.</strong> No interval, level ladder or
        after-hours route has been approved (<span class="mono">LP-020</span>), so the level below
        is your judgement and is stored with your name and your reason — not chosen by the system.
    </p>
    <?php if ($mayEscalate && !$resolved && !$closed): ?>
        <form method="post" action="/urgent-cases/<?= e(rawurlencode($case->reference)) ?>/escalate" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="grid">
                <div class="field">
                    <label for="level">Level (1–5)</label>
                    <input id="level" name="level" type="number" min="1" max="5" step="1" value="1">
                </div>
            </div>
            <div class="field">
                <label for="reason">Why this is being escalated</label>
                <textarea id="reason" name="reason" rows="2" minlength="10" maxlength="4000" required></textarea>
            </div>
            <div class="form-actions">
                <button class="button button-secondary" type="submit">Escalate</button>
            </div>
        </form>
    <?php else: ?>
        <p class="muted">You can see this case but not escalate it.</p>
    <?php endif; ?>

    <?php if ($mayClose): ?>
        <h2 class="section-title">Close the case</h2>
        <p class="muted">
            A case stays open until accountable handover is documented. The note is the record of
            what happened to the patient — performed, transferred, declined, or otherwise — and it
            is required.
        </p>
        <form method="post" action="/urgent-cases/<?= e(rawurlencode($case->reference)) ?>/close" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="field">
                <label for="resolution_note">Outcome</label>
                <textarea id="resolution_note" name="resolution_note" rows="3" minlength="10"
                          maxlength="4000" required
                          <?= $error('resolution_note') === null
                              ? ''
                              : ' aria-describedby="' . e($errorId('resolution_note')) . '" aria-invalid="true"' ?>></textarea>
                <?php if ($error('resolution_note') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('resolution_note')) ?>"><?= e((string) $error('resolution_note')) ?></p>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">Close case</button>
            </div>
        </form>
    <?php endif; ?>

    <h2 class="section-title">What has happened</h2>
    <?php if ($timeline === []): ?>
        <p class="empty">Nothing has been recorded against this case beyond its arrival.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Coordination timeline, oldest first</caption>
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">What</th>
                        <th scope="col">By</th>
                        <th scope="col">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timeline as $row): ?>
                        <tr>
                            <td><?= e($row['at']) ?></td>
                            <th scope="row"><?= e($row['entry']->description()) ?></th>
                            <td><?= e($row['entry']->actorName) ?></td>
                            <td><?= $row['entry']->detail === null ? '—' : nl2br(e($row['entry']->detail)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
