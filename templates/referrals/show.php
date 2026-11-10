<?php
/**
 * @var \App\Domain\Referral       $referral
 * @var \App\Domain\Procedure|null $procedure
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
            <p>This draft is not complete yet.</p>
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

    <?php
    $advancements = [
        'receive' => ['label' => 'Mark received', 'target' => \App\Domain\ReferralStatus::Received],
        'triage' => ['label' => 'Start administrative review', 'target' => \App\Domain\ReferralStatus::AdministrativeReview],
        'request-information' => ['label' => 'Request information', 'target' => \App\Domain\ReferralStatus::AwaitingInformation],
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
                    <td><?= e(ucfirst($referral->requestedUrgency)) ?></td>
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
