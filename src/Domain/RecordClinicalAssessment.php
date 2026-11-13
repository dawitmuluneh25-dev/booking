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

        if ($errors === [] && !$this->hasAnyContent($data)) {
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
            recordedByUserId: $actor->userId,
            recordedAtUtc: $recordedAtUtc,
            supersedesId: $current?->id,
        );

        return $this->transaction->run(fn (): ClinicalAssessment => $this->assessments->record($assessment));
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
