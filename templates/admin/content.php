<?php

use App\Domain\ContentStatus;
use App\Domain\PublishedContent;
use App\Security\Csrf;

/**
 * @var \App\Domain\User $currentUser
 * @var list<PublishedContent> $records
 * @var string $today
 * @var int $nowShowing
 * @var string|null $notice
 * @var string|null $error
 * @var array<string, string> $errors
 * @var bool $canPublish
 * @var callable(PublishedContent): string $statusLabel
 * @var callable(PublishedContent): bool $isExpired
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Patient content</h1>
        <div class="panel-actions">
            <a class="button button-secondary button-small" href="/admin">Administration</a>
            <a class="button button-secondary button-small" href="/services">View public pages</a>
        </div>
    </div>

    <?php if ($notice !== null): ?>
        <p class="alert alert-success"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="alert alert-error"><?= e($error) ?></p>
    <?php endif; ?>

    <p class="lede">
        The words a patient reads, with the provenance §17.5 requires beside them: who
        wrote them, who reviewed them, who approved them, when they take effect and when
        somebody must read them again.
    </p>

    <p class="muted">
        Publishing is refused until the clinical reviewer, the approver, the approval date,
        the effective date, the review date and the translation-review status are all
        recorded — the database refuses it too, so this is not a rule that only lives on
        this screen. A page whose review date passes stops being shown without anybody
        having to remember to take it down.
    </p>

    <?php
    $showing = $nowShowing === 1
        ? '1 version is being shown'
        : $nowShowing . ' versions are being shown';
    ?>
    <p class="muted">Today is <?= e($today) ?>. <?= e($showing) ?> to patients.</p>

    <?php if ($records === []): ?>
        <p><strong>No content has been imported.</strong> Run <code>php bin/console content:sync</code>
            to import the approved service pages as drafts.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Patient-facing content and its publication state</caption>
                <thead>
                    <tr>
                        <th scope="col">Page</th>
                        <th scope="col">Version</th>
                        <th scope="col">Status</th>
                        <th scope="col">Reviewer / approver</th>
                        <th scope="col">Dates</th>
                        <th scope="col">What is missing</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php $missing = $record->missingForPublication(); ?>
                        <tr>
                            <th scope="row">
                                <span class="mono"><?= e($record->contentKey) ?></span><br>
                                <span class="muted"><?= e($record->title) ?></span>
                            </th>
                            <td class="mono"><?= e($record->version) ?></td>
                            <td>
                                <span class="badge"><?= e($statusLabel($record)) ?></span>
                                <?php if ($isExpired($record)): ?>
                                    <br><span class="state state-warn">Review date passed — not being shown</span>
                                <?php elseif ($record->status === ContentStatus::Published): ?>
                                    <br><span class="state state-ok">Live</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $record->clinicalReviewer === null
                                    ? '<span class="muted">No reviewer</span>'
                                    : e($record->clinicalReviewer) ?><br>
                                <?= $record->approver === null
                                    ? '<span class="muted">No approver</span>'
                                    : e($record->approver) ?>
                            </td>
                            <td class="mono">
                                approved <?= e($record->approvedOn ?? '—') ?><br>
                                effective <?= e($record->effectiveOn ?? '—') ?><br>
                                review <?= e($record->reviewOn ?? '—') ?>
                            </td>
                            <td>
                                <?php if ($missing === []): ?>
                                    <span class="muted">Nothing</span>
                                <?php else: ?>
                                    <ul class="plain-list">
                                        <?php foreach ($missing as $item): ?>
                                            <li><?= e($item) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record->status === ContentStatus::Draft): ?>
                                    <form method="post" action="/admin/content/<?= e((string) $record->id) ?>/publish">
                                        <?= Csrf::field() ?>
                                        <button class="button button-small" type="submit"
                                            <?= $missing === [] ? '' : 'disabled' ?>>
                                            Publish
                                        </button>
                                    </form>
                                    <?php if ($missing !== []): ?>
                                        <span class="muted">Nothing to publish until the missing inputs arrive.</span>
                                    <?php endif; ?>
                                <?php elseif ($record->status === ContentStatus::Published): ?>
                                    <form method="post" action="/admin/content/<?= e((string) $record->id) ?>/withdraw">
                                        <?= Csrf::field() ?>
                                        <button class="button button-secondary button-small" type="submit">
                                            Withdraw
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">Withdrawn. Publish a new version to replace it.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (isset($errors['status'])): ?>
        <p class="field-error"><?= e($errors['status']) ?></p>
    <?php endif; ?>
</section>
