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
 * @var \App\Domain\ProcedureConsent|null $consent
 * @var list<array{consent: \App\Domain\ProcedureConsent, recordedBy: string}> $consentHistory
 * @var list<\App\Domain\ConsentParticipant> $consentParticipants
 * @var list<\App\Domain\ConsentOutcome>     $consentOutcomes
 */
$reassignable = in_array(
    $referral->status,
    [\App\Domain\ReferralStatus::ClinicallyApproved, \App\Domain\ReferralStatus::ApprovedForScheduling],
    true,
);
$mayReassign = $currentUser->can(\App\Domain\Permission::ClinicalApprove)
    && $referral->clinicalPriority !== null
    && $reassignable;

// Consent is recorded after the clinical decision and before the procedure. The
// command refuses anything earlier; the template asks the same question so the form
// is never offered where submitting it would fail.
$consentable = in_array(
    $referral->status,
    [
        \App\Domain\ReferralStatus::ClinicallyApproved,
        \App\Domain\ReferralStatus::ApprovedForScheduling,
        \App\Domain\ReferralStatus::ConvertedToAppointment,
    ],
    true,
);
$mayRecordConsent = $currentUser->can(\App\Domain\Permission::ClinicalApprove) && $consentable;
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
                <?php if ($referral->patientAge() !== null): ?>
                    <tr>
                        <th scope="row">Age</th>
                        <td><?= e($referral->patientAge()->describeWithSource()) ?></td>
                    </tr>
                <?php endif; ?>
                <?php /*
                 * §6.3: an age recorded alongside a date of birth is flagged for
                 * reconciliation when the two disagree. The comparison is arithmetic
                 * against the unit's own span, so it states a conflict rather than
                 * deciding which of the two is right — that is a person's job.
                 */ ?>
                <?php if ($referral->patientAge()?->disagreesWithDateOfBirth($referral->patientDob, $referral->createdAtUtc) === true): ?>
                    <tr>
                        <th scope="row">Age and date of birth</th>
                        <td>
                            <strong>These disagree.</strong>
                            <span class="muted">
                                The date of birth does not match the recorded age
                                (<?= e($referral->patientAge()->describe()) ?>), which was stated when
                                the request was taken. Reconcile before relying on either.
                            </span>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($referral->patientIdentifier !== null): ?>
                    <tr>
                        <th scope="row">Reference number</th>
                        <td>
                            <?= e($referral->patientIdentifier) ?>
                            <span class="muted">
                                <?= e($referral->identifierType()?->label() ?? 'Unclassified') ?>
                                <?php if ($referral->patientIdentifierFacility !== null): ?>
                                    &middot; issued by <?= e($referral->patientIdentifierFacility) ?>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($referral->patientEmail !== null): ?>
                    <tr>
                        <th scope="row">Patient email</th>
                        <td><?= e($referral->patientEmail) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($referral->preferredLanguage !== null): ?>
                    <tr>
                        <th scope="row">Preferred language</th>
                        <td><?= e($referral->preferredLanguage) ?></td>
                    </tr>
                <?php endif; ?>
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
                        <?php if ($referral->referringPhysicianCredentials !== null): ?>
                            <span class="muted">, <?= e($referral->referringPhysicianCredentials) ?></span>
                        <?php endif; ?>
                        <?php if ($referral->referringFacility !== null): ?>
                            <span class="muted">· <?= e($referral->referringFacility) ?></span>
                        <?php endif; ?>
                        <?php if ($referral->referringDepartment !== null): ?>
                            <span class="muted">· <?= e($referral->referringDepartment) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Clinical summary</th>
                    <td><?= nl2br(e($referral->clinicalSummary ?? '—')) ?></td>
                </tr>
                <tr>
                    <th scope="row">Clinical question</th>
                    <td>
                        <?php if ($referral->clinicalQuestion !== null): ?>
                            <?= nl2br(e($referral->clinicalQuestion)) ?>
                        <?php else: ?>
                            <?php /*
                             * §6.4 makes this mandatory for a clinician referral and
                             * conditional otherwise, so an empty one is a fact worth
                             * stating rather than a blank cell.
                             */ ?>
                            <span class="muted">Not stated. A clinician referral should say what the procedure is meant to answer.</span>
                        <?php endif; ?>
                    </td>
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

