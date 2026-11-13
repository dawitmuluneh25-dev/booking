<?php

use App\Domain\PublishedContent;

/**
 * @var PublishedContent $page
 */
$sections = $page->sections();
?>
<article class="panel" aria-labelledby="service-title">
    <p class="eyebrow">Patient information</p>
    <h1 id="service-title"><?= e($page->title) ?></h1>

    <?php /*
     * §17.5 requires the approver and the review date to appear with the page. Both
     * come from the content record — the page cannot render an approval it does not
     * have, because a record without one cannot be published in the first place.
     */ ?>
    <p class="muted"><?= e($page->provenance()) ?></p>

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

    <p class="muted">
        This page is educational and describes the referral process; it is not a
        patient-specific instruction or a substitute for a consent discussion.
    </p>

    <p class="muted">Version <?= e($page->version) ?>, <?= e($page->language === 'en' ? 'English' : $page->language) ?>.</p>
</article>
