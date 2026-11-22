<?php

use App\Domain\PublishedContent;

/**
 * A public policy page.
 *
 * Deliberately plain: a privacy notice or a booking policy is read for its content,
 * not for its layout, and nothing here should distract from the wording the owners
 * approved. The provenance line is not decoration — a record without an approver and
 * a review date cannot reach this template, because it cannot be published.
 *
 * @var PublishedContent $page
 */
$sections = $page->sections();
?>
<article class="panel" aria-labelledby="page-title">
    <p class="eyebrow">HakimeRAD</p>
    <h1 id="page-title"><?= e($page->title) ?></h1>

    <p class="muted"><?= e($page->provenance()) ?></p>

    <?php /*
     * D-01: the effective date and the review date are visible fields on each policy page, printed
     * from the record. The partial is shared with the service template so the two cannot drift.
     */ ?>
    <?php require __DIR__ . '/../partials/document-control.php'; ?>

    <?php if ($page->supersededVersion !== null): ?>
        <p class="muted">Replaces version <?= e($page->supersededVersion) ?> of this page.</p>
    <?php endif; ?>

    <?php foreach ($sections as $section): ?>
        <section>
            <h2><?= e($section['heading']) ?></h2>

            <?php foreach ($section['paragraphs'] as $paragraph): ?>
                <p><?= e($paragraph) ?></p>
            <?php endforeach; ?>

            <?php if ($section['bullets'] !== []): ?>
                <ul class="plain-list">
                    <?php foreach ($section['bullets'] as $bullet): ?>
                        <li><?= e($bullet) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($section['faq'] !== []): ?>
                <dl class="details">
                    <?php foreach ($section['faq'] as $entry): ?>
                        <dt><strong><?= e($entry['question']) ?></strong></dt>
                        <dd><?= e($entry['answer']) ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <p class="muted">Version <?= e($page->version) ?>, <?= e($page->language === 'en' ? 'English' : $page->language) ?>.</p>
</article>
