<?php
/**
 * The guided intake form from UI/UX REF-02. Sections follow the specification:
 * patient, referrer, requested procedure, supporting information, confirmation.
 *
 * No clinical eligibility rule is asked here. Procedure-specific fields are
 * rendered only when the catalogue has an approved field set for that procedure,
 * which is why the section renders nothing by default.
 *
 * @var array<string, string>        $values
 * @var array<string, string>        $errors
 * @var list<\App\Domain\Procedure>  $procedures
 * @var list<string>                 $urgencies
 * @var string                       $idempotencyKey
 */
$value = static fn (string $field): string => e($values[$field] ?? '');
$error = static fn (string $field): ?string => $errors[$field] ?? null;
?>
<section class="panel">
    <div class="panel-head">
        <h1>New referral</h1>
        <a class="button button-secondary button-small" href="/referrals">Back</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>This referral was not submitted. Correct the marked fields.</p>
            <ul>
                <?php foreach ($errors as $field => $message): ?>
                    <li><a href="#<?= e((string) $field) ?>"><?= e($message) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="muted">
        A referral is a request for clinical review. It is not an appointment, it does not
        reserve a time, and it is not accepted until the clinical team has reviewed it.
    </p>

    <form method="post" action="/referrals" class="stack">
        <?= \App\Security\Csrf::field() ?>
        <input type="hidden" name="idempotency_key" value="<?= e($idempotencyKey) ?>">

        <h2 class="section-title">A. Patient</h2>
        <div class="grid">
            <div class="field">
                <label for="patient_name">Patient name</label>
                <input id="patient_name" name="patient_name" type="text" required
                       value="<?= $value('patient_name') ?>">
                <?php if ($error('patient_name') !== null): ?>
                    <p class="field-error"><?= e((string) $error('patient_name')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_phone">Patient phone</label>
                <input id="patient_phone" name="patient_phone" type="tel" required
                       value="<?= $value('patient_phone') ?>">
                <?php if ($error('patient_phone') !== null): ?>
                    <p class="field-error"><?= e((string) $error('patient_phone')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_dob">Date of birth</label>
                <input id="patient_dob" name="patient_dob" type="date" value="<?= $value('patient_dob') ?>">
                <?php if ($error('patient_dob') !== null): ?>
                    <p class="field-error"><?= e((string) $error('patient_dob')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="section-title">B. Guardian, where the patient is a minor</h2>
        <div class="grid">
            <div class="field">
                <label for="guardian_name">Guardian name</label>
                <input id="guardian_name" name="guardian_name" type="text" value="<?= $value('guardian_name') ?>">
            </div>
            <div class="field">
                <label for="guardian_phone">Guardian phone</label>
                <input id="guardian_phone" name="guardian_phone" type="tel" value="<?= $value('guardian_phone') ?>">
                <?php if ($error('guardian_phone') !== null): ?>
                    <p class="field-error"><?= e((string) $error('guardian_phone')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="guardian_relationship">Relationship</label>
                <input id="guardian_relationship" name="guardian_relationship" type="text"
                       value="<?= $value('guardian_relationship') ?>">
            </div>
        </div>

        <h2 class="section-title">C. Requested procedure</h2>
        <div class="grid">
            <div class="field">
                <label for="procedure_code">Procedure</label>
                <select id="procedure_code" name="procedure_code" required>
                    <option value="">Choose a procedure</option>
                    <?php foreach ($procedures as $procedure): ?>
                        <option value="<?= e($procedure->code) ?>"
                            <?= ($values['procedure_code'] ?? '') === $procedure->code ? 'selected' : '' ?>>
                            <?= e($procedure->label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($error('procedure_code') !== null): ?>
                    <p class="field-error"><?= e((string) $error('procedure_code')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="requested_urgency">Requested urgency</label>
                <select id="requested_urgency" name="requested_urgency" required>
                    <?php foreach ($urgencies as $urgency): ?>
                        <option value="<?= e($urgency) ?>"
                            <?= ($values['requested_urgency'] ?? '') === $urgency ? 'selected' : '' ?>>
                            <?= e(ucfirst($urgency)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="muted">Priority is a request, not a decision. Urgent cases are not booked here.</p>
                <?php if ($error('requested_urgency') !== null): ?>
                    <p class="field-error"><?= e((string) $error('requested_urgency')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="target_site">Target site</label>
                <input id="target_site" name="target_site" type="text" value="<?= $value('target_site') ?>">
            </div>
        </div>

        <div class="field">
            <label for="clinical_summary">Clinical summary</label>
            <textarea id="clinical_summary" name="clinical_summary" rows="5" required><?= $value('clinical_summary') ?></textarea>
            <p class="muted">Indication, relevant history and imaging to date.</p>
            <?php if ($error('clinical_summary') !== null): ?>
                <p class="field-error"><?= e((string) $error('clinical_summary')) ?></p>
            <?php endif; ?>
        </div>

        <h2 class="section-title">D. Referring clinician</h2>
        <div class="grid">
            <div class="field">
                <label for="referring_physician_name">Referring physician</label>
                <input id="referring_physician_name" name="referring_physician_name" type="text"
                       value="<?= $value('referring_physician_name') ?>">
            </div>
            <div class="field">
                <label for="referring_facility">Facility</label>
                <input id="referring_facility" name="referring_facility" type="text"
                       value="<?= $value('referring_facility') ?>">
            </div>
            <div class="field">
                <label for="referring_physician_phone">Contact phone</label>
                <input id="referring_physician_phone" name="referring_physician_phone" type="tel"
                       value="<?= $value('referring_physician_phone') ?>">
            </div>
        </div>

        <h2 class="section-title">E. Supporting information</h2>
        <p class="muted">
            Imaging reports and letters are attached to the referral after it is submitted,
            so the reference exists before any file is uploaded.
        </p>

        <h2 class="section-title">F. Confirmation</h2>
        <div class="field">
            <label>
                <input type="checkbox" required>
                I confirm the details are accurate and that this submission is not an appointment.
            </label>
        </div>

        <div class="form-actions">
            <button class="button" type="submit">Submit referral</button>
            <a class="link" href="/referrals">Cancel</a>
        </div>
    </form>
</section>
