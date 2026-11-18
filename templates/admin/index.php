<?php
/**
 * @var \App\Domain\User|null $currentUser
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Administration</h1>
    </div>

    <p class="lede">
        Configuration and operational views. These pages are limited to the administrator role;
        staff accounts can manage bookings but cannot change clinic settings or read diagnostics.
    </p>

    <div class="cards">
        <article class="card">
            <h3>Staff accounts</h3>
            <p>Accounts, roles and the state of each sign-in.</p>
            <a class="link" href="/admin/users">Open accounts</a>
            <a class="link" href="/admin/physicians">Referring physicians</a>
        </article>

        <article class="card">
            <h3>Referring account requests</h3>
            <p>
                Clinicians who asked for an account through the public form. Approving one
                creates the account; referral authority is granted separately by a clinical
                approver.
            </p>
            <a class="link" href="/admin/account-requests">Open the queue</a>
        </article>

        <article class="card">
            <h3>Opening hours</h3>
            <p>Weekly opening hours and one-off closed days.</p>
            <a class="link" href="/admin/hours">Edit hours</a>
            <a class="link" href="/admin/resources">Rooms and closures</a>
        </article>

        <article class="card">
            <h3>Laboratories</h3>
            <p>Where a specimen can be sent, as a serialised catalogue.</p>
            <a class="link" href="/admin/laboratories">Manage destinations</a>
        </article>

        <article class="card">
            <h3>Escalation routes</h3>
            <p>
                Who to telephone during an urgent case, and whether anybody has confirmed that the
                number is answered.
            </p>
            <a class="link" href="/admin/escalation-routes">Manage the roster</a>
        </article>

        <article class="card">
            <h3>Patient content</h3>
            <p>The words patients read, with §17.5's reviewer, approver and review date.</p>
            <a class="link" href="/admin/content">Review and publish</a>
        </article>

        <article class="card">
            <h3>Audit trail</h3>
            <p>Every status change with who made it and when.</p>
            <a class="link" href="/admin/audit">Review history</a>
        </article>

        <article class="card">
            <h3>Diagnostics</h3>
            <p>Schema state, notification backlog, backups and recent errors.</p>
            <a class="link" href="/admin/diagnostics">Open diagnostics</a>
        </article>
    </div>
</section>
