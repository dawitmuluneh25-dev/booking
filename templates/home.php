<?php
/**
 * The public landing page.
 *
 * HR-ENG-DEC-R1-003 D-05 approved this copy for Release 1: a headline, a supporting statement, three
 * service descriptions, a five-step process and two calls to action. The words here are that copy,
 * character for character, because approved public text is not a draft to be improved by whoever
 * edits the template next — `HomePageCopyTest` pins every sentence and fails if one is reworded.
 *
 * Two things the template deliberately does *not* do:
 *
 *   It does not point either call to action at a route that does not answer. `/bookings/new` needs
 *   `bookings.manage` and returns 403 to every visitor who is not signed in — that was the defect of
 *   18 November (§11). `/referral-guidance` is a public route that returns 404 until Legal approves
 *   the page, so linking it today would be the same defect with a different URL. Both calls to
 *   action therefore point at routes that work now; the professional one moves to
 *   `/referral-guidance` the day that page publishes, and that is a one-line change.
 *
 *   It does not describe an intake that ADR-013 removed. Referrals come from clinicians who hold an
 *   account, so the primary action starts the account request rather than pretending a stranger can
 *   submit a referral. §33 records this as a deviation from D-05's implied route, with the question
 *   the owner has to answer.
 */
?>
<section class="hero">
    <p class="eyebrow">HakimeRAD Radiologic Consultancy</p>
    <h1>Specialist Image-Guided Interventional Radiology Care</h1>
    <p class="lede">
        HakimeRAD Radiologic Consultancy coordinates referrals, specialist review, and procedure
        planning for minimally invasive image-guided interventions. Requests are reviewed by the
        appropriate clinical team before any procedure is approved or scheduled.
    </p>
    <p><a class="button" href="/request-account">Request a Procedure Assessment</a></p>
    <p class="muted"><a href="/login">For Healthcare Professionals — Referral Guidance</a></p>
</section>

<section class="grid" aria-labelledby="services-heading">
    <h2 id="services-heading" class="section-title">Procedures and referrals</h2>
    <ul class="cards">
        <li class="card">
            <h3>Image-Guided Biopsy and FNAC</h3>
            <p class="muted">
                Specialist referral and assessment for image-guided fine needle aspiration cytology
                and core needle biopsy.
            </p>
        </li>
        <li class="card">
            <h3>Image-Guided Abscess Drainage</h3>
            <p class="muted">
                Referral and clinical coordination for image-guided aspiration and percutaneous
                drainage of selected fluid collections.
            </p>
        </li>
        <li class="card">
            <h3>Hydrostatic Intussusception Reduction</h3>
            <p class="muted">
                An urgent hospital-based coordination pathway requiring direct communication with
                the receiving clinical team.
            </p>
        </li>
    </ul>
</section>

<section class="panel">
    <h2>How the process works</h2>
    <ol class="steps">
        <li>Submit a referral or eligible procedure request.</li>
        <li>The team reviews the submitted clinical information.</li>
        <li>The appropriate clinician determines suitability and the next step.</li>
        <li>Approved procedures proceed to resource coordination and scheduling.</li>
        <li>The team coordinates attendance and follow-up as applicable.</li>
    </ol>
</section>
