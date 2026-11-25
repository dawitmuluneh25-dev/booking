<?php

/**
 * The five operational appointments (D-05), stored in the database (D-08).
 *
 * @var list<array{
 *     key: string,
 *     title: string,
 *     responsibility: string,
 *     holder: string|null,
 *     deputy: string|null,
 *     updatedBy: string|null,
 *     updatedAt: string|null
 * }>                        $rows
 * @var int                   $vacant
 * @var array<string, string> $errors
 * @var string|null           $notice
 * @var \App\Domain\User      $currentUser
 * @var bool                  $canEdit whether the signed-in role may change these values
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Operations</h1>
        <a class="button button-secondary button-small" href="/admin/escalation-routes">Escalation routes</a>
    </div>

    <?php if ($notice !== null): ?>
        <p class="alert alert-info"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That change was not recorded.</p>
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($vacant > 0): ?>
        <p class="alert alert-warning" role="status">
            <strong><?= $vacant ?> of <?= count($rows) ?> appointments are vacant.</strong>
            An appointment with nobody named counts toward nothing: release coverage stays unmet until
            a holder and a deputy are recorded for every one of the five.
        </p>
    <?php else: ?>
        <p class="alert alert-info" role="status">
            <strong>All five appointments have a holder and a deputy.</strong>
        </p>
    <?php endif; ?>

    <p class="lede">
        Who does each operational job, and who covers it when they are unreachable. A job title here
        grants no access to anything: permissions follow the role somebody signed in with, never the
        appointment they hold.
    </p>

    <p class="muted">
        The deputy exists to be called when the holder cannot be. The screen refuses the same name in
        both columns, because a rota with no cover that looks covered is worse than one that admits it.
    </p>

    <form method="post" action="/admin/operations" class="stack">
        <?= \App\Security\Csrf::field() ?>

        <?php foreach ($rows as $row): ?>
            <fieldset class="card">
                <legend><?= e($row['title']) ?></legend>

                <p class="muted"><?= e(ucfirst($row['responsibility'])) ?>.</p>

                <div class="field-row">
                    <label for="holder-<?= e($row['key']) ?>">Holder</label>
                    <input
                        id="holder-<?= e($row['key']) ?>"
                        name="holder[<?= e($row['key']) ?>]"
                        type="text"
                        value="<?= e($row['holder'] ?? '') ?>"
                        maxlength="120"
                        autocomplete="off"
                        <?= $canEdit ? '' : 'disabled' ?>
                    >
                </div>

                <div class="field-row">
                    <label for="deputy-<?= e($row['key']) ?>">Deputy</label>
                    <input
                        id="deputy-<?= e($row['key']) ?>"
                        name="deputy[<?= e($row['key']) ?>]"
                        type="text"
                        value="<?= e($row['deputy'] ?? '') ?>"
                        maxlength="120"
                        autocomplete="off"
                        <?= $canEdit ? '' : 'disabled' ?>
                    >
                </div>

                <p class="muted">
                    <?php if ($row['holder'] === null): ?>
                        Vacant. Nobody is named for this appointment.
                    <?php elseif ($row['updatedAt'] !== null): ?>
                        Last changed <?= e((string) $row['updatedAt']) ?> by <?= e((string) ($row['updatedBy'] ?? 'unknown')) ?>.
                    <?php endif; ?>
                </p>
            </fieldset>
        <?php endforeach; ?>

        <?php if ($canEdit): ?>
            <div class="actions">
                <button type="submit" class="button">Save appointments</button>
            </div>
            <p class="muted">
                Every change is written to the audit trail with your account, the previous names and the
                new ones. A submission that changes nothing writes nothing.
            </p>
        <?php else: ?>
            <p class="muted">Your role can read this page but not change it.</p>
        <?php endif; ?>
    </form>
</section>

