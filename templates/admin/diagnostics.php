<?php
/**
 * @var list<array{label: string, value: string, state: string}> $checks
 * @var array{pending: int, sending: int, sent: int, failed: int, dead: int, oldest_minutes: int|null}|null $outbox
 * @var array<string, int> $bookings
 * @var list<\App\Support\LogRecord> $errors
 * @var list<\App\Support\BackupFile> $backups
 * @var bool $unavailable
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Diagnostics</h1>
        <a class="button button-secondary button-small" href="/admin/diagnostics">Refresh</a>
    </div>

    <?php if ($unavailable): ?>
        <div class="alert alert-error" role="alert">
            Diagnostics could not be collected. The database may be unavailable; check
            <code>php bin/console doctor</code> on the host.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Operational checks</caption>
                <thead>
                    <tr>
                        <th scope="col">Check</th>
                        <th scope="col">State</th>
                        <th scope="col">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $check): ?>
                        <tr>
                            <th scope="row"><?= e($check['label']) ?></th>
                            <td>
                                <span class="state state-<?= e($check['state']) ?>">
                                    <?= e(strtoupper($check['state'])) ?>
                                </span>
                            </td>
                            <td><?= e($check['value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h2 class="section-title">Work queue</h2>
        <?php if ($outbox === null): ?>
            <p class="empty">The outbox cannot be read right now.</p>
        <?php else: ?>
            <div class="grid">
                <div class="details">
                    <div><dt>Pending</dt><dd><?= e($outbox['pending']) ?></dd></div>
                    <div><dt>In flight</dt><dd><?= e($outbox['sending']) ?></dd></div>
                    <div><dt>Sent</dt><dd><?= e($outbox['sent']) ?></dd></div>
                    <div><dt>Failed</dt><dd><?= e($outbox['failed']) ?></dd></div>
                    <div><dt>Dead</dt><dd><?= e($outbox['dead']) ?></dd></div>
                    <div>
                        <dt>Oldest pending</dt>
                        <dd><?= $outbox['oldest_minutes'] === null ? '—' : e($outbox['oldest_minutes']) . ' min' ?></dd>
                    </div>
                </div>
            </div>
            <p class="muted">
                Dispatch runs from cron: <code>php bin/console outbox:dispatch</code>.
                A growing pending count means cron is not running.
            </p>
        <?php endif; ?>

        <h2 class="section-title">Bookings by status</h2>
        <?php if ($bookings === []): ?>
            <p class="empty">No bookings yet.</p>
        <?php else: ?>
            <div class="details">
                <?php foreach ($bookings as $status => $count): ?>
                    <div><dt><?= e(ucfirst($status)) ?></dt><dd><?= e($count) ?></dd></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Recent errors</h2>
        <?php if ($errors === []): ?>
            <p class="empty">No errors in the retained logs.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Recent error log entries</caption>
                    <thead>
                        <tr>
                            <th scope="col">Time (UTC)</th>
                            <th scope="col">Message</th>
                            <th scope="col">Context</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $error): ?>
                            <tr>
                                <td><?= e(str_replace('T', ' ', substr($error->timestamp, 0, 19))) ?></td>
                                <td><?= e($error->message) ?></td>
                                <td class="muted"><?= e($error->contextLine()) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="section-title">Backups</h2>
        <?php if ($backups === []): ?>
            <p class="empty">
                No backup yet. Run <code>php bin/console db:backup</code> and schedule it from cron.
            </p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Recent database backups</caption>
                    <thead>
                        <tr>
                            <th scope="col">File</th>
                            <th scope="col">Size</th>
                            <th scope="col">Written (UTC)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td><?= e($backup->name) ?></td>
                                <td><?= e($backup->humanSize()) ?></td>
                                <td><?= e(gmdate('Y-m-d H:i', $backup->modifiedAt)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
