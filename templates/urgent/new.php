<?php
/**
 * The urgent intake form.
 *
 * Every field is one the coordination command requires or that §10.2 makes
 * mandatory for coordinating a child. Nothing here asks a clinical question the
 * software would have to answer — no eligibility, no stability, no diagnosis.
 *
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var \App\Domain\User     $currentUser
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
        <h1>Register an urgent case</h1>
        <a class="button button-secondary button-small" href="/urgent-cases">Board</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <p>This case was not registered. Correct the marked fields.</p>
            <ul>
                <?php foreach ($errors as $field => $message): ?>
                    <li><a href="#<?= e((string) $field) ?>"><?= e($message) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="alert alert-warning" role="note">
        Record the case <strong>after</strong> making direct telephone contact with the treating
        hospital. Registering it does not contact anybody, and it is not an acknowledgement:
        continue direct contact until a named clinician confirms they have taken the case.
    </p>

    <p class="muted">
        Hydrostatic intussusception reduction requires urgent hospital-based assessment. This
        form does not book a procedure, does not assess the patient, and cannot be used to
        decide whether reduction is appropriate — the receiving clinical team does that.
    </p>

    <form method="post" action="/urgent-cases" class="stack">
        <?= \App\Security\Csrf::field() ?>

        <h2 class="section-title">A. Patient</h2>
        <div class="grid">
            <div class="field">
                <label for="patient_name">Patient name or temporary identifier</label>
                <input id="patient_name"<?= $describedBy('patient_name') ?> name="patient_name" type="text"
                       required minlength="2" maxlength="120" value="<?= $value('patient_name') ?>">
                <?php if ($error('patient_name') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_name')) ?>"><?= e((string) $error('patient_name')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_dob">Date of birth</label>
                <input id="patient_dob"<?= $describedBy('patient_dob') ?> name="patient_dob" type="date"
                       value="<?= $value('patient_dob') ?>">
                <?php if ($error('patient_dob') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_dob')) ?>"><?= e((string) $error('patient_dob')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="patient_age_months">Age in months</label>
                <input id="patient_age_months"<?= $describedBy('patient_age_months') ?> name="patient_age_months"
                       type="text" inputmode="numeric" maxlength="3" value="<?= $value('patient_age_months') ?>">
                <p class="muted">Record this when the date of birth is not known. One of the two is required.</p>
                <?php if ($error('patient_age_months') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('patient_age_months')) ?>"><?= e((string) $error('patient_age_months')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="section-title">B. Guardian</h2>
        <div class="grid">
            <div class="field">
                <label for="guardian_name">Guardian name</label>
                <input id="guardian_name"<?= $describedBy('guardian_name') ?> name="guardian_name" type="text"
                       maxlength="120" value="<?= $value('guardian_name') ?>">
                <?php if ($error('guardian_name') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('guardian_name')) ?>"><?= e((string) $error('guardian_name')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="guardian_phone">Guardian phone</label>
                <input id="guardian_phone"<?= $describedBy('guardian_phone') ?> name="guardian_phone" type="tel"
                       required value="<?= $value('guardian_phone') ?>">
                <?php if ($error('guardian_phone') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('guardian_phone')) ?>"><?= e((string) $error('guardian_phone')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="guardian_relationship">Relationship to the patient</label>
                <input id="guardian_relationship"<?= $describedBy('guardian_relationship') ?>
                       name="guardian_relationship" type="text" maxlength="40"
                       value="<?= $value('guardian_relationship') ?>">
                <?php if ($error('guardian_relationship') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('guardian_relationship')) ?>"><?= e((string) $error('guardian_relationship')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="section-title">C. Referring team</h2>
        <div class="grid">
            <div class="field">
                <label for="referring_physician_name">Referring clinician</label>
                <input id="referring_physician_name"<?= $describedBy('referring_physician_name') ?>
                       name="referring_physician_name" type="text" maxlength="120"
                       value="<?= $value('referring_physician_name') ?>">
                <?php if ($error('referring_physician_name') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('referring_physician_name')) ?>"><?= e((string) $error('referring_physician_name')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="referring_facility">Referring hospital</label>
                <input id="referring_facility"<?= $describedBy('referring_facility') ?> name="referring_facility"
                       type="text" maxlength="160" value="<?= $value('referring_facility') ?>">
                <?php if ($error('referring_facility') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('referring_facility')) ?>"><?= e((string) $error('referring_facility')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="referring_physician_phone">Direct contact number</label>
                <input id="referring_physician_phone"<?= $describedBy('referring_physician_phone') ?>
                       name="referring_physician_phone" type="tel" maxlength="32"
                       value="<?= $value('referring_physician_phone') ?>">
                <p class="muted">The route somebody can actually answer, not a general inbox.</p>
                <?php if ($error('referring_physician_phone') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('referring_physician_phone')) ?>"><?= e((string) $error('referring_physician_phone')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="receiving_hospital_name">Receiving hospital</label>
                <input id="receiving_hospital_name"<?= $describedBy('receiving_hospital_name') ?>
                       name="receiving_hospital_name" type="text" maxlength="160"
                       value="<?= $value('receiving_hospital_name') ?>">
                <?php if ($error('receiving_hospital_name') !== null): ?>
                    <p class="field-error" id="<?= e($errorId('receiving_hospital_name')) ?>"><?= e((string) $error('receiving_hospital_name')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="section-title">D. Clinical context</h2>
        <div class="field">
            <label for="clinical_summary">What the referring team has reported</label>
            <textarea id="clinical_summary"<?= $describedBy('clinical_summary') ?> name="clinical_summary"
                      rows="5" required minlength="10" maxlength="4000"><?= $value('clinical_summary') ?></textarea>
            <p class="muted">
                Record the source and the time. Do not enter an assessment the software would then
                appear to have made — no diagnosis, no stability judgement.
            </p>
            <?php if ($error('clinical_summary') !== null): ?>
                <p class="field-error" id="<?= e($errorId('clinical_summary')) ?>"><?= e((string) $error('clinical_summary')) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="imaging_summary">Imaging available</label>
            <textarea id="imaging_summary"<?= $describedBy('imaging_summary') ?> name="imaging_summary"
                      rows="3" maxlength="4000"><?= $value('imaging_summary') ?></textarea>
            <p class="muted">Describe the study and its findings as reported. If none is available, say so.</p>
            <?php if ($error('imaging_summary') !== null): ?>
                <p class="field-error" id="<?= e($errorId('imaging_summary')) ?>"><?= e((string) $error('imaging_summary')) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button class="button" type="submit">Register case</button>
        </div>
    </form>
</section>
