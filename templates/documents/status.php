<?php

/**
 * The page a single-use status link opens.
 *
 * Deliberately minimal: a reference, the state of the request, and the procedure
 * it is for. No name, no phone number, no clinical summary. The token in the URL
 * is the only credential, so the page refuses to become a second copy of the
 * referral just because somebody holds the link.
 *
 * @var \App\Domain\Referral $referral
 * @var string               $timezone
 */
?>
<section class="panel">
    <p class="eyebrow">Request status</p>
    <h1>Referral <?= e($referral->reference) ?></h1>

    <dl class="details">
        <div>
            <dt>Status</dt>
            <dd><?= e($referral->status->label()) ?></dd>
        </div>
        <div>
            <dt>Procedure</dt>
            <dd><?= e(ucfirst(str_replace('_', ' ', $referral->procedureCode))) ?></dd>
        </div>
        <div>
            <dt>Submitted</dt>
            <dd><?= e($referral->createdAtUtc) ?> UTC</dd>
        </div>
    </dl>

    <p class="muted">
        This link works once and then stops. If you need to check again, ask the team for a new one.
        A submitted request is not a confirmed appointment.
    </p>
</section>
