<?php
/**
 * @var list<array{
 *     reference: string,
 *     patient: string,
 *     procedure: string,
 *     state: string,
 *     sent: string,
 *     outstanding: ?string
 * }>          $referrals
 * @var string|null $withheld
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>My referrals</h1>
        <a class="button button-small" href="/referrals/new">New referral</a>
    </div>

    <p class="muted">
        The referrals sent to this service under your referrer record, newest first, with the
        state each one has reached and anything the service is still waiting for. This is not
        the clinical record.
    </p>

    <?php if ($withheld !== null): ?>
        <p class="empty"><?= e($withheld) ?></p>
    <?php elseif ($referrals === []): ?>
        <p class="empty">You have not referred anyone yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Referrals you have sent, with their state</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Patient</th>
                        <th scope="col">Procedure</th>
                        <th scope="col">State</th>
                        <th scope="col">Sent</th>
                        <th scope="col">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($referrals as $referral): ?>
                        <tr>
                            <td><?= e($referral['reference']) ?></td>
                            <td><?= e($referral['patient']) ?></td>
                            <td><?= e($referral['procedure']) ?></td>
                            <td><?= e($referral['state']) ?></td>
                            <td><?= e($referral['sent']) ?></td>
                            <td>
                                <?php if ($referral['outstanding'] === null): ?>
                                    <span class="muted">Nothing outstanding</span>
                                <?php else: ?>
                                    <?= e($referral['outstanding']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
