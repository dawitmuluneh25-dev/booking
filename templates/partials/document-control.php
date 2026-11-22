<?php

use App\Domain\PublishedContent;

/**
 * The document-control block: the dates a reader is entitled to see, from the record.
 *
 * Shared by the policy and service templates so that the effective date and the next review date
 * are printed by one piece of code. Two copies of this markup would have been two places for the
 * date to be formatted differently on two pages that are governed by the same decision.
 *
 * Expects `$page` to be a PublishedContent in scope. Renders nothing when the record holds no
 * dates, which is the state of a draft and of every page before D-01 fixed them.
 *
 * @var PublishedContent $page
 */
$control = $page->documentControl();
?>
<?php if ($control !== []): ?>
    <dl class="details" aria-label="Document control">
        <?php foreach ($control as $entry): ?>
            <dt><?= e($entry['label']) ?></dt>
            <dd><?= e($entry['value']) ?></dd>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>
