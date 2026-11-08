<?php
/**
 * @var list<array{weekday: int, label: string, closed: bool, opens: string, closes: string}> $rows
 * @var list<array{date: string, reason: string|null}> $closures
 * @var array<string, string> $errors
 * @var string|null           $notice
 * @var string                $timezone
 * @var bool                  $unavailable
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Opening hours</h1>
        <a class="button button-secondary button-small" href="/admin">Back</a>
    </div>

    <?php if ($unavailable): ?>
        <div class="alert alert-error" role="alert">
            Opening hours cannot be loaded right now. Check the database connection.
        </div>
    <?php else: ?>
        <?php if ($notice !== null): ?>
            <div class="alert alert-success" role="status"><?= e($notice) ?></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error" role="alert">
                <p>The hours were not saved:</p>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p class="muted">
            Times are wall-clock values in <strong><?= e($timezone) ?></strong>. Appointments start every
            <?= e(\App\Domain\BookingService::SLOT_MINUTES) ?> minutes and must finish by the closing time,
            so a 17:00 close means the last start is 16:30. These hours also apply to the public booking form.
        </p>

        <form method="post" action="/admin/hours">
            <?= \App\Security\Csrf::field() ?>
            <input type="hidden" name="action" value="save_week">

            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Weekly opening hours</caption>
                    <thead>
                        <tr>
                            <th scope="col">Day</th>
                            <th scope="col">Closed</th>
                            <th scope="col">Opens</th>
                            <th scope="col">Closes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <th scope="row"><?= e($row['label']) ?></th>
                                <td>
                                    <label class="visually-hidden" for="closed-<?= e($row['weekday']) ?>">
                                        Closed on <?= e($row['label']) ?>
                                    </label>
                                    <input
                                        id="closed-<?= e($row['weekday']) ?>"
                                        type="checkbox"
                                        name="hours[<?= e($row['weekday']) ?>][closed]"
                                        value="1"
                                        <?= $row['closed'] ? 'checked' : '' ?>
                                    >
                                </td>
                                <td>
                                    <label class="visually-hidden" for="opens-<?= e($row['weekday']) ?>">
                                        Opening time on <?= e($row['label']) ?>
                                    </label>
                                    <input
                                        id="opens-<?= e($row['weekday']) ?>"
                                        type="time"
                                        name="hours[<?= e($row['weekday']) ?>][opens]"
                                        value="<?= e($row['opens']) ?>"
                                        step="900"
                                    >
                                </td>
                                <td>
                                    <label class="visually-hidden" for="closes-<?= e($row['weekday']) ?>">
                                        Closing time on <?= e($row['label']) ?>
                                    </label>
                                    <input
                                        id="closes-<?= e($row['weekday']) ?>"
                                        type="time"
                                        name="hours[<?= e($row['weekday']) ?>][closes]"
                                        value="<?= e($row['closes']) ?>"
                                        step="900"
                                    >
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="actions">
                <button class="button" type="submit">Save hours</button>
            </div>
        </form>

        <h2 class="section-title">One-off closed days</h2>

        <?php if ($closures === []): ?>
            <p class="empty">No closed days. The weekly hours apply to every week.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">One-off closed days</caption>
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($closures as $closure): ?>
                            <tr>
                                <td><?= e($closure['date']) ?></td>
                                <td><?= e($closure['reason'] ?? '—') ?></td>
                                <td class="row-actions">
                                    <form method="post" action="/admin/hours" class="inline-form">
                                        <?= \App\Security\Csrf::field() ?>
                                        <input type="hidden" name="action" value="remove_closure">
                                        <input type="hidden" name="closed_on" value="<?= e($closure['date']) ?>">
                                        <button class="button button-secondary button-small" type="submit">
                                            Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/hours" class="day-form">
            <?= \App\Security\Csrf::field() ?>
            <input type="hidden" name="action" value="add_closure">

            <label for="closed_on">Close a day</label>
            <input id="closed_on" name="closed_on" type="date" required>

            <label for="reason">Reason <span class="muted">(optional)</span></label>
            <input id="reason" name="reason" type="text" maxlength="120" placeholder="Public holiday">

            <button class="button button-small" type="submit">Add</button>
        </form>
    <?php endif; ?>
</section>
