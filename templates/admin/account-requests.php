<?php
/**
 * @var list<\App\Domain\AccountRequest> $pending
 * @var list<\App\Domain\AccountRequest> $decided
 * @var bool $unavailable
 * @var array<string, string> $errors
 * @var array{user: \App\Domain\User, temporaryPassword: string, request: \App\Domain\AccountRequest}|null $created
 * @var bool $mayDecide
 * @var string|null $notice
 * @var \App\Domain\User|null $currentUser
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Referring account requests</h1>
        <a class="button button-secondary button-small" href="/admin">Back</a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status">
            <p><?= e($notice) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($created !== null): ?>
        <div class="alert alert-warning" role="alert">
            <p>
                Account created for <strong><?= e($created['user']->name) ?></strong>
                from request <?= e($created['request']->reference) ?>.
            </p>
            <p>
                Temporary password, shown once:
                <code class="secret"><?= e($created['temporaryPassword']) ?></code>
            </p>
            <p class="muted">
                Give it to the person directly; it is not stored in readable form and cannot
                be shown again. <strong>Referral authority has not been granted.</strong>
                The account has a pending referring profile — a clinical approver must verify
                the registration before referrals from this account can be accepted.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$mayDecide): ?>
        <p class="muted">
            You can read this queue. Creating an account needs the account-management
            permission, so the decision forms are not shown here.
        </p>
    <?php endif; ?>

    <?php if ($unavailable): ?>
        <p>Requests cannot be loaded right now.</p>
    <?php elseif ($pending === []): ?>
        <p class="empty">Nothing is waiting for a decision.</p>
    <?php else: ?>
        <h2>Waiting (<?= e((string) count($pending)) ?>)</h2>
        <p class="muted">Oldest first, so the queue is worked in the order it arrived.</p>

        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Account requests awaiting a decision</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Person</th>
                        <th scope="col">Practice</th>
                        <th scope="col">Received</th>
                        <?php if ($mayDecide): ?>
                            <th scope="col">Decision</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $request): ?>
                        <tr>
                            <th scope="row"><?= e($request->reference) ?></th>
                            <td>
                                <?= e($request->fullName) ?><br>
                                <span class="muted"><?= e($request->email) ?></span>
                                <?php if ($request->registrationNo !== null): ?>
                                    <br><span class="muted">Reg. <?= e($request->registrationNo) ?></span>
                                <?php else: ?>
                                    <br><span class="muted">No registration number given</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($request->practiceName) ?><br>
                                <span class="muted"><?= e($request->practiceCity) ?></span>
                                <?php if ($request->note !== null): ?>
                                    <br><span class="muted"><?= e($request->note) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($request->createdAtUtc) ?> UTC</td>
                            <?php if ($mayDecide): ?>
                                <td>
                                    <form method="post" action="/admin/account-requests/<?= e((string) $request->id) ?>/approve" class="inline-form">
                                        <?= \App\Security\Csrf::field() ?>
                                        <label class="visually-hidden" for="approve-reason-<?= e((string) $request->id) ?>">
                                            Note for the approval of <?= e($request->fullName) ?>
                                        </label>
                                        <input
                                            id="approve-reason-<?= e((string) $request->id) ?>"
                                            name="reason"
                                            type="text"
                                            maxlength="500"
                                            placeholder="Optional note"
                                        >
                                        <button class="button button-small" type="submit">Approve</button>
                                    </form>

                                    <form method="post" action="/admin/account-requests/<?= e((string) $request->id) ?>/reject" class="inline-form">
                                        <?= \App\Security\Csrf::field() ?>
                                        <label class="visually-hidden" for="reject-reason-<?= e((string) $request->id) ?>">
                                            Reason for refusing <?= e($request->fullName) ?>
                                        </label>
                                        <input
                                            id="reject-reason-<?= e((string) $request->id) ?>"
                                            name="reason"
                                            type="text"
                                            maxlength="500"
                                            placeholder="Reason (required, 10+ characters)"
                                            required
                                        >
                                        <button class="button button-secondary button-small" type="submit">Refuse</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!$unavailable && $decided !== []): ?>
        <h2>Recent decisions</h2>

        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Account requests already decided</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Person</th>
                        <th scope="col">Outcome</th>
                        <th scope="col">Decided</th>
                        <th scope="col">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($decided as $request): ?>
                        <tr>
                            <th scope="row"><?= e($request->reference) ?></th>
                            <td>
                                <?= e($request->fullName) ?><br>
                                <span class="muted"><?= e($request->practiceName) ?></span>
                            </td>
                            <td>
                                <?= e($request->status->label()) ?>
                                <?php if ($request->createdUserId !== null): ?>
                                    <br><span class="muted">Account #<?= e((string) $request->createdUserId) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($request->decidedAtUtc ?? '—') ?>
                                <?php if ($request->decidedByUserId !== null): ?>
                                    <br><span class="muted">Staff #<?= e((string) $request->decidedByUserId) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($request->decisionReason ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
