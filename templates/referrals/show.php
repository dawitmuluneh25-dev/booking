<?php
/**
 * @var \App\Domain\Referral       $referral
 * @var \App\Domain\Procedure|null $procedure
 * @var string|null                $notice
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

    <p class="status-line">
        <span class="badge"><?= e($referral->status->label()) ?></span>
        <span class="muted">Submitted <?= e(str_replace('T', ' ', substr($referral->createdAtUtc, 0, 19))) ?> UTC</span>
    </p>

    <p class="muted">
        This is a request for clinical review. It is not an appointment and no time has been
        reserved; scheduling happens only after clinical approval.
    </p>

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
