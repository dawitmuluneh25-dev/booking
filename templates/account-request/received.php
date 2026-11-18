<?php
/**
 * The single success page. Reached whether a row was written, collapsed as a
 * duplicate, or discarded by the honeypot — a different page for any of those would
 * tell a stranger something about the address they typed.
 *
 * @var string $reference
 */
?>
<section class="panel">
    <p class="eyebrow">Request received</p>
    <h1>Thank you — your request is with the clinic</h1>

    <p>
        Your reference is <strong class="reference"><?= e($reference) ?></strong>.
    </p>

    <p class="muted">
        The practice will be contacted at the address you gave. Please keep the reference
        if you need to follow up. No email has been sent by this page.
    </p>

    <p class="muted">
        An account is created by the clinic, and referral access is granted separately
        after your professional registration is verified. An account on its own does not
        let referrals be accepted.
    </p>

    <p><a class="button button-secondary" href="/">Back to the start</a></p>
</section>
