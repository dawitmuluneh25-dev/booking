<?php
/**
 * @var array<string, string> $services
 * @var array<string, string> $errors
 * @var array<string, mixed>  $old
 */
$value = static fn (string $key): string => is_string($old[$key] ?? null) ? (string) $old[$key] : '';
$fieldError = static fn (string $key): ?string => $errors[$key] ?? null;
?>
<section class="panel">
    <h1>New booking request</h1>
    <p class="muted">Fields marked with an asterisk are required.</p>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p><strong>Please fix the following:</strong></p>
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/bookings" novalidate>
        <?= \App\Security\Csrf::field() ?>

        <div class="field">
            <label for="patient_name">Full name <span aria-hidden="true">*</span></label>
            <input
                id="patient_name"
                name="patient_name"
                type="text"
                value="<?= e($value('patient_name')) ?>"
                maxlength="120"
                autocomplete="name"
                required
                <?php if ($fieldError('patient_name') !== null): ?>aria-invalid="true" aria-describedby="error-patient_name"<?php endif; ?>
            >
            <?php if ($fieldError('patient_name') !== null): ?>
                <p class="field-error" id="error-patient_name"><?= e($fieldError('patient_name')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="patient_phone">Phone number <span aria-hidden="true">*</span></label>
            <input
                id="patient_phone"
                name="patient_phone"
                type="tel"
                value="<?= e($value('patient_phone')) ?>"
                maxlength="20"
                autocomplete="tel"
                required
                <?php if ($fieldError('patient_phone') !== null): ?>aria-invalid="true" aria-describedby="error-patient_phone"<?php endif; ?>
            >
            <?php if ($fieldError('patient_phone') !== null): ?>
                <p class="field-error" id="error-patient_phone"><?= e($fieldError('patient_phone')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="service">Service <span aria-hidden="true">*</span></label>
            <select
                id="service"
                name="service"
                required
                <?php if ($fieldError('service') !== null): ?>aria-invalid="true" aria-describedby="error-service"<?php endif; ?>
            >
                <option value="">Select a service</option>
                <?php foreach ($services as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $value('service') === $key ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($fieldError('service') !== null): ?>
                <p class="field-error" id="error-service"><?= e($fieldError('service')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="scheduled_at">Preferred date and time <span aria-hidden="true">*</span></label>
            <input
                id="scheduled_at"
                name="scheduled_at"
                type="datetime-local"
                value="<?= e($value('scheduled_at')) ?>"
                required
                <?php if ($fieldError('scheduled_at') !== null): ?>aria-invalid="true" aria-describedby="error-scheduled_at"<?php endif; ?>
            >
            <?php if ($fieldError('scheduled_at') !== null): ?>
                <p class="field-error" id="error-scheduled_at"><?= e($fieldError('scheduled_at')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="notes">Anything we should know? <span class="muted">(optional)</span></label>
            <textarea
                id="notes"
                name="notes"
                rows="4"
                maxlength="1000"
                <?php if ($fieldError('notes') !== null): ?>aria-invalid="true" aria-describedby="error-notes"<?php endif; ?>
            ><?= e($value('notes')) ?></textarea>
            <?php if ($fieldError('notes') !== null): ?>
                <p class="field-error" id="error-notes"><?= e($fieldError('notes')) ?></p>
            <?php endif; ?>
        </div>

        <div class="actions">
            <button class="button" type="submit">Send request</button>
            <a class="link" href="/">Cancel</a>
        </div>
    </form>
</section>
