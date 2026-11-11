<?php
/**
 * @var \App\Domain\Referral       $referral
 * @var \App\Domain\Procedure|null $procedure
 * @var array{type: string, rationale: string, reviewer: string, decidedAt: string, technique: ?string, modality: ?string, sedation: ?string, minutes: ?int}|null $decision
 * @var string|null                $notice
 * @var array<string, string>      $errors
 * @var \App\Domain\User           $currentUser
 */
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
                    <td><?= e($referral->requestedUrgencyLabel()) ?></td>
                </tr>
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
</section>
