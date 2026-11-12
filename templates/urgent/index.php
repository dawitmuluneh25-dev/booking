<?php
/**
 * The urgent coordination board.
 *
 * The only thing this page sorts for is "nobody has picked this up" and "it has
 * been waiting longest". It deliberately does not compute a deadline or label a
 * case overdue: no acknowledgement interval has been approved (LP-020), so the
 * page reports elapsed time and stops there.
 *
 * @var list<array{case: \App\Domain\UrgentCase, receivedAt: string, elapsed: string}> $rows
 * @var bool          $canRegister
 * @var string|null   $notice
 * @var \App\Domain\User $currentUser
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Urgent coordination</h1>
        <?php if ($canRegister): ?>
            <a class="button button-small" href="/urgent-cases/new">Register an urgent case</a>
        <?php endif; ?>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><p><?= e($notice) ?></p></div>
    <?php endif; ?>

    <p class="alert alert-warning" role="note">
        An urgent case is a coordination record, not an appointment. Nothing on this board
        creates, reserves or holds a slot, and a case cannot be booked from here.
    </p>

    <p class="muted">
        Cases with no acknowledgement are listed first, then oldest first. Elapsed time is
        measured from receipt. No acknowledgement interval or escalation ladder has been
        approved, so this page does not call a case overdue and does not choose a contact
        for you.
    </p>

    <p class="muted">
        <strong>Escalation policy: not configured.</strong> No interval, level ladder or
        after-hours route has been approved (<span class="mono">LP-020</span>). Escalating is
        a person's judgement recorded against their name; the system does not decide when.
    </p>

    <?php if ($rows === []): ?>
        <p class="empty">No urgent case is waiting.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Urgent cases awaiting coordination</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Patient</th>
                        <th scope="col">Referring facility</th>
                        <th scope="col">Received</th>
                        <th scope="col">Waiting</th>
                        <th scope="col">Coordination</th>
                        <th scope="col">Clinical status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $case = $row['case']; ?>
                        <tr>
                            <th scope="row">
                                <a href="/urgent-cases/<?= e(rawurlencode($case->reference)) ?>">
                                    <?= e($case->reference) ?>
                                </a>
                            </th>
                            <td>
                                <?= e($case->patientName) ?>
                                <span class="muted block">
                                    <?= e($case->patientAgeMonths === null
                                        ? ($case->patientDob ?? 'Age not recorded')
                                        : $case->patientAgeMonths . ' months') ?>
                                </span>
                            </td>
                            <td><?= e($case->referringFacility ?? '—') ?></td>
                            <td><?= e($row['receivedAt']) ?></td>
                            <td><?= e($row['elapsed']) ?></td>
                            <td>
                                <?php if ($case->coordination->isAcknowledged()): ?>
                                    <span class="badge"><?= e($case->coordination->label()) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-current"><?= e($case->coordination->label()) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge"><?= e($case->urgency->label()) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
