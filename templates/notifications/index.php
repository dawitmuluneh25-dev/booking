<?php
/**
 * The in-app notification list.
 *
 * Reference and time, and nothing else. The page is a signpost, not a summary: the
 * detail lives behind the referral or booking, where object-level rules decide who
 * may read it.
 *
 * @var list<\App\Domain\InAppNotification> $notifications
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Notifications</h1>
    </div>

    <p class="muted">
        What the system has told people about, and when. Open the referral or the booking to see
        the detail you are entitled to.
    </p>

    <?php if ($notifications === []): ?>
        <p class="empty">Nothing has been sent yet.</p>
    <?php else: ?>
        <table class="data-table">
            <caption class="visually-hidden">Notifications, newest first</caption>
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">When</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notifications as $notification): ?>
                    <tr>
                        <td><?= e($notification->reference) ?></td>
                        <td>
                            <time datetime="<?= e($notification->occurredAt) ?>">
                                <?= e($notification->occurredAt) ?>
                            </time>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
