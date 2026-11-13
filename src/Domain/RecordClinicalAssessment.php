<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record the pre-procedure assessment (§6.5, §6.6).
 *
 * The command exists because the alternative is a form that defaults blanks to
 * "no". Every clinical field is optional *at the level of the request*, and each one
 * that is supplied must use its approved vocabulary — `unknown` and
 * `not_yet_assessed` are values a clinician chooses, not values the software infers
 * from an empty box.
 *
 * Three refusals carry the meaning:
 *
 *   1. Only a holder of `clinical.review` may record one. An assessment is a
 *      clinician's judgement; reception can see that it exists.
 *   2. A status that asserts a finding needs the finding. "Known allergy" with no
 *      reaction recorded is a sentence a reader cannot act on, and §6.5 asks for the
 *      reaction where known. The schema enforces the same rule.
 *   3. A record with nothing in it is refused rather than stored. An empty assessment
 *      would read as "assessed and unremarkable", which is the exact confusion §6.2
 *      names.
 *
 * Nothing is required to be present. Requiring an allergy answer here would push a
 * clinician to type one to get past the form, which is worse than an honest blank.
 */
final class RecordClinicalAssessment
{
    /**
     * States in which a pre-procedure assessment is meaningful: after intake and
     * before the referral is finished. Draft is not in the system yet; the three
     * terminal states have nothing left to assess.
     *
     * @var list<ReferralStatus>
     */
    private const REFUSED = [
        ReferralStatus::Draft,
        ReferralStatus::Declined,
        ReferralStatus::Cancelled,
        ReferralStatus::Closed,
    ];

    public function __construct(
        private readonly ClinicalAssessmentRepository $assessments,
        private readonly ReferralRepository $referrals,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException    the actor is not a clinician
     * @throws ReferralNotFoundException the referral does not exist
     * @throws ValidationException       a supplied value is not usable
     * @throws AssessmentNotPermittedException the referral is in a state that takes no assessment
     */
    public function execute(Actor $actor, int $referralId, array $input, ?string $nowUtc = null): ClinicalAssessment
    {
        if (!$actor->can(Permission::ClinicalReview)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalReview);
        }

        $referral = $this->referrals->findById($referralId);

        if ($referral === null) {
            throw new ReferralNotFoundException('#' . $referralId);
        }

        if (in_array($referral->status, self::REFUSED, true)) {
            throw new AssessmentNotPermittedException(
                $referral->status,
                'A pre-procedure assessment is recorded after intake and before the referral is finished.',
            );
        }

        $data = [
            'anticoagulant_status' => $this->text($input, 'anticoagulant_status'),
            'anticoagulant_details' => $this->text($input, 'anticoagulant_details'),
            'allergy_status' => $this->text($input, 'allergy_status'),
            'allergy_details' => $this->text($input, 'allergy_details'),
            'pregnancy_status' => $this->text($input, 'pregnancy_status'),
            'pregnancy_details' => $this->text($input, 'pregnancy_details'),
            'relevant_history' => $this->text($input, 'relevant_history'),
            'prior_procedure' => $this->text($input, 'prior_procedure'),
            'examination_context' => $this->text($input, 'examination_context'),
            'imaging_modality' => $this->text($input, 'imaging_modality'),
            'imaging_date' => $this->text($input, 'imaging_date'),
            'imaging_reference' => $this->text($input, 'imaging_reference'),
            'medication_review_status' => $this->text($input, 'medication_review_status'),
        ];

        $errors = $this->validator->validate($data, [
            'anticoagulant_status' => 'in:' . $this->allowed(AnticoagulantStatus::cases()),
            'allergy_status' => 'in:' . $this->allowed(AllergyStatus::cases()),
            'pregnancy_status' => 'in:' . $this->allowed(PregnancyStatus::cases()),
            'imaging_modality' => 'in:' . $this->allowed(ImagingModality::cases()),
            'anticoagulant_details' => 'max:500',
            'allergy_details' => 'max:500',
            'pregnancy_details' => 'max:500',
            'relevant_history' => 'max:8000',
            'prior_procedure' => 'max:1000',
            'examination_context' => 'max:8000',
            'imaging_reference' => 'max:200',
            'medication_review_status' => 'in:' . $this->allowed(MedicationReviewStatus::cases()),
        ]);

        // An empty string is not a value for a controlled column: it has to be
        // either a vocabulary term or an explicit absence, and the column models the
        // absence as NULL.
        $anticoagulant = AnticoagulantStatus::tryFrom($data['anticoagulant_status']);
        $allergy = AllergyStatus::tryFrom($data['allergy_status']);
        $pregnancy = PregnancyStatus::tryFrom($data['pregnancy_status']);
        $imaging = ImagingModality::tryFrom($data['imaging_modality']);

        if ($anticoagulant?->assertsUse() === true && $data['anticoagulant_details'] === '') {
            $errors['anticoagulant_details'] = 'Name the drug (and the last dose if known) — '
                . '“reports taking one” with nothing behind it cannot be acted on.';
        }

        if ($allergy?->assertsAllergy() === true && $data['allergy_details'] === '') {
            $errors['allergy_details'] = 'Record the allergy and the reaction where known.';
        }

        if ($data['imaging_date'] !== '' && strtotime($data['imaging_date']) === false) {
            $errors['imaging_date'] = 'Enter a calendar date for the imaging.';
        } elseif ($data['imaging_date'] !== '' && strtotime($data['imaging_date']) > time()) {
            $errors['imaging_date'] = 'An imaging date in the future cannot be a study already reviewed.';
        }

        $medicationReview = MedicationReviewStatus::tryFrom($data['medication_review_status']);
        $medications = $this->medications($input, $errors);

        if ($medicationReview?->requiresList() === true && $medications === []) {
            $errors['medications'] = 'This status says a list exists, but none was entered. '
                . 'Use “Unknown” if nobody has asked, or “None” if asking produced nothing.';
        }

        if (
            $medicationReview !== null
            && !$medicationReview->requiresList()
            && $medications !== []
        ) {
            $errors['medications'] = 'A list was entered with a status that says there is no list. '
                . 'Choose “Reported, not yet reconciled” or “List recorded and reviewed”.';
        }

        if ($medicationReview === null && $medications !== []) {
            $errors['medications'] = 'Say how far the medication review got — a list with no status '
                . 'cannot be told apart from a list nobody has checked.';
        }
        if ($errors === [] && !$this->hasAnyContent($data) && $medications === []) {
            $errors['assessment'] = 'This record would be empty. Record at least one answer, or '
                . 'leave the assessment unrecorded — an empty assessment reads as “assessed and '
                . 'unremarkable”, which nobody said.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $recordedAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');
        $current = $this->assessments->current($referral->id);

        $assessment = new ClinicalAssessment(
            id: 0,
            referralId: $referral->id,
            anticoagulant: $anticoagulant,
            anticoagulantDetails: $this->nullable($data['anticoagulant_details']),
            allergy: $allergy,
            allergyDetails: $this->nullable($data['allergy_details']),
            pregnancy: $pregnancy,
            pregnancyDetails: $this->nullable($data['pregnancy_details']),
            relevantHistory: $this->nullable($data['relevant_history']),
            priorProcedure: $this->nullable($data['prior_procedure']),
            examinationContext: $this->nullable($data['examination_context']),
            imagingModality: $imaging,
            imagingDate: $this->nullable($data['imaging_date']),
            imagingReference: $this->nullable($data['imaging_reference']),
            medicationReview: $medicationReview,
            medications: $medications,
            recordedByUserId: $actor->userId,
            recordedAtUtc: $recordedAtUtc,
            supersedesId: $current?->id,
        );

        return $this->transaction->run(fn (): ClinicalAssessment => $this->assessments->record($assessment));
    }

