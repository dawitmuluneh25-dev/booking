<?php
/**
 * @var int $retryAfterMinutes
 */
?>
<section class="panel">
    <h1>Too many requests</h1>

    <p>
        This network has sent several requests recently. Please try again in about
        <?= e((string) $retryAfterMinutes) ?> minutes.
    </p>

    <p class="muted">
        If you are requesting an account on behalf of a practice and this blocks you,
        contact the clinic directly using the details on the institutional site.
    </p>
</section>
