<?php

/**
 * @var \App\Domain\Referral              $referral
 * @var list<\App\Domain\ReferralDocument> $documents
 * @var list<\App\Domain\DocumentKind>    $kinds
 * @var list<string>                      $accepted
 * @var int                               $maxMegabytes
 * @var bool                              $canUpload
 * @var bool                              $canReview
 * @var bool                              $canIssueLink
 * @var string|null                       $statusLink
 * @var string|null                       $notice
 * @var string|null                       $error
 * @var array<string, string>             $fieldErrors
 */

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.') . ' MiB';
    }

    return max(1, (int) ceil($bytes / 1024)) . ' KiB';
};

$statusTone = static fn (\App\Domain\DocumentScanStatus $status): string => match ($status) {
    \App\Domain\DocumentScanStatus::Clean, \App\Domain\DocumentScanStatus::NotRequired => 'ok',
    \App\Domain\DocumentScanStatus::Pending => 'warn',
    default => 'bad',
};
?>
<section class="panel">
    <p class="eyebrow">Supporting documents</p>
    <h1>Referral <?= e($referral->reference) ?></h1>
    <p class="lede">
        <?= e($referral->status->label()) ?> ·
        <?= e(str_replace('_', ' ', $referral->procedureCode)) ?>
    </p>

    <?php if ($notice !== null): ?>
        <p class="alert alert-success" role="status"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($fieldErrors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>The document was not stored:</p>
            <ul>
                <?php foreach ($fieldErrors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <h2>Attached files</h2>

    <?php if ($documents === []): ?>
        <p class="muted">No documents have been attached to this referral.</p>
    <?php else: ?>
        
        <div class="table-wrap">
        <table>
            <caption class="visually-hidden">Attached documents</caption>
            <thead>
            <tr>
                <th scope="col">Kind</th>
                <th scope="col">File</th>
                <th scope="col">Size</th>
                <th scope="col">State</th>
                <th scope="col">Added</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($documents as $document): ?>
                <tr>
                    <td><?= e($document->kind->label()) ?></td>
                    <td>
                        <?= e($document->originalName) ?>
                        <?php if ($document->deletedAtUtc !== null): ?>
                            <span class="muted">(removed from storage)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($formatBytes($document->byteSize)) ?></td>
                    <td>
                        <span class="badge badge-<?= e($statusTone($document->scanStatus)) ?>">
                            <?= e($document->scanStatus->label()) ?>
                        </span>
                    </td>
                    <td><?= e($document->createdAtUtc) ?> UTC</td>
                    <td>
                        <?php if ($document->isServable()): ?>
                            <a class="link" href="/documents/<?= e($document->documentRef) ?>/download">Download</a>
                        <?php elseif ($document->scanStatus === \App\Domain\DocumentScanStatus::Pending): ?>
                            <span class="muted">Awaiting review</span>
                        <?php else: ?>
                            <span class="muted">Withheld</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($canReview && $document->scanStatus === \App\Domain\DocumentScanStatus::Pending): ?>
                    <tr>
                        <td colspan="6">
                            <form method="post" action="/documents/<?= e($document->documentRef) ?>/review" class="inline-form">
                                <?= \App\Security\Csrf::field() ?>
                                <label for="verdict-<?= e($document->documentRef) ?>">Review outcome</label>
                                <select id="verdict-<?= e($document->documentRef) ?>" name="verdict" required>
                                    <option value="clean">Released</option>
                                    <option value="infected">Quarantine - content not acceptable</option>
                                    <option value="error">Quarantine - could not be reviewed</option>
                                </select>
                                <label for="note-<?= e($document->documentRef) ?>">Note</label>
                                <input id="note-<?= e($document->documentRef) ?>" name="note" maxlength="190">
                                <button class="button button-secondary" type="submit">Record review</button>
                            </form>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if ($canUpload): ?>
        <h2>Add a document</h2>
        <form method="post" action="/referrals/<?= e($referral->reference) ?>/documents" enctype="multipart/form-data">
            <?= \App\Security\Csrf::field() ?>

            <label for="kind">Document kind</label>
            <select id="kind" name="kind">
                <?php foreach ($kinds as $kind): ?>
                    <option value="<?= e($kind->value) ?>"><?= e($kind->label()) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="document">File</label>
            <input
                id="document"
                name="document"
                type="file"
                required
                accept="<?= e(implode(',', array_map(static fn (string $extension): string => '.' . $extension, $accepted))) ?>"
            >
            <p class="muted">
                <?= e(implode(', ', array_map('strtoupper', $accepted))) ?> only, up to
                <?= e($maxMegabytes) ?> MiB. Files are stored privately and are not visible to anyone
                until an authorised reviewer releases them.
            </p>

            <button class="button" type="submit">Upload</button>
        </form>
    <?php endif; ?>

    <?php if ($canIssueLink): ?>
        <h2>Status link</h2>
        <?php if ($statusLink !== null): ?>
            <p class="alert" role="status">
                Copy this link now; it is shown once and works once.<br>
                <code><?= e($statusLink) ?></code>
            </p>
        <?php endif; ?>
        <form method="post" action="/referrals/<?= e($referral->reference) ?>/status-link" class="inline-form">
            <?= \App\Security\Csrf::field() ?>
            <button class="button button-secondary" type="submit">Create a single-use status link</button>
        </form>
        <p class="muted">
            The link contains no name or reference, expires on its own, and stops working the first
            time it is opened.
        </p>
    <?php endif; ?>
</section>
