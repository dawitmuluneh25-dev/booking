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
$errorId = static fn (string $field): string => $field . '-error';
$describedBy = static fn (string $field): string => $error($field) === null
    ? ''
    : ' aria-describedby="' . e($errorId($field)) . '" aria-invalid="true"';
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
                <input id="patient_name"<?= $describedBy('patient_name') ?> name="patient_name" type="text" required
                       value="<?= $value('patient_name') ?>">
                <?php if ($error('patient_name') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_name')) ?>"><?= e((string) $error('patient_name')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_phone">Patient phone</label>
                <input id="patient_phone"<?= $describedBy('patient_phone') ?> name="patient_phone" type="tel" required
                       value="<?= $value('patient_phone') ?>">
                <?php if ($error('patient_phone') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_phone')) ?>"><?= e((string) $error('patient_phone')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_dob">Date of birth</label>
                <input id="patient_dob"<?= $describedBy('patient_dob') ?> name="patient_dob" type="date" value="<?= $value('patient_dob') ?>">
                <?php if ($error('patient_dob') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_dob')) ?>"><?= e((string) $error('patient_dob')) ?></p>
                <?php endif; ?>
            </div>
            <?php /*
             * §6.3 offers the age as an alternative to a date of birth, and asks for
             * the unit and the source with it. The three inputs are deliberately
             * adjacent: a number in one box and its unit two fields away is how
             * "3" becomes three years old.
             */ ?>
            <div class="field">
                <label for="patient_age_value">Age, if the date of birth is not known</label>
                <input id="patient_age_value"<?= $describedBy('patient_age_value') ?> name="patient_age_value"
                       type="number" min="0" inputmode="numeric" value="<?= $value('patient_age_value') ?>">
                <?php if ($error('patient_age_value') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_age_value')) ?>"><?= e((string) $error('patient_age_value')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_age_unit">Age unit</label>
                <select id="patient_age_unit"<?= $describedBy('patient_age_unit') ?> name="patient_age_unit">
                    <option value="">Not stated</option>
                    <?php foreach (\App\Domain\AgeUnit::cases() as $unit): ?>
                        <option value="<?= e($unit->value) ?>"<?= $value('patient_age_unit') === $unit->value ? ' selected' : '' ?>><?= e(ucfirst($unit->value)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($error('patient_age_unit') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_age_unit')) ?>"><?= e((string) $error('patient_age_unit')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_age_source">Who supplied the age</label>
                <select id="patient_age_source"<?= $describedBy('patient_age_source') ?> name="patient_age_source">
                    <option value="">Not stated</option>
                    <?php foreach (\App\Domain\AgeSource::cases() as $source): ?>
                        <option value="<?= e($source->value) ?>"<?= $value('patient_age_source') === $source->value ? ' selected' : '' ?>><?= e(ucfirst($source->label())) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($error('patient_age_source') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_age_source')) ?>"><?= e((string) $error('patient_age_source')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_identifier">Hospital or clinic number</label>
                <input id="patient_identifier"<?= $describedBy('patient_identifier') ?> name="patient_identifier"
                       type="text" value="<?= $value('patient_identifier') ?>">
                <?php if ($error('patient_identifier') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_identifier')) ?>"><?= e((string) $error('patient_identifier')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_identifier_type">What kind of number</label>
                <select id="patient_identifier_type"<?= $describedBy('patient_identifier_type') ?> name="patient_identifier_type">
                    <option value="">Not stated</option>
                    <?php foreach (\App\Domain\PatientIdentifierType::cases() as $type): ?>
                        <option value="<?= e($type->value) ?>"<?= $value('patient_identifier_type') === $type->value ? ' selected' : '' ?>><?= e($type->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($error('patient_identifier_type') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_identifier_type')) ?>"><?= e((string) $error('patient_identifier_type')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_identifier_facility">Issued by</label>
                <input id="patient_identifier_facility"<?= $describedBy('patient_identifier_facility') ?>
                       name="patient_identifier_facility" type="text" value="<?= $value('patient_identifier_facility') ?>">
                <?php if ($error('patient_identifier_facility') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_identifier_facility')) ?>"><?= e((string) $error('patient_identifier_facility')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_email">Email, if you use one</label>
                <input id="patient_email"<?= $describedBy('patient_email') ?> name="patient_email" type="email"
                       value="<?= $value('patient_email') ?>">
                <?php if ($error('patient_email') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_email')) ?>"><?= e((string) $error('patient_email')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="preferred_language">Preferred language</label>
                <input id="preferred_language"<?= $describedBy('preferred_language') ?> name="preferred_language"
                       type="text" value="<?= $value('preferred_language') ?>">
                <?php if ($error('preferred_language') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('preferred_language')) ?>"><?= e((string) $error('preferred_language')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="section-title">B. Guardian, where the patient is a minor</h2>
        <div class="grid">
            <div class="field">
                <label for="guardian_name">Guardian name</label>
                <input id="guardian_name"<?= $describedBy('guardian_name') ?> name="guardian_name" type="text" value="<?= $value('guardian_name') ?>">
            </div>
            <div class="field">
                <label for="guardian_phone">Guardian phone</label>
                <input id="guardian_phone"<?= $describedBy('guardian_phone') ?> name="guardian_phone" type="tel" value="<?= $value('guardian_phone') ?>">
                <?php if ($error('guardian_phone') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('guardian_phone')) ?>"><?= e((string) $error('guardian_phone')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="guardian_relationship">Relationship</label>
                <input id="guardian_relationship"<?= $describedBy('guardian_relationship') ?> name="guardian_relationship" type="text"
                       value="<?= $value('guardian_relationship') ?>">
            </div>
        </div>

        <h2 class="section-title">C. Requested procedure</h2>
        <div class="grid">
            <div class="field">
                <label for="procedure_code">Procedure</label>
                <select id="procedure_code"<?= $describedBy('procedure_code') ?> name="procedure_code" required>
                    <option value="">Choose a procedure</option>
                    <?php foreach ($procedures as $procedure): ?>
                        <option value="<?= e($procedure->code) ?>"
                            <?= ($values['procedure_code'] ?? '') === $procedure->code ? 'selected' : '' ?>>
                            <?= e($procedure->label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($error('procedure_code') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('procedure_code')) ?>"><?= e((string) $error('procedure_code')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="requested_urgency">Requested urgency</label>
                <select id="requested_urgency"<?= $describedBy('requested_urgency') ?> name="requested_urgency" required>
                    <?php foreach ($urgencies as $urgencyValue => $urgencyLabel): ?>
                        <option value="<?= e($urgencyValue) ?>"
                            <?= ($values['requested_urgency'] ?? '') === $urgencyValue ? 'selected' : '' ?>>
                            <?= e($urgencyLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="muted">Priority is a request, not a decision. Urgent cases are not booked here.</p>
                <?php if ($error('requested_urgency') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('requested_urgency')) ?>"><?= e((string) $error('requested_urgency')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="target_site">Target site</label>
                <input id="target_site"<?= $describedBy('target_site') ?> name="target_site" type="text" value="<?= $value('target_site') ?>">
            </div>
        </div>

        <div class="field">
            <label for="clinical_summary">Clinical summary</label>
            <textarea id="clinical_summary"<?= $describedBy('clinical_summary') ?> name="clinical_summary" rows="5" required><?= $value('clinical_summary') ?></textarea>
            <p class="muted">Indication, relevant history and imaging to date.</p>
            <?php if ($error('clinical_summary') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('clinical_summary')) ?>"><?= e((string) $error('clinical_summary')) ?></p>
            <?php endif; ?>
        </div>
        <?php /*
         * REF-009 asks a different question from the summary above: the summary says
         * why the request was made, this says what the procedure is meant to answer.
         * Required of a referring clinician, optional when a patient is the source,
         * where it is settled during clinical review instead.
         */ ?>
        <div class="field">
            <label for="clinical_question">Clinical question</label>
            <textarea id="clinical_question"<?= $describedBy('clinical_question') ?> name="clinical_question" rows="3"><?= $value('clinical_question') ?></textarea>
            <p class="muted">What the procedure should answer, for example whether a lesion is malignant.</p>
            <?php if ($error('clinical_question') !== null): ?>
                <p class="field-error" id="<?= e($errorId('clinical_question')) ?>"><?= e((string) $error('clinical_question')) ?></p>
            <?php endif; ?>
        </div>

        <h2 class="section-title">D. Referring clinician</h2>
        <div class="grid">
            <div class="field">
                <label for="referring_physician_name">Referring physician</label>
                <input id="referring_physician_name"<?= $describedBy('referring_physician_name') ?> name="referring_physician_name" type="text"
                       value="<?= $value('referring_physician_name') ?>">
            </div>
            <div class="field">
                <label for="referring_facility">Facility</label>
                <input id="referring_facility"<?= $describedBy('referring_facility') ?> name="referring_facility" type="text"
                       value="<?= $value('referring_facility') ?>">
            </div>
            <div class="field">
                <label for="referring_physician_credentials">Credentials</label>
                <input id="referring_physician_credentials"<?= $describedBy('referring_physician_credentials') ?>
                       name="referring_physician_credentials" type="text"
                       value="<?= $value('referring_physician_credentials') ?>">
                <p class="muted">As stated here. Privileges are verified separately on the clinician's profile.</p>
                <?php if ($error('referring_physician_credentials') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('referring_physician_credentials')) ?>"><?= e((string) $error('referring_physician_credentials')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="referring_department">Department</label>
                <input id="referring_department"<?= $describedBy('referring_department') ?> name="referring_department" type="text"
                       value="<?= $value('referring_department') ?>">
                <?php if ($error('referring_department') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('referring_department')) ?>"><?= e((string) $error('referring_department')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="referring_physician_phone">Contact phone</label>
                <input id="referring_physician_phone"<?= $describedBy('referring_physician_phone') ?> name="referring_physician_phone" type="tel"
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
            <button class="button button-secondary" type="submit" formaction="/referrals/draft" formnovalidate>
                Save as draft
            </button>
            <a class="link" href="/referrals">Cancel</a>
        </div>
    </form>
</section>