<?php
/**
 * @var \App\Domain\ClinicalAssessment|null $assessment
 * @var list<array{assessment: \App\Domain\ClinicalAssessment, recordedBy: string}> $assessmentHistory
 * @var list<\App\Domain\AnticoagulantStatus> $anticoagulantStatuses
 * @var list<\App\Domain\AllergyStatus>       $allergyStatuses
 * @var list<\App\Domain\PregnancyStatus>     $pregnancyStatuses
 * @var list<\App\Domain\ImagingModality>     $imagingModalities
 */
$mayAssess = $currentUser->can(\App\Domain\Permission::ClinicalReview)
    && !in_array($referral->status, [
        \App\Domain\ReferralStatus::Draft,
        \App\Domain\ReferralStatus::Declined,
        \App\Domain\ReferralStatus::Cancelled,
        \App\Domain\ReferralStatus::Closed,
    ], true);
?>
<section class="panel" id="guardian">
    <h2 class="section-title">Guardian</h2>

    <?php if ($referral->guardianName === null): ?>
        <p><strong>No guardian is named on this referral.</strong> Nobody is recorded as acting for
            the patient, so there is no authority to verify.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">The guardian named on this referral</caption>
                <tbody>
                    <tr>
                        <th scope="row">Name</th>
                        <td><?= e($referral->guardianName) ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Contact</th>
                        <td><?= e($referral->guardianPhone ?? 'Not recorded') ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Relationship</th>
                        <td><?= e($referral->guardianRelationship ?? 'Not recorded') ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Authority</th>
                        <td>
                            <?php if ($guardianVerification === null): ?>
                                <strong>Not verified.</strong> This person is named as acting for the
                                patient; nobody has checked that they may.
                            <?php else: ?>
                                Verified <?= e($guardianVerification['at']) ?>
                                by <?= e($guardianVerification['by']) ?>
                                — <?= e($guardianVerification['basis']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($guardianVerification === null && $currentUser->can(\App\Domain\Permission::ReferralTriage)): ?>
            <h3>Verify the authority</h3>
            <p class="muted">
                Say what you checked — a document, a register, the person's own record. A relationship
                is not an authority: the adult who brings a child may be neither a parent nor a
                guardian.
            </p>
            <form method="post"
                  action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/guardian-verification"
                  class="stack">
                <?= \App\Security\Csrf::field() ?>
                <div class="field">
                    <label for="guardian_verification_basis">What the authority was checked against</label>
                    <input id="guardian_verification_basis" name="guardian_verification_basis"
                        maxlength="160" required minlength="10"
                        <?= isset($errors['guardian_verification_basis'])
                            ? 'aria-describedby="guardian_basis-error" aria-invalid="true"'
                            : '' ?>>
                    <?php if (isset($errors['guardian_verification_basis'])): ?>
                        <p class="field-error" id="guardian_basis-error">
                            <?= e($errors['guardian_verification_basis']) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php if (isset($errors['guardian'])): ?>
                    <p class="field-error"><?= e($errors['guardian']) ?></p>
                <?php endif; ?>
                <div class="form-actions">
                    <button class="button" type="submit">Record verification</button>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel" id="assessment">
    <h2 class="section-title">Pre-procedure assessment</h2>
    <p class="muted">
        Allergy, anticoagulant use and pregnancy are recorded as answers somebody gave, not as
        blanks that mean &ldquo;no&rdquo;. &ldquo;Unknown&rdquo; and &ldquo;not yet assessed&rdquo;
        are real answers here: a bleeding risk judged from an empty field is a bleeding risk nobody
        assessed.
    </p>

    <?php if ($assessment === null): ?>
        <p><strong>Not recorded.</strong> Nothing has been assessed yet, which is not the same as
            nothing being wrong.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">The assessment that currently stands</caption>
                <tbody>
                    <tr>
                        <th scope="row">Anticoagulant or antiplatelet</th>
                        <td><?= e($assessment->anticoagulant?->label() ?? 'Not recorded') ?>
                            <?php if ($assessment->anticoagulantDetails !== null): ?>
                                — <?= e($assessment->anticoagulantDetails) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Allergy</th>
                        <td><?= e($assessment->allergy?->label() ?? 'Not recorded') ?>
                            <?php if ($assessment->allergyDetails !== null): ?>
                                — <?= e($assessment->allergyDetails) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Pregnancy</th>
                        <td><?= e($assessment->pregnancy?->label() ?? 'Not recorded') ?>
                            <?php if ($assessment->pregnancyDetails !== null): ?>
                                — <?= e($assessment->pregnancyDetails) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Relevant history</th>
                        <td><?= $assessment->relevantHistory !== null
                            ? nl2br(e($assessment->relevantHistory))
                            : 'Not recorded' ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Current medications</th>
                        <td>
                            <?= e($assessment->medicationReview?->label() ?? 'Not recorded') ?>
                            <?php if ($assessment->medications !== []): ?>
                                <ul class="plain-list">
                                    <?php foreach ($assessment->medications as $medication): ?>
                                        <li><?= e($medication->describe()) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Prior procedure</th>
                        <td><?= e($assessment->priorProcedure ?? 'Not recorded') ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Imaging</th>
                        <td>
                            <?= e($assessment->imagingModality?->label() ?? 'Not recorded') ?>
                            <?php if ($assessment->imagingDate !== null): ?>
                                — <?= e($assessment->imagingDate) ?>
                            <?php endif; ?>
                            <?php if ($assessment->imagingReference !== null): ?>
                                (<?= e($assessment->imagingReference) ?>)
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Recorded</th>
                        <td><?= e($assessment->recordedAtUtc) ?>
                            by user #<?= e((string) $assessment->recordedByUserId) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if (!$assessment->isCompleteForProcedure()): ?>
            <p class="alert alert-warning">
                This assessment is not complete for a procedure: allergy and anticoagulant use both
                have to be established, and pregnancy must not be left open.
            </p>
        <?php endif; ?>

        <?php if (count($assessmentHistory) > 1): ?>
            <h3>Earlier assessments</h3>
            <p class="muted">Nothing is overwritten. A later assessment supersedes an earlier one,
                and the earlier one stays readable.</p>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Every assessment recorded on this referral</caption>
                    <thead>
                        <tr>
                            <th scope="col">Recorded</th>
                            <th scope="col">Allergy</th>
                            <th scope="col">Anticoagulant</th>
                            <th scope="col">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assessmentHistory as $row): ?>
                            <?php $entry = $row['assessment']; ?>
                            <tr>
                                <th scope="row"><?= e($entry->recordedAtUtc) ?></th>
                                <td><?= e($entry->allergy?->label() ?? 'Not recorded') ?></td>
                                <td><?= e($entry->anticoagulant?->label() ?? 'Not recorded') ?></td>
                                <td><?= e($row['recordedBy']) ?>
                                    <?= $entry->id === $assessment->id ? '(current)' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($mayAssess): ?>
        <h3><?= $assessment === null ? 'Record the assessment' : 'Record a new assessment' ?></h3>
        <p class="muted">
            Leave a question blank if it has not been asked. Choosing &ldquo;unknown&rdquo; is a
            different statement from leaving it blank, and the record keeps them apart.
        </p>
        <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/assessment" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="field">
                <label for="anticoagulant_status">Anticoagulant or antiplatelet use</label>
                <select id="anticoagulant_status" name="anticoagulant_status">
                    <option value="">— not recorded —</option>
                    <?php foreach ($anticoagulantStatuses as $status): ?>
                        <option value="<?= e($status->value) ?>"><?= e($status->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="anticoagulant_details">Drug, indication and last dose if known</label>
                <input id="anticoagulant_details" name="anticoagulant_details" maxlength="500">
            </div>
            <div class="field">
                <label for="allergy_status">Allergy</label>
                <select id="allergy_status" name="allergy_status">
                    <option value="">— not recorded —</option>
                    <?php foreach ($allergyStatuses as $status): ?>
                        <option value="<?= e($status->value) ?>"><?= e($status->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="allergy_details">Allergy and reaction where known</label>
                <input id="allergy_details" name="allergy_details" maxlength="500">
            </div>
            <div class="field">
                <label for="pregnancy_status">Pregnancy-related assessment</label>
                <select id="pregnancy_status" name="pregnancy_status">
                    <option value="">— not recorded —</option>
                    <?php foreach ($pregnancyStatuses as $status): ?>
                        <option value="<?= e($status->value) ?>"><?= e($status->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="pregnancy_details">Pregnancy assessment detail</label>
                <input id="pregnancy_details" name="pregnancy_details" maxlength="500">
            </div>
            <div class="field">
                <label for="relevant_history">Relevant medical history</label>
                <textarea id="relevant_history" name="relevant_history" rows="3"></textarea>
            </div>
            <div class="field">
                <label for="prior_procedure">Relevant prior biopsy, drainage or intervention</label>
                <input id="prior_procedure" name="prior_procedure" maxlength="1000">
            </div>
            <div class="field">
                <label for="examination_context">Examination and clinical context</label>
                <textarea id="examination_context" name="examination_context" rows="3"></textarea>
            </div>
            <div class="field">
                <label for="imaging_modality">Imaging available</label>
                <select id="imaging_modality" name="imaging_modality">
                    <option value="">— not recorded —</option>
                    <?php foreach ($imagingModalities as $modality): ?>
                        <option value="<?= e($modality->value) ?>"><?= e($modality->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="imaging_date">Date of the imaging</label>
                <input id="imaging_date" name="imaging_date" type="date">
            </div>
            <div class="field">
                <label for="imaging_reference">Secure imaging reference</label>
                <input id="imaging_reference" name="imaging_reference" maxlength="200">
                <p class="muted">A reference to an approved route, never a public link or credentials.</p>
            </div>
            <fieldset class="field">
                <legend>Current medications</legend>
                <div class="field">
                    <label for="medication_review_status">How far the review got</label>
                    <select id="medication_review_status" name="medication_review_status">
                        <option value="">— not recorded —</option>
                        <option value="recorded">List recorded and reviewed</option>
                        <option value="not_reconciled">Reported, not yet reconciled</option>
                        <option value="none_reported_confirmed">None — confirmed by asking</option>
                        <option value="unknown">Unknown</option>
                    </select>
                    <p class="muted">
                        A list typed in at intake is &ldquo;reported, not reconciled&rdquo; until a
                        clinician has checked it. Leaving this blank means nobody asked — which is
                        not the same as the patient taking nothing.
                    </p>
                </div>
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="field-row">
                        <div class="field">
                            <label for="medication_name_<?= (string) $i ?>">Medication</label>
                            <input id="medication_name_<?= (string) $i ?>" name="medication_name[]"
                                maxlength="200">
                        </div>
                        <div class="field">
                            <label for="medication_dose_<?= (string) $i ?>">Dose</label>
                            <input id="medication_dose_<?= (string) $i ?>" name="medication_dose[]"
                                maxlength="100">
                        </div>
                        <div class="field">
                            <label for="medication_frequency_<?= (string) $i ?>">Frequency</label>
                            <input id="medication_frequency_<?= (string) $i ?>"
                                name="medication_frequency[]" maxlength="100">
                        </div>
                    </div>
                <?php endfor; ?>
            </fieldset>
            <?php foreach (['anticoagulant_details', 'allergy_details', 'imaging_date', 'medications', 'assessment'] as $field): ?>
                <?php if (isset($errors[$field])): ?>
                    <p class="field-error"><?= e($errors[$field]) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="form-actions">
                <button class="button" type="submit">Record assessment</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="panel" id="consent">
    <h2 class="section-title">Consent</h2>
    <p class="muted">
        Consent is a discussion that is recorded, not a box that is ticked. What was said, which
        information was used, in which language, and on whose authority are all part of the record,
        because the answer to &ldquo;was this patient properly consented?&rdquo; has to be reconstructible
        years later. The system never records consent on its own, and it offers no emergency override:
        a patient who cannot take part is recorded as such and remains a person&rsquo;s problem to solve.
    </p>

    <?php if ($consent === null): ?>
        <p><strong>No consent discussion is recorded yet.</strong> A recorded agreement is required
            before the procedure, and nothing here implies one exists.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">The consent record that currently stands</caption>
                <tbody>
                    <tr>
                        <th scope="row">Outcome</th>
                        <td><strong><?= e($consent->standingLabel()) ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row">Discussed with</th>
                        <td><?= e($consent->participantName) ?>
                            (<?= e($consent->participant->label()) ?>)</td>
                    </tr>
                    <?php if ($consent->participant->needsVerifiedAuthority()): ?>
                        <tr>
                            <th scope="row">Representative authority</th>
                            <td>
                                <?php if ($consent->authorityVerified): ?>
                                    Verified at <?= e($consent->authorityVerifiedAtUtc ?? '') ?>
                                <?php else: ?>
                                    <strong>Not verified</strong> — no agreement can be recorded on this
                                    authority, and none has been
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">Capacity</th>
                        <td>
                            <?= $consent->capacityAssessed ? 'Assessed' : 'Not recorded as assessed' ?>
                            <?php if ($consent->capacityNote !== null): ?>
                                — <?= e($consent->capacityNote) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Information used</th>
                        <td>Version <?= e($consent->informationVersion) ?>,
                            language <?= e($consent->informationLanguage) ?></td>
                    </tr>
                    <?php if ($consent->interpreterInvolved): ?>
                        <tr>
                            <th scope="row">Interpreter</th>
                            <td><?= e((string) $consent->interpreterLanguage) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">What was discussed</th>
                        <td><?= nl2br(e($consent->discussionRecord)) ?></td>
                    </tr>
                    <?php if ($consent->questionsRecorded !== null): ?>
                        <tr>
                            <th scope="row">Questions and answers</th>
                            <td><?= nl2br(e($consent->questionsRecorded)) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">Attestation</th>
                        <td><?= e($consent->attestationReference) ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Recorded</th>
                        <td><?= e($consent->consentedAtUtc) ?> by user #<?= e((string) $consent->recordedByUserId) ?></td>
                    </tr>
                    <?php if ($consent->isWithdrawn()): ?>
                        <tr>
                            <th scope="row">Withdrawn</th>
                            <td><?= e((string) $consent->withdrawnAtUtc) ?> —
                                <?= e((string) $consent->withdrawalReason) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($consentHistory !== []): ?>
        <h3>Consent history</h3>
        <p class="muted">
            Nothing here is edited. A later discussion supersedes an earlier one, and a withdrawal
            leaves what was agreed readable.
        </p>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Every consent discussion recorded on this referral</caption>
                <thead>
                    <tr>
                        <th scope="col">Recorded</th>
                        <th scope="col">Outcome</th>
                        <th scope="col">Discussed with</th>
                        <th scope="col">By</th>
                        <th scope="col">Superseded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($consentHistory as $row): ?>
                        <?php $entry = $row['consent']; ?>
                        <tr>
                            <th scope="row"><?= e($entry->consentedAtUtc) ?></th>
                            <td><?= e($entry->standingLabel()) ?></td>
                            <td><?= e($entry->participantName) ?></td>
                            <td><?= e($row['recordedBy']) ?></td>
                            <td><?= $entry->id !== $consent->id ? 'Yes' : 'No — this is current' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($mayRecordConsent): ?>
        <h3><?= $consent === null ? 'Record the discussion' : 'Record a new discussion' ?></h3>
        <p class="muted">
            Recording again does not overwrite the earlier record: it supersedes it, and both stay
            readable.
        </p>
        <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/consent" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="field">
                <label for="participant_relationship">Who took part</label>
                <select id="participant_relationship" name="participant_relationship" required>
                    <option value="">— Choose —</option>
                    <?php foreach ($consentParticipants as $participant): ?>
                        <option value="<?= e($participant->value) ?>"><?= e($participant->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="participant_name">Name of the person taking part</label>
                <input id="participant_name" name="participant_name" maxlength="120" required
                    value="<?= e($referral->patientName) ?>">
            </div>
            <div class="field">
                <label>
                    <input type="checkbox" name="authority_verified" value="1">
                    I have verified this person&rsquo;s authority to decide for the patient
                </label>
                <p class="muted">
                    Required before an agreement can be recorded on anyone else&rsquo;s behalf. A
                    relationship is not an authority: an accompanying adult may be neither a parent nor
                    a guardian.
                </p>
            </div>
            <div class="field">
                <label for="outcome">Outcome</label>
                <select id="outcome" name="outcome" required>
                    <option value="">— Choose —</option>
                    <?php foreach ($consentOutcomes as $outcome): ?>
                        <option value="<?= e($outcome->value) ?>"><?= e($outcome->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="information_version">Version of the patient information used</label>
                <input id="information_version" name="information_version" maxlength="60" required>
            </div>
            <div class="field">
                <label for="information_language">Language it was given in</label>
                <input id="information_language" name="information_language" maxlength="16" required>
            </div>
            <div class="field">
                <label>
                    <input type="checkbox" name="interpreter_involved" value="1">
                    An interpreter was involved
                </label>
            </div>
            <div class="field">
                <label for="interpreter_language">Interpreter language</label>
                <input id="interpreter_language" name="interpreter_language" maxlength="16">
            </div>
            <div class="field">
                <label for="target_site">Site or lesion discussed</label>
                <input id="target_site" name="target_site" maxlength="120"
                    value="<?= e((string) ($referral->targetSite ?? '')) ?>">
            </div>
            <div class="field">
                <label for="discussion_record">What was discussed</label>
                <textarea id="discussion_record" name="discussion_record" rows="5"
                    minlength="30" maxlength="8000" required
                    <?= isset($errors['discussion_record'])
                        ? 'aria-describedby="discussion_record-error" aria-invalid="true"'
                        : 'aria-describedby="discussion_record-note"' ?>></textarea>
                <p class="muted" id="discussion_record-note">
                    Benefits, material risks and alternatives, in your own words — this is the record of
                    the discussion, not a summary of the leaflet.
                </p>
                <?php if (isset($errors['discussion_record'])): ?>
                    <p class="field-error" id="discussion_record-error"><?= e($errors['discussion_record']) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="questions_recorded">Questions raised, and the answers given</label>
                <textarea id="questions_recorded" name="questions_recorded" rows="3" maxlength="4000"></textarea>
            </div>
            <div class="field">
                <label>
                    <input type="checkbox" name="capacity_assessed" value="1">
                    Capacity was assessed
                </label>
            </div>
            <div class="field">
                <label for="capacity_note">Capacity assessment note</label>
                <input id="capacity_note" name="capacity_note" maxlength="500">
            </div>
            <div class="field">
                <label for="attestation_reference">Signature or attestation reference</label>
                <input id="attestation_reference" name="attestation_reference" maxlength="200" required>
                <p class="muted">
                    Where the signed form is held, or how the agreement was attested. An agreement with
                    nothing behind it is not a record.
                </p>
            </div>
            <?php if (isset($errors['authority_verified'])): ?>
                <p class="field-error"><?= e($errors['authority_verified']) ?></p>
            <?php endif; ?>
            <?php if (isset($errors['consent'])): ?>
                <p class="field-error"><?= e($errors['consent']) ?></p>
            <?php endif; ?>
            <div class="form-actions">
                <button class="button" type="submit">Record consent</button>
            </div>
        </form>
    <?php elseif (!$consentable): ?>
        <p class="muted">
            Consent is recorded after the clinical decision. This referral is
            &ldquo;<?= e($referral->status->label()) ?>&rdquo;, so there is nothing to consent to yet.
        </p>
    <?php endif; ?>

    <?php if ($consent !== null && $consent->isStandingAgreement() && $mayRecordConsent): ?>
        <h3>Withdraw the agreement</h3>
        <p class="muted">
            Withdrawal does not delete anything: it records when, by whom and why, and leaves the
            discussion readable. The procedure then needs a clinical decision, not another tick.
        </p>
        <form method="post" action="/referrals/<?= e(rawurlencode($referral->reference)) ?>/consent/withdraw"
              class="stack">
            <?= \App\Security\Csrf::field() ?>
            <input type="hidden" name="consent_id" value="<?= e((string) $consent->id) ?>">
            <div class="field">
                <label for="withdrawal_reason">Why the agreement no longer stands</label>
                <textarea id="withdrawal_reason" name="withdrawal_reason" rows="3"
                    minlength="10" maxlength="500" required
                    <?= isset($errors['withdrawal_reason'])
                        ? 'aria-describedby="withdrawal_reason-error" aria-invalid="true"'
                        : '' ?>></textarea>
                <?php if (isset($errors['withdrawal_reason'])): ?>
                    <p class="field-error" id="withdrawal_reason-error"><?= e($errors['withdrawal_reason']) ?></p>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <button class="button button-secondary" type="submit">Withdraw consent</button>
            </div>
        </form>
    <?php endif; ?>
</section>
