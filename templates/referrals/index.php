<?php
/**
 * @var list<\App\Domain\Referral> $referrals
 * @var string|null                 $notice
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Referral queue</h1>
        <a class="button button-small" href="/referrals/new">New referral</a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <p class="muted">
        Submitted referrals waiting on the desk or on the referrer, oldest first. A referral
        is not an appointment: nothing here is scheduled, and nothing here has been through
        clinical review.
    </p>

    <?php if ($referrals === []): ?>
        <p class="empty">Nothing is waiting. New referrals appear here as they are submitted.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Referrals awaiting administrative action</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Patient</th>
                        <th scope="col">Procedure</th>
                        <th scope="col">Status</th>
                        <th scope="col">Submitted (UTC)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($referrals as $referral): ?>
                        <tr>
                            <td>
                                <a href="/referrals/<?= e(rawurlencode($referral->reference)) ?>">
                                    <?= e($referral->reference) ?>
                                </a>
                            </td>
                            <td><?= e($referral->patientName) ?></td>
                            <td><?= e($referral->procedureCode) ?></td>
                            <td><?= e($referral->status->label()) ?></td>
                            <td><?= e(str_replace('T', ' ', substr($referral->createdAtUtc, 0, 19))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
