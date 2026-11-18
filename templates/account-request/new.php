<?php
/**
 * @var array<string, string> $errors
 * @var array<string, string> $previous
 * @var array{token: string, question: string} $challenge
 * @var string|null $notice
 */
$value = static fn (string $key): string => is_string($previous[$key] ?? null) ? (string) $previous[$key] : '';
$fieldError = static fn (string $key): ?string => $errors[$key] ?? null;
?>
<section class="panel">
    <p class="eyebrow">Referring clinicians</p>
    <h1>Request a referring account</h1>

    <p class="muted">
        An account lets you submit referrals and see what became of them. Accounts are
        created by the clinic, not by signing up: send this request and the team will
        review it.
    </p>

    <p class="muted">
        This form is for clinicians and practice staff only. It must not be used to send
        patient information — to refer a patient, use the referral routes on the
        <a href="/services">services pages</a>.
    </p>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success" role="status">
            <p><?= e($notice) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p><strong>Please check the following:</strong></p>
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/request-account" novalidate>
        <?= \App\Security\Csrf::field() ?>

        <div class="field">
            <label for="full_name">Full name <span aria-hidden="true">*</span></label>
            <input
                id="full_name"
                name="full_name"
                type="text"
                value="<?= e($value('full_name')) ?>"
                maxlength="120"
                autocomplete="name"
                required
                <?php if ($fieldError('full_name') !== null): ?>aria-invalid="true" aria-describedby="error-full_name"<?php endif; ?>
            >
            <?php if ($fieldError('full_name') !== null): ?>
                <p class="field-error" id="error-full_name"><?= e($fieldError('full_name')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="email">Work email address <span aria-hidden="true">*</span></label>
            <input
                id="email"
                name="email"
                type="email"
                value="<?= e($value('email')) ?>"
                maxlength="190"
                autocomplete="email"
                required
                <?php if ($fieldError('email') !== null): ?>aria-invalid="true" aria-describedby="error-email"<?php endif; ?>
            >
            <p class="muted">This is the address the clinic will contact you at.</p>
            <?php if ($fieldError('email') !== null): ?>
                <p class="field-error" id="error-email"><?= e($fieldError('email')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="practice_name">Practice or institution <span aria-hidden="true">*</span></label>
            <input
                id="practice_name"
                name="practice_name"
                type="text"
                value="<?= e($value('practice_name')) ?>"
                maxlength="160"
                autocomplete="organization"
                required
                <?php if ($fieldError('practice_name') !== null): ?>aria-invalid="true" aria-describedby="error-practice_name"<?php endif; ?>
            >
            <?php if ($fieldError('practice_name') !== null): ?>
                <p class="field-error" id="error-practice_name"><?= e($fieldError('practice_name')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="practice_city">City <span aria-hidden="true">*</span></label>
            <input
                id="practice_city"
                name="practice_city"
                type="text"
                value="<?= e($value('practice_city')) ?>"
                maxlength="120"
                autocomplete="address-level2"
                required
                <?php if ($fieldError('practice_city') !== null): ?>aria-invalid="true" aria-describedby="error-practice_city"<?php endif; ?>
            >
            <?php if ($fieldError('practice_city') !== null): ?>
                <p class="field-error" id="error-practice_city"><?= e($fieldError('practice_city')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="registration_no">Professional registration number</label>
            <input
                id="registration_no"
                name="registration_no"
                type="text"
                value="<?= e($value('registration_no')) ?>"
                maxlength="60"
                <?php if ($fieldError('registration_no') !== null): ?>aria-invalid="true" aria-describedby="error-registration_no"<?php endif; ?>
            >
            <p class="muted">
                Optional. If you do not have it to hand, the clinic will ask for it during
                verification — it is needed before referrals can be accepted.
            </p>
            <?php if ($fieldError('registration_no') !== null): ?>
                <p class="field-error" id="error-registration_no"><?= e($fieldError('registration_no')) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="note">Anything the clinic should know</label>
            <textarea
                id="note"
                name="note"
                rows="4"
                maxlength="1000"
                <?php if ($fieldError('note') !== null): ?>aria-invalid="true" aria-describedby="error-note"<?php endif; ?>
            ><?= e($value('note')) ?></textarea>
            <p class="muted">Do not include patient details here.</p>
            <?php if ($fieldError('note') !== null): ?>
                <p class="field-error" id="error-note"><?= e($fieldError('note')) ?></p>
            <?php endif; ?>
        </div>

        <?php /* A field no person sees and no autofill should complete. */ ?>
        <div class="honeypot" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div class="field">
            <label for="check_answer"><?= e($challenge['question']) ?> <span aria-hidden="true">*</span></label>
            <input
                id="check_answer"
                name="check_answer"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                required
                <?php if ($fieldError('check_answer') !== null): ?>aria-invalid="true" aria-describedby="error-check_answer"<?php endif; ?>
            >
            <p class="muted">This keeps automated submissions out of the queue.</p>
            <?php if ($fieldError('check_answer') !== null): ?>
                <p class="field-error" id="error-check_answer"><?= e($fieldError('check_answer')) ?></p>
            <?php endif; ?>
        </div>

        <input type="hidden" name="check_token" value="<?= e($challenge['token']) ?>">

        <div class="actions">
            <button class="button" type="submit">Send request</button>
        </div>
    </form>
</section>
