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
        </article>

        <article class="card">
            <h3>Diagnostics</h3>
            <p>Schema state, notification backlog, backups and recent errors.</p>
            <a class="link" href="/admin/diagnostics">Open diagnostics</a>
        </article>
    </div>
</section>
