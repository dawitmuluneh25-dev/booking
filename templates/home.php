<?php
/**
 * The public landing page.
 *
 * This is the only page a stranger sees before deciding whether to stay, and for a
 * year it advertised a booking form the public cannot reach: `/bookings/new` needs
 * `bookings.manage`, so the one button on the page returned 403 to every visitor
 * who was not already signed in. It also described an intake that ADR-013 removed —
 * referrals come from clinicians who hold an account, not from anybody who lands
 * here (ADR-013, ADR-005: a request is not an appointment).
 *
 * So the page now describes the funnel that exists: request an account, get it
 * reviewed, sign in, refer. The words are functional, not marketing copy — the
 * owner-approved public text (BL-A1) has not been written yet, and this page does
 * not pretend to be it.
 *
 * @var array<string, string> $services
 */
?>
<section class="hero">
    <p class="eyebrow">Referring clinicians</p>
    <h1>Refer a patient.</h1>
    <p class="lede">
        Referrals come from registered clinicians, not from patients. Request an account and the
        clinic will review it; once it is approved you can sign in to send referrals and follow
        what becomes of them.
    </p>
    <p><a class="button" href="/request-account">Request a referring account</a></p>
    <p class="muted">Already hold an account? <a href="/login">Sign in</a>.</p>
</section>

<section class="grid" aria-labelledby="services-heading">
    <h2 id="services-heading" class="section-title">What you can refer for</h2>
    <ul class="cards">
        <?php foreach ($services as $key => $label): ?>
            <li class="card">
                <h3><?= e($label) ?></h3>
                <p class="muted">
                    Name the procedure in the referral. The clinical team reviews it before
                    anything is scheduled.
                </p>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="panel">
    <h2>How it works</h2>
    <ol class="steps">
        <li><strong>Request an account.</strong> The clinic reviews every request before an account exists.</li>
        <li><strong>Send the referral.</strong> You receive a reference immediately and can follow its progress.</li>
        <li>
            <strong>The clinical team decides.</strong> If the referral is accepted, the appointment
            is arranged and confirmed with you separately.
        </li>
    </ol>
</section>
