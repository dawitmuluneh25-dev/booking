<?php
/**
 * @var list<array{
 *     resource: \App\Domain\Resource,
 *     blocks: list<array{block: \App\Domain\ResourceBlock, label: string}>,
 *     week: array<int, array{opens: string, closes: string}|null>
 * }> $rows
 * @var array<string, string> $errors
 * @var string|null           $notice
 * @var string                $timezone
 */
$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
?>
<section class="panel">
    <div class="panel-head">
        <h1>Rooms and closures</h1>
        <a class="button button-secondary button-small" href="/admin">Back</a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>That was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="muted">
        Times are entered in <strong><?= e($timezone) ?></strong>. A closure takes the room out of the
        slot picker and is refused by the booking transaction itself, so a hand-crafted form post
        cannot book into it. A room is never deleted — deactivating it keeps its appointments and
        closures on record.
    </p>

    <?php foreach ($rows as $row): ?>
        <?php $resource = $row['resource']; ?>
        <h2 class="section-title">
            <?= e($resource->label()) ?>
            <?php if (!$resource->isActive): ?>
                <span class="badge badge-superseded">Inactive — not offered</span>
            <?php endif; ?>
        </h2>
        <p class="muted">Code <code><?= e($resource->code) ?></code></p>

        <details>
            <summary>Rename or deactivate</summary>
            <form method="post" action="/admin/resources/<?= e((string) $resource->id) ?>">
                <?= \App\Security\Csrf::field() ?>

                <div class="form-row">
                    <label for="name-<?= e((string) $resource->id) ?>">Name</label>
                    <input
                        id="name-<?= e((string) $resource->id) ?>"
                        type="text"
                        name="name"
                        value="<?= e($resource->name) ?>"
                        required
                    >
                </div>

                <div class="form-row">
                    <label for="kind-<?= e((string) $resource->id) ?>">Kind</label>
                    <select id="kind-<?= e((string) $resource->id) ?>" name="kind">
                        <?php foreach (['suite', 'room'] as $kind): ?>
                            <option value="<?= e($kind) ?>" <?= $resource->kind === $kind ? 'selected' : '' ?>>
                                <?= e(ucfirst($kind)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="modality-<?= e((string) $resource->id) ?>">Modality</label>
                    <input
                        id="modality-<?= e((string) $resource->id) ?>"
                        type="text"
                        name="modality"
                        maxlength="60"
                        value="<?= e($resource->modality ?? '') ?>"
                    >
                </div>

                <div class="form-row">
                    <label for="active-<?= e((string) $resource->id) ?>">
                        <input
                            id="active-<?= e((string) $resource->id) ?>"
                            type="checkbox"
                            name="active"
                            value="1"
                            <?= $resource->isActive ? 'checked' : '' ?>
                        >
                        Available for booking
                    </label>
                </div>

                <button class="button" type="submit">Save room</button>
            </form>
        </details>

        <details>
            <summary>Weekly opening hours</summary>
            <form method="post" action="/admin/resources/<?= e((string) $resource->id) ?>/week">
                <?= \App\Security\Csrf::field() ?>

                <div class="table-wrap">
                    <table>
                        <caption class="visually-hidden">Default week for <?= e($resource->name) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col">Day</th>
                                <th scope="col">Opens</th>
                                <th scope="col">Closes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($weekdays as $weekday => $label): ?>
                                <?php $window = $row['week'][$weekday] ?? null; ?>
                                <tr>
                                    <th scope="row"><?= e($label) ?></th>
                                    <td>
                                        <label class="visually-hidden" for="open-<?= e((string) $resource->id) ?>-<?= e((string) $weekday) ?>">
                                            <?= e($label) ?> opens
                                        </label>
                                        <input
                                            id="open-<?= e((string) $resource->id) ?>-<?= e((string) $weekday) ?>"
                                            type="time"
                                            name="week_<?= e((string) $weekday) ?>_opens"
                                            value="<?= e($window === null ? '' : substr($window['opens'], 0, 5)) ?>"
                                        >
                                    </td>
                                    <td>
                                        <label class="visually-hidden" for="close-<?= e((string) $resource->id) ?>-<?= e((string) $weekday) ?>">
                                            <?= e($label) ?> closes
                                        </label>
                                        <input
                                            id="close-<?= e((string) $resource->id) ?>-<?= e((string) $weekday) ?>"
                                            type="time"
                                            name="week_<?= e((string) $weekday) ?>_closes"
                                            value="<?= e($window === null ? '' : substr($window['closes'], 0, 5)) ?>"
                                        >
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="muted">Leave both fields empty to close that day. A day needs both times.</p>

                <button class="button" type="submit">Save hours</button>
            </form>
        </details>

        <h3>Closures</h3>
        <?php if ($row['blocks'] === []): ?>
            <p>No closures scheduled.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Closures for <?= e($resource->name) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Window</th>
                            <th scope="col">Reason</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($row['blocks'] as $entry): ?>
                            <tr>
                                <th scope="row"><?= e($entry['label']) ?></th>
                                <td><?= e($entry['block']->reason) ?></td>
                                <td>
                                    <form method="post" action="/admin/resources" class="inline-form">
                                        <?= \App\Security\Csrf::field() ?>
                                        <input type="hidden" name="action" value="remove_block">
                                        <input type="hidden" name="block_id" value="<?= e((string) $entry['block']->id) ?>">
                                        <button class="link-button" type="submit">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <h2 class="section-title">Add a room</h2>
    <form method="post" action="/admin/resources/create">
        <?= \App\Security\Csrf::field() ?>

        <div class="form-row">
            <label for="new-code">Code</label>
            <input id="new-code" type="text" name="code" maxlength="40" required>
        </div>

        <div class="form-row">
            <label for="new-name">Name</label>
            <input id="new-name" type="text" name="name" maxlength="120" required>
        </div>

        <div class="form-row">
            <label for="new-kind">Kind</label>
            <select id="new-kind" name="kind">
                <option value="suite">Suite</option>
                <option value="room" selected>Room</option>
            </select>
        </div>

        <div class="form-row">
            <label for="new-modality">Modality</label>
            <input id="new-modality" type="text" name="modality" maxlength="60">
        </div>

        <p class="muted">
            The code is permanent: reports group by it. Opening hours come next — a room with no
            hours is never offered.
        </p>

        <button class="button" type="submit">Add room</button>
    </form>

    <h2 class="section-title">Close a window</h2>
    <form method="post" action="/admin/resources">
        <?= \App\Security\Csrf::field() ?>
        <input type="hidden" name="action" value="add_block">

        <div class="form-row">
            <label for="resource_id">Room or suite</label>
            <select id="resource_id" name="resource_id" required>
                <?php foreach ($rows as $row): ?>
                    <option value="<?= e((string) $row['resource']->id) ?>">
                        <?= e($row['resource']->label()) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label for="starts_at">Starts</label>
            <input id="starts_at" type="datetime-local" name="starts_at" required>
        </div>

        <div class="form-row">
            <label for="ends_at">Ends</label>
            <input id="ends_at" type="datetime-local" name="ends_at" required>
        </div>

        <div class="form-row">
            <label for="reason">Reason</label>
            <input id="reason" type="text" name="reason" maxlength="255" required>
        </div>

        <button class="button" type="submit">Record closure</button>
    </form>
</section>
