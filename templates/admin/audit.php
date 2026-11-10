<?php
/**
 * @var list<\App\Domain\BookingEvent> $events
 * @var list<\App\Domain\RetentionRun>  $runs
 * @var list<\App\Domain\AuditEvent>    $auditEvents
 * @var list<\App\Domain\AuditAction>   $actions
 * @var int                             $total
 * @var int                             $auditTotal
 * @var string                          $reference
 * @var string                          $auditActor
 * @var string                          $auditAction
 * @var string                          $auditObjectRef
 * @var string                          $auditFrom
 * @var string                          $auditTo
 * @var bool                            $auditFiltered
 * @var bool                            $invalidDate
 * @var bool                            $unavailable
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Audit trail</h1>
        <a class="button button-secondary button-small" href="/admin">Back</a>
    </div>

    <?php if ($unavailable): ?>
        <div class="alert alert-error" role="alert">The audit trail cannot be read right now.</div>
    <?php else: ?>
        <form method="get" action="/admin/audit" class="day-form">
            <div class="grid">
                <div class="field">
                    <label for="reference">Booking reference</label>
                    <input id="reference" name="reference" type="search" value="<?= e($reference) ?>"
                           placeholder="BK-…" autocomplete="off">
                </div>
                <div class="field">
                    <label for="actor">Actor</label>
                    <input id="actor" name="actor" type="search" value="<?= e($auditActor) ?>"
                           placeholder="email or user id" autocomplete="off">
                </div>
                <div class="field">
                    <label for="action">Action</label>
                    <select id="action" name="action">
                        <option value="">Any action</option>
                        <?php foreach ($actions as $case): ?>
                            <option value="<?= e($case->value) ?>"
                                <?= $auditAction === $case->value ? 'selected' : '' ?>>
                                <?= e($case->label()) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="object_ref">Object reference</label>
                    <input id="object_ref" name="object_ref" type="search" value="<?= e($auditObjectRef) ?>"
                           placeholder="document ref or user id" autocomplete="off">
                </div>
                <div class="field">
                    <label for="from">From (clinic date)</label>
                    <input id="from" name="from" type="date" value="<?= e($auditFrom) ?>">
                </div>
                <div class="field">
                    <label for="to">Until (clinic date)</label>
                    <input id="to" name="to" type="date" value="<?= e($auditTo) ?>">
                </div>
            </div>
            <button class="button button-small" type="submit">Filter</button>
            <?php if ($reference !== '' || $auditFiltered): ?>
                <a class="link" href="/admin/audit">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($invalidDate): ?>
            <div class="alert alert-error" role="alert">
                A date filter was ignored because it is not a real date. Use the date picker.
            </div>
        <?php endif; ?>

        <p class="muted">
            <?= e($total) ?> booking event<?= $total === 1 ? '' : 's' ?><?= $reference === '' ? '' : ' for ' . e($reference) ?>.
            Events are append-only: a correction is another event, never an edit.
        </p>

        <?php if ($events === []): ?>
            <p class="empty">No lifecycle events match this filter.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Booking lifecycle events</caption>
                    <thead>
                        <tr>
                            <th scope="col">When (UTC)</th>
                            <th scope="col">Booking</th>
                            <th scope="col">From</th>
                            <th scope="col">To</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= e(str_replace('T', ' ', substr($event->createdAtUtc, 0, 19))) ?></td>
                                <td>
                                    <a href="/bookings/<?= e(rawurlencode($event->reference)) ?>">
                                        <?= e($event->reference) ?>
                                    </a>
                                </td>
                                <td><?= e($event->fromStatus) ?></td>
                                <td><?= e($event->toStatus) ?></td>
                                <td><?= e($event->actor) ?></td>
                                <td class="muted"><?= e($event->reason ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Authority and credential events</h2>
        <p class="muted">
            <?= e($auditTotal) ?> account, credential or document decision<?= $auditTotal === 1 ? '' : 's' ?>
            recorded. This table is what answers &ldquo;who was made an administrator, and when&rdquo;;
            it carries identifiers, never patient names, phone numbers or clinical text.
        </p>

        <?php if ($auditEvents === []): ?>
            <p class="empty">No authority events match this filter.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Account, credential and document decisions</caption>
                    <thead>
                        <tr>
                            <th scope="col">When (UTC)</th>
                            <th scope="col">Action</th>
                            <th scope="col">Object</th>
                            <th scope="col">Change</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Request</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditEvents as $auditEvent): ?>
                            <tr>
                                <td><?= e(str_replace('T', ' ', substr($auditEvent->occurredAtUtc, 0, 19))) ?></td>
                                <td><?= e($auditEvent->action) ?></td>
                                <td><?= e($auditEvent->objectType) ?> <?= e($auditEvent->objectRef) ?></td>
                                <td class="muted"><?= e($auditEvent->changeSummary()) ?></td>
                                <td>
                                    <?php if ($auditEvent->actorEmail !== ''): ?>
                                        <?= e($auditEvent->actorEmail) ?>
                                    <?php else: ?>
                                        <span class="muted">unauthenticated</span>
                                    <?php endif; ?>
                                    <?php if ($auditEvent->actorRole !== ''): ?>
                                        <span class="muted">(<?= e($auditEvent->actorRole) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted"><?= e($auditEvent->requestId === '' ? '—' : $auditEvent->requestId) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Retention sweeps</h2>
        <?php if ($runs === []): ?>
            <p class="empty">
                No sweep has run. Check the plan with <code>php bin/console retention:plan</code>.
            </p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Executed retention sweeps</caption>
                    <thead>
                        <tr>
                            <th scope="col">Ran (UTC)</th>
                            <th scope="col">Cutoff</th>
                            <th scope="col">Bookings redacted</th>
                            <th scope="col">Actor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $run): ?>
                            <tr>
                                <td><?= e(str_replace('T', ' ', substr($run->ranAtUtc, 0, 19))) ?></td>
                                <td><?= e($run->cutoffDate) ?></td>
                                <td><?= e($run->bookingsRedacted) ?></td>
                                <td><?= e($run->actor) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
