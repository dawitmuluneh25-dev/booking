<?php
/**
 * @var \App\Domain\Referral       $referral
 * @var \App\Domain\Procedure|null $procedure
 * @var array{type: string, rationale: string, reviewer: string, decidedAt: string, technique: ?string, modality: ?string, sedation: ?string, minutes: ?int}|null $decision
 * @var list<array{change: \App\Domain\ClinicalPriorityChange, changedBy: string, changedAt: string}> $priorityChanges
 * @var list<\App\Domain\Priority> $priorities
 * @var string|null                $notice
 * @var array<string, string>      $errors
 * @var \App\Domain\User           $currentUser
 */
$reassignable = in_array(
    $referral->status,
    [\App\Domain\ReferralStatus::ClinicallyApproved, \App\Domain\ReferralStatus::ApprovedForScheduling],
    true,
);
$mayReassign = $currentUser->can(\App\Domain\Permission::ClinicalApprove)
    && $referral->clinicalPriority !== null
    && $reassignable;
?>
<section class="panel">
    <div class="panel-head">
        <h1>Referral <?= e($referral->reference) ?></h1>
        <div class="panel-actions">
            <a class="button button-secondary button-small" href="/referrals">Queue</a>
            <a class="button button-secondary button-small"
               href="/referrals/<?= e(rawurlencode($referral->reference)) ?>/documents">Documents</a>
        </div>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>
                <?= $referral->status === \App\Domain\ReferralStatus::Draft
                    ? 'This draft is not complete yet.'
                    : 'That could not be recorded.' ?>
            </p>
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($referral->status === \App\Domain\ReferralStatus::Draft): ?>
        <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/submit" class="inline-form">
            <?= \App\Security\Csrf::field() ?>
            <button class="button" type="submit">Submit this referral</button>
            <span class="muted">Submitting sends it to the clinic and it cannot be edited afterwards.</span>
        </form>
    <?php endif; ?>

    <p class="status-line">
        <span class="badge"><?= e($referral->status->label()) ?></span>
        <span class="muted">Submitted <?= e(str_replace('T', ' ', substr($referral->createdAtUtc, 0, 19))) ?> UTC</span>
    </p>

    <p class="muted">
        This is a request for clinical review. It is not an appointment and no time has been
        reserved; scheduling happens only after clinical approval.
    </p>

    <?php if ($decision !== null): ?>
        <h2 class="section-title">Clinical decision</h2>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">The standing clinical decision</caption>
                <tbody>
                    <tr>
                        <th scope="row">Outcome</th>
                        <td><span class="badge badge-current"><?= e($decision['type']) ?></span></td>
                    </tr>
                    <tr>
                        <th scope="row">Rationale</th>
                        <td><?= nl2br(e($decision['rationale'])) ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Decided by</th>
                        <td><?= e($decision['reviewer']) ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Decided</th>
                        <td><?= e($decision['decidedAt']) ?></td>
                    </tr>
                    <?php if ($decision['technique'] !== null || $decision['modality'] !== null || $decision['sedation'] !== null || $decision['minutes'] !== null): ?>
                        <tr>
                            <th scope="row">Planned</th>
                            <td>
                                <?= e(implode(' · ', array_filter([
                                    $decision['technique'],
                                    $decision['modality'],
                                    $decision['sedation'],
                                    $decision['minutes'] === null ? null : $decision['minutes'] . ' minutes',
                                ]))) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($referral->status === \App\Domain\ReferralStatus::ClinicallyApproved
        && $currentUser->can(\App\Domain\Permission::ScheduleManage)): ?>
        <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/release" class="inline-form">
            <?= \App\Security\Csrf::field() ?>
            <button class="button" type="submit">Release for scheduling</button>
            <span class="muted">Moves the referral into the booking queue the desk works from.</span>
        </form>
    <?php elseif ($referral->status === \App\Domain\ReferralStatus::ApprovedForScheduling
        && $currentUser->can(\App\Domain\Permission::ScheduleManage)): ?>
        <p>
            <a class="button" href="/schedule/book?referral_id=<?= e((string) $referral->id) ?>">Book the appointment</a>
            <span class="muted">The referral is in the scheduling queue; booking it creates the appointment.</span>
        </p>
    <?php endif; ?>

    <?php
    $advancements = [
        'receive' => ['label' => 'Mark received', 'target' => \App\Domain\ReferralStatus::Received],
        'triage' => ['label' => 'Start administrative review', 'target' => \App\Domain\ReferralStatus::AdministrativeReview],
        'request-information' => ['label' => 'Request information', 'target' => \App\Domain\ReferralStatus::AwaitingInformation],
        'send-to-review' => ['label' => 'Send for clinical review', 'target' => \App\Domain\ReferralStatus::ClinicalReview],
    ];
    ?>
    <?php if ($currentUser->can(\App\Domain\Permission::ReferralTriage)): ?>
        <h2 class="section-title">Desk actions</h2>
        <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/advance" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="field">
                <label for="reason">Reason or what is missing</label>
                <input id="reason" name="reason" type="text" maxlength="480">
                <p class="muted">Required when asking the referrer for something.</p>
            </div>
            <div class="form-actions">
                <?php foreach ($advancements as $action => $advancement): ?>
                    <?php if ($referral->status->canTransitionTo($advancement['target'])): ?>
                        <button class="button button-secondary" type="submit" name="action" value="<?= e($action) ?>">
                            <?= e($advancement['label']) ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </form>

        <?php if ($referral->status === \App\Domain\ReferralStatus::AwaitingInformation): ?>
            <h2 class="section-title">Record the reply</h2>
            <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/reply" class="stack">
                <?= \App\Security\Csrf::field() ?>
                <div class="field">
                    <label for="reply">What the referrer sent back</label>
                    <textarea id="reply" name="reply" rows="3" maxlength="480"></textarea>
                </div>
                <button class="button" type="submit">Record reply</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <div class="table-wrap">
        <table>
            <caption class="visually-hidden">Referral details</caption>
            <tbody>
                <tr>
                    <th scope="row">Patient</th>
                    <td><?= e($referral->patientName) ?></td>
                </tr>
                <tr>
                    <th scope="row">Patient phone</th>
                    <td><?= e($referral->patientPhone) ?></td>
                </tr>
                <tr>
                    <th scope="row">Procedure</th>
                    <td>
                        <?= e($procedure?->label ?? $referral->procedureCode) ?>
                        <span class="muted">(<?= e($referral->procedureCode) ?>)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Requested urgency</th>
                    <td>
                        <?= e($referral->requestedUrgencyLabel()) ?>
                        <span class="muted">(the submitter's request, not a clinical decision)</span>
                    </td>
                </tr>
                <?php if ($referral->clinicalPriority !== null): ?>
                    <tr>
                        <th scope="row">Clinical priority</th>
                        <td>
                            <?= e((string) $referral->clinicalPriorityLabel()) ?>
                            <?php if ($referral->requiresDirectCoordination()): ?>
                                <span class="muted">
                                    — direct coordination with the receiving team is required;
                                    this case does not wait in the ordinary queue.
                                </span>
                            <?php endif; ?>
                            <?php if ($priorityChanges !== []): ?>
                                <span class="muted block">
                                    Changed <?= e((string) count($priorityChanges)) ?>
                                    time<?= count($priorityChanges) === 1 ? '' : 's' ?> since it was
                                    assigned — see below.
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row">Target site</th>
                    <td><?= e($referral->targetSite ?? '—') ?></td>
                </tr>
                <tr>
                    <th scope="row">Referring clinician</th>
                    <td>
                        <?= e($referral->referringPhysicianName ?? '—') ?>
                        <?php if ($referral->referringFacility !== null): ?>
                            <span class="muted">· <?= e($referral->referringFacility) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Clinical summary</th>
                    <td><?= nl2br(e($referral->clinicalSummary ?? '—')) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if ($priorityChanges !== []): ?>
        <h2 class="section-title">Priority history</h2>
        <p class="muted">
            A change of priority is recorded with both categories and the reason. Nothing here is
            edited: the earlier category stays visible, because a referral that was routine last week
            and is expedited today is a fact somebody may later need to account for.
        </p>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Recorded changes of clinical priority</caption>
                <thead>
                    <tr>
                        <th scope="col">From</th>
                        <th scope="col">To</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Changed by</th>
                        <th scope="col">Changed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($priorityChanges as $row): ?>
                        <tr>
                            <th scope="row"><?= e($row['change']->fromLabel() ?? '—') ?></th>
                            <td><?= e($row['change']->toLabel()) ?></td>
                            <td><?= nl2br(e($row['change']->reason)) ?></td>
                            <td><?= e($row['changedBy']) ?></td>
                            <td><?= e($row['changedAt']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($mayReassign): ?>
        <h2 class="section-title">Change the clinical priority</h2>
        <p class="muted">
            This referral is already in the elective queue at
            &ldquo;<?= e((string) $referral->clinicalPriorityLabel()) ?>&rdquo;. Changing the category
            reorders its work; it does not book anything, and it is recorded against your name with
            the reason. <strong>Urgent direct coordination is not offered</strong> — it says the queue
            is the wrong route, so it is a redirect rather than a change of position.
        </p>
        <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/priority" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="field">
                <label for="clinical_priority">New priority</label>
                <select id="clinical_priority" name="clinical_priority" required
                    <?= isset($errors['clinical_priority'])
                        ? 'aria-describedby="clinical_priority-error" aria-invalid="true"'
                        : '' ?>>
                    <option value="">— Choose —</option>
                    <?php foreach ($priorities as $priority): ?>
                        <?php if ($priority->leavesTheOrdinaryQueue()) {
                            continue;
                        } ?>
                        <option value="<?= e($priority->value) ?>"><?= e($priority->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['clinical_priority'])): ?>
                    <p class="field-error" id="clinical_priority-error"><?= e($errors['clinical_priority']) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="reason">Why the category is changing</label>
                <textarea id="reason" name="reason" rows="3" minlength="10" maxlength="4000" required
                    <?= isset($errors['reason'])
                        ? 'aria-describedby="reason-error" aria-invalid="true"'
                        : '' ?>></textarea>
                <?php if (isset($errors['reason'])): ?>
                    <p class="field-error" id="reason-error"><?= e($errors['reason']) ?></p>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <button class="button" type="submit">Change priority</button>
            </div>
        </form>
    <?php elseif ($referral->clinicalPriority !== null && !$reassignable): ?>
        <p class="muted">
            The clinical priority can be changed while the referral is approved for scheduling. It is
            now at &ldquo;<?= e($referral->status->label()) ?>&rdquo;, so a change would be a scheduling
            decision rather than a queue ordering one.
        </p>
    <?php endif; ?>
</section>
