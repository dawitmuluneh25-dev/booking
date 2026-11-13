<?php

use App\Domain\PublishedContent;

/**
 * @var list<PublishedContent> $pages
 */
?>
<section class="hero">
    <p class="eyebrow">Patient information</p>
    <h1>Services</h1>
    <p class="lede">
        What each procedure is, why it may be requested, and what happens after a request
        reaches the team. These pages describe the process; they are not a decision about
        your care.
    </p>
</section>

<?php if ($pages === []): ?>
    <?php /*
     * An honest empty state rather than a placeholder page. Content is served only
     * when §17.5 is satisfied, so "nothing here" is the correct rendering of a set
     * of drafts that nobody has been able to publish yet.
     */ ?>
    <section class="panel">
        <h2>No service pages are available yet</h2>
        <p class="muted">
            The pages for each procedure are written and waiting for clinical sign-off before
            they are shown here. Please contact the department directly if you need information
            about a specific procedure in the meantime.
        </p>
    </section>
<?php else: ?>
    <section class="grid" aria-labelledby="service-list-heading">
        <h2 id="service-list-heading" class="section-title">Procedures</h2>
        <ul class="cards">
            <?php foreach ($pages as $page): ?>
                <li class="card">
                    <h3><a href="/services/<?= e($page->slug()) ?>"><?= e($page->title) ?></a></h3>
                    <p class="muted"><?= e($page->provenance()) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
