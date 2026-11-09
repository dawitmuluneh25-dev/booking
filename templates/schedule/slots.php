<?php

/**
 * The slot picker, used for both a new booking and a move.
 *
 * Every offered time has already been checked against the resource's opening
 * windows and its occupancy rows; the form submits one of those times. There is
 * no free-text time field, because a time a person types is a time the server has
 * not agreed to (PRD 7.4).
 *
 * @var string $mode            'book' or 'move'
 * @var string $action
 * @var string $dateAction
 * @var \App\Domain\Appointment|null $appointment
 * @var list<\App\Domain\Referral> $referrals
 * @var int    $selectedReferral
 * @var string $date
 * @var int    $duration
 * @var string $dateLabel
 * @var list<array{resource: \App\Domain\Resource, slots: list<array{utc: string, label: string}>}> $resourceSlots
 * @var string|null $notice
 * @var string|null $error
 * @var array<string, string> $fieldErrors
 */
?>
<section class="panel">
    <p class="eyebrow"><?= $mode === 'move' ? 'Move appointment' : 'New booking' ?></p>
    <h1><?= e($dateLabel) ?></h1>

    <?php if ($appointment !== null): ?>
        <p class="lede">
            <?= e($appointment->reference) ?> ·
            <?= e($appointment->patientName) ?> ·
            currently <?= e($appointment->startsAtUtc) ?> UTC
        </p>
    <?php endif; ?>

    <?php if ($notice !== null): ?>
        <p class="alert alert-success" role="status"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="alert alert-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($fieldErrors !== []): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($fieldErrors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="get" action="<?= e($dateAction) ?>" class="inline-form">
        <?php if ($mode === 'book'): ?>
            <input type="hidden" name="referral_id" value="<?= e($selectedReferral) ?>">
        <?php endif; ?>
        <label for="date">Date</label>
        <input id="date" type="date" name="date" value="<?= e($date) ?>" required>
        <label for="duration">Length</label>
        <select id="duration" name="duration_minutes"<?= $mode === 'move' ? ' disabled' : '' ?>>
            <?php foreach ([15, 30, 45, 60, 90, 120, 180, 240] as $minutes): ?>
                <option value="<?= e($minutes) ?>"<?= $minutes === $duration ? ' selected' : '' ?>>
                    <?= e($minutes) ?> min
                </option>
            <?php endforeach; ?>
        </select>
        <button class="button button-secondary" type="submit">Show times</button>
    </form>

    <form method="post" action="<?= e($action) ?>">
        <?= \App\Security\Csrf::field() ?>
        <input type="hidden" name="duration_minutes" value="<?= e($duration) ?>">

        <?php if ($mode === 'book'): ?>
            <label for="referral_id">Referral</label>
            <select id="referral_id" name="referral_id" required>
                <?php if ($referrals === []): ?>
                    <option value="">No referral is approved for scheduling.</option>
                <?php else: ?>
                    <?php foreach ($referrals as $referral): ?>
                        <option value="<?= e($referral->id) ?>"<?= $referral->id === $selectedReferral ? ' selected' : '' ?>>
                            <?= e($referral->reference) ?> · <?= e($referral->patientName) ?> ·
                            <?= e(str_replace('_', ' ', $referral->procedureCode)) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        <?php endif; ?>

        <?php if ($mode === 'move'): ?>
            <label for="reason">Reason (optional)</label>
            <input id="reason" name="reason" maxlength="255">
        <?php endif; ?>

        <fieldset>
            <legend>Available times</legend>

            <?php
            $offered = 0;
            foreach ($resourceSlots as $group) {
                $offered += count($group['slots']);
            }
            ?>

            <?php if ($offered === 0): ?>
                <p class="muted">
                    Nothing is free for this length on this date. Try another date or a shorter slot.
                </p>
            <?php endif; ?>

            <?php foreach ($resourceSlots as $group): ?>
                <?php if ($group['slots'] === []): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <h2><?= e($group['resource']->name) ?></h2>
                <div class="slot-grid">
                    <?php foreach ($group['slots'] as $slot): ?>
                        <label class="slot">
                            <input
                                type="radio"
                                name="slot"
                                value="<?= e($group['resource']->id . '|' . $slot['utc']) ?>"
                                required
                            >
                            <span><?= e($slot['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </fieldset>

        <button class="button" type="submit"><?= $mode === 'move' ? 'Move appointment' : 'Book appointment' ?></button>
    </form>

    <p class="muted">
        Times shown are <?= e($date) ?> in the clinic's local clock. A submitted time is re-checked
        against the room's occupancy before anything is written, so two people choosing the same
        slot produce one booking and one refusal.
    </p>
</section>
