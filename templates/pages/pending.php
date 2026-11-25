<?php

/**
 * The holding page for an approved policy that is not yet in force (D-12, 25 November 2026).
 *
 * Deliberately empty of legal content. A holding page that summarised the notice would be an
 * unreviewed policy statement on the most sensitive page of the site; one that promised a date it
 * could not keep would be worse. It therefore states two facts it can support — the page is not
 * published, and when it takes effect, if a record supplies that date — and nothing else.
 *
 * No approver, no document-control block, no review date: those are properties of a published page,
 * and printing them here would imply an act that has not happened yet.
 *
 * @var string       $title
 * @var string|null  $effectiveOn
 */
?>
<article class="panel" aria-labelledby="page-title">
    <p class="eyebrow">HakimeRAD</p>
    <h1 id="page-title"><?= e($title) ?></h1>

    <p>This page is not published yet.</p>

    <?php if ($effectiveOn !== null): ?>
        <p class="muted">It takes effect on <?= e($effectiveOn) ?>.</p>
    <?php endif; ?>
</article>
