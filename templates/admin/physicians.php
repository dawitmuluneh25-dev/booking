<?php
/**
 * @var list<\App\Domain\PhysicianQueueEntry> $entries
 * @var string                                $filter
 * @var array<string, string>                 $errors
 * @var string|null                           $notice
 * @var string                                $timezone
 * @var \App\Domain\User                      $currentUser
 */
$tabs = ['pending' => 'Awaiting review', 'verified' => 'Verified', 'suspended' => 'Suspended', 'rejected' => 'Rejected', 'all' => 'All'];
?>
<section class="panel">
    <div class="panel-head">
        <h1>Referring physicians</h1>
        <a class="button button-secondary button-small" href="/admin">Back</a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><p><?= e($notice) ?></p></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That decision was not recorded.</p>
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="muted">
        Verification decides whether referrals from this clinician are accepted. It does not
        change their account role, and it does not let them see anyone else's referrals.
        Suspending a profile leaves the account intact, so the clinician can still sign in and
        see why.
    </p>

    <nav class="tabs" aria-label="Filter by status">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="tab<?= $key === $filter ? ' is-active' : '' ?>"
               href="/admin/physicians?status=<?= e($key) ?>"
               <?= $key === $filter ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($entries === []): ?>
        <p class="empty">
            <?= $filter === 'pending'
                ? 'No physician is waiting for verification.'
                : 'Nothing in this filter.' ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Referring physicians, <?= e($tabs[$filter]) ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Physician</th>
                        <th scope="col">Practice number</th>
                        <th scope="col">Institution</th>
                        <th scope="col">Submitted</th>
                        <th scope="col">Status</th>
                        <th scope="col">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <th scope="row">
                                <?= e($entry->name) ?>
                                <span class="muted block"><?= e($entry->email) ?></span>
                            </th>
                            <td><?= e($entry->profile->practiceNumber) ?></td>
                            <td>
                                <?= e($entry->profile->institution) ?>
                                <?php if ($entry->profile->specialty !== null): ?>
                                    <span class="muted block"><?= e($entry->profile->specialty) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($entry->createdLine($timezone)) ?>
                                <span class="muted block"><?= e($entry->reviewLine($timezone)) ?></span>
                            </td>
                            <td><span class="badge"><?= e($entry->profile->status->value) ?></span></td>
                            <td>
                                <?php if ($entry->isPending()): ?>
                                    <form method="post" action="/admin/physicians/<?= e((string) $entry->profile->id) ?>/verify" class="stack">
                                        <?= \App\Security\Csrf::field() ?>
                                        <label class="field">
                                            <span class="field-label">Note (optional)</span>
                                            <input type="text" name="note" maxlength="255" autocomplete="off">
                                        </label>
                                        <button class="button button-small" type="submit">Verify</button>
                                    </form>
                                    <form method="post" action="/admin/physicians/<?= e((string) $entry->profile->id) ?>/suspend" class="stack">
                                        <?= \App\Security\Csrf::field() ?>
                                        <label class="field">
                                            <span class="field-label">Reason (required to suspend)</span>
                                            <input type="text" name="note" maxlength="255" required>
                                        </label>
                                        <button class="button button-secondary button-small" type="submit">Suspend</button>
                                    </form>
                                    <form method="post" action="/admin/physicians/<?= e((string) $entry->profile->id) ?>/reject" class="stack">
                                        <?= \App\Security\Csrf::field() ?>
                                        <label class="field">
                                            <span class="field-label">Why the credentials did not check out</span>
                                            <input type="text" name="note" minlength="10" maxlength="255" required>
                                        </label>
                                        <button class="button button-secondary button-small" type="submit">Reject</button>
                                    </form>
                                <?php elseif ($entry->profile->status === \App\Domain\PhysicianStatus::Verified): ?>
                                    <form method="post" action="/admin/physicians/<?= e((string) $entry->profile->id) ?>/suspend" class="stack">
                                        <?= \App\Security\Csrf::field() ?>
                                        <label class="field">
                                            <span class="field-label">Reason (required)</span>
                                            <input type="text" name="note" maxlength="255" required>
                                        </label>
                                        <button class="button button-secondary button-small" type="submit">Suspend</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">No action</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