    /**
     * The medication rows, from three parallel arrays.
     *
     * A row with nothing in it is skipped rather than refused, because the form
     * always renders a few empty rows. A row with a dose but no name is refused:
     * "5 mg" alone is not a medication, and storing it would let a list look
     * populated while saying nothing.
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     *
     * @return list<Medication>
     */
    private function medications(array $input, array &$errors): array
    {
        $names = $this->listOf($input, 'medication_name');
        $doses = $this->listOf($input, 'medication_dose');
        $frequencies = $this->listOf($input, 'medication_frequency');

        if (count($names) > 50) {
            $errors['medications'] = 'At most 50 medications can be recorded in one assessment.';

            return [];
        }

        $medications = [];

        foreach ($names as $index => $name) {
            $dose = $doses[$index] ?? '';
            $frequency = $frequencies[$index] ?? '';

            if ($name === '' && $dose === '' && $frequency === '') {
                continue;
            }

            if ($name === '') {
                $errors['medications'] = 'Every medication needs a name; dose and frequency are optional.';

                continue;
            }

            if (mb_strlen($name) > 200 || mb_strlen($dose) > 100 || mb_strlen($frequency) > 100) {
                $errors['medications'] = 'A medication name, dose or frequency is too long.';

                continue;
            }

            $medications[] = new Medication(
                name: $name,
                dose: $dose === '' ? null : $dose,
                frequency: $frequency === '' ? null : $frequency,
            );
        }

        return $medications;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    private function listOf(array $input, string $key): array
    {
        $value = $input[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        $values = [];

        foreach ($value as $item) {
            $values[] = is_string($item) ? trim($item) : '';
        }

        return $values;
    }
    /** @param array<string, string> $data */
    private function hasAnyContent(array $data): bool
    {
        foreach ($data as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * @param list<\BackedEnum> $cases
     */
    private function allowed(array $cases): string
    {
        return implode(',', array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $cases,
        ));
    }
}
