<?php
/**
 * @var list<\App\Domain\Referral> $referrals
 * @var string|null                $notice
 * @var array<string, string>      $labels
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Clinical review</h1>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <p class="muted">
        Referrals waiting for a radiologist, oldest first. Nothing here can be scheduled;
        approval is what makes a referral schedulable.
    </p>

    <?php if ($referrals === []): ?>
        <p class="empty">Nothing is awaiting review.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Referrals awaiting clinical review</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Patient</th>
                        <th scope="col">Procedure</th>
                        <th scope="col">Urgency</th>
                        <th scope="col">Sent (UTC)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($referrals as $referral): ?>
                        <tr>
                            <td>
                                <a href="/review/<?= e(rawurlencode($referral->reference)) ?>">
                                    <?= e($referral->reference) ?>
                                </a>
                            </td>
                            <td><?= e($referral->patientName) ?></td>
                            <td><?= e($labels[$referral->procedureCode] ?? $referral->procedureCode) ?></td>
                            <td><?= e(ucfirst($referral->requestedUrgency)) ?></td>
                            <td><?= e(str_replace('T', ' ', substr($referral->createdAtUtc, 0, 19))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
