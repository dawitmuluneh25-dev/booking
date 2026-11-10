<?php
/**
 * @var list<array{resource: \App\Domain\Resource, blocks: list<array{block: \App\Domain\ResourceBlock, label: string}>}> $rows
 * @var array<string, string> $errors
 * @var string|null           $notice
 * @var string                $timezone
 */
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
            <p>The closure was not recorded:</p>
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
        cannot book into it. Removing a closure never touches an appointment.
    </p>

    <?php foreach ($rows as $row): ?>
        <h2 class="section-title"><?= e($row['resource']->label()) ?></h2>
        <p class="muted">Code <code><?= e($row['resource']->code) ?></code></p>

        <?php if ($row['blocks'] === []): ?>
            <p>No closures scheduled.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <caption class="visually-hidden">Closures for <?= e($row['resource']->name) ?></caption>
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
