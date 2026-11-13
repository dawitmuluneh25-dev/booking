<?php
/**
 * @var \App\Domain\User $currentUser
 * @var list<\App\Domain\LaboratoryDestination> $destinations
 * @var int $activeCount
 * @var string $nextLabel
 * @var bool $seriesFull
 * @var string|null $notice
 * @var array<string, string> $errors
 */
?>
<section class="panel">
    <div class="panel-head">
        <h1>Laboratories</h1>
        <div class="panel-actions">
            <a class="button button-secondary button-small" href="/admin">Administration</a>
        </div>
    </div>

    <?php if ($notice !== null): ?>
        <p class="alert alert-info"><?= e($notice) ?></p>
    <?php endif; ?>

    <p class="lede">
        Where a specimen can be sent. A specimen record stores a reference to one of
        these, so the destination can be counted and audited rather than typed in each
        time — which is the difference between &ldquo;sent to pathology&rdquo; and knowing
        which laboratory has the slide.
    </p>

    <p class="muted">
        The series is serialised: Pathology Lab 1, Pathology Lab 2, and so on. There is
        no name field on purpose. The approved decision fixed the scheme and left the
        number of laboratories open, and a free-text name would let a destination drift
        away from what the laboratory is actually called — including on specimens
        already sent. Real names arrive as a change with content behind it.
    </p>

    <?php if ($destinations === []): ?>
        <p><strong>No laboratory is configured.</strong> Until one exists, a specimen
            record has nowhere to point, so this is the first thing to add.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Configured laboratory destinations</caption>
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Label</th>
                        <th scope="col">Available for new specimens</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($destinations as $destination): ?>
                        <tr>
                            <th scope="row" class="mono"><?= e($destination->code()) ?></th>
                            <td><?= e($destination->label()) ?></td>
                            <td>
                                <?= $destination->isActive
                                    ? 'Yes'
                                    : 'No — closed, still named on earlier specimens' ?>
                            </td>
                            <td>
                                <form method="post" action="/admin/laboratories/<?= e((string) $destination->id) ?>">
                                    <?= \App\Security\Csrf::field() ?>
                                    <button class="button button-secondary button-small" type="submit">
                                        <?= $destination->isActive ? 'Close to new specimens' : 'Reopen' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="muted">
            <?= e((string) $activeCount) ?> of <?= e((string) count($destinations)) ?>
            <?= count($destinations) === 1 ? 'laboratory is' : 'laboratories are' ?> open.
            Closing one does not remove it: a specimen sent there keeps naming it.
        </p>
    <?php endif; ?>

    <?php if (isset($errors['serial_no'])): ?>
        <p class="field-error"><?= e($errors['serial_no']) ?></p>
    <?php endif; ?>

    <?php if ($seriesFull): ?>
        <p class="muted">
            The serial series is full. Another laboratory needs a real naming scheme rather
            than the next number.
        </p>
    <?php else: ?>
        <h2 class="section-title">Add the next laboratory</h2>
        <p class="muted">
            This will add <strong><?= e($nextLabel) ?></strong>. The number comes from the
            catalogue; it is not typed in, so it cannot be duplicated or skipped.
        </p>
        <form method="post" action="/admin/laboratories" class="stack">
            <?= \App\Security\Csrf::field() ?>
            <div class="form-actions">
                <button class="button" type="submit">Add <?= e($nextLabel) ?></button>
            </div>
        </form>
    <?php endif; ?>
</section>
