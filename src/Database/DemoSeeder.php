<?php

declare(strict_types=1);

namespace App\Database;

use App\Bootstrap\Application;
use App\Domain\Actor;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Security\PasswordHasher;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Builds a demonstrable dataset from nothing.
 *
 * The point of this class is that a clean checkout becomes a working demonstration
 * in one command — five roles that can actually sign in, two rooms open during the
 * week, and referrals parked at every stage a person will want to look at, so the
 * triage queue, the radiologist's list, the scheduling desk and the urgent board all
 * have something in them.
 *
 * Three properties are deliberate, and none of them are conveniences:
 *
 * 1. It refuses production by name and cannot be talked out of it. `--force` can
 *    confirm the seed; it cannot overrule the environment. The two guards are
 *    separate because they fail separately: an operator can be certain and still be
 *    on the wrong database.
 * 2. It refuses a database that already holds records it did not create. Demo
 *    patients beside real ones is the one outcome nobody can undo by looking, and a
 *    demo administrator with a printed password on a live system is not a dataset,
 *    it is a back door.
 * 3. Every value is a literal in this file. There is no import path, no file
 *    argument and no environment variable that can supply a patient, so there is no
 *    route by which real data reaches a demo record. Contact details use a reserved
 *    domain that cannot receive mail and a subscriber prefix that is not assigned,
 *    so a mistyped number cannot reach a person.
 *
 * It runs as one unit of work: a failure halfway leaves nothing behind, which is
 * also what makes re-running it safe — "already seeded" is a complete dataset, never
 * a partial one.
 */
final class DemoSeeder
{
    /** RFC 2606 reserves `.invalid`: mail to it fails at the first hop, by design. */
    public const EMAIL_DOMAIN = 'demo.invalid';

    /** 000 is not an assigned subscriber prefix, so these numbers reach nobody. */
    private const PATIENT_PHONE_PREFIX = '+251 000 000 ';

    /** The active elective catalogue created by migrations 0019 and 0020. */
    private const PROCEDURES = [
        'image_guided_core_biopsy',
        'image_guided_fnac',
        'image_guided_abscess_drainage',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly DemoSeedGuard $guard,
    ) {
    }

    /** @throws DemoSeedRefused the environment may not hold a demo dataset */
    public function requirePermittedEnvironment(): void
    {
        $this->guard->requirePermittedEnvironment();
    }

    /** @throws DemoSeedRefused no confirmation was given */
    public function requireConfirmation(bool $confirmed): void
    {
        $this->guard->requireConfirmation($confirmed);
    }

    /**
     * @throws DemoSeedRefused the database already holds records
     */
    public function seed(string $password): DemoSeedReport
    {
        $hasher = new PasswordHasher();
        $errors = $hasher->policyErrors($password);

        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $hash = $hasher->hash($password);

        return $this->app->transaction()->run(function () use ($hash): DemoSeedReport {
            if ($this->alreadySeeded()) {
                return DemoSeedReport::alreadySeeded();
            }

            $this->requireEmptyDatabase();
            $this->requireProcedureCatalogue();

            $people = $this->createPeople($hash);
            $this->credentialTheRadiologist($people['radiologist']);
            $rooms = $this->createResources();

            $referrals = $this->createReferrals($people);
            $appointments = $this->bookAppointments($people, $rooms, $referrals);
            $urgent = $this->registerUrgentCase($people);

            return new DemoSeedReport(
                state: DemoSeedReport::SEEDED,
                users: count($people),
                resources: count($rooms),
                referrals: count($referrals),
                appointments: $appointments,
                urgentCases: $urgent,
            );
        });
    }

    private function alreadySeeded(): bool
    {
        $statement = $this->app->pdo()->prepare('SELECT COUNT(*) FROM users WHERE email LIKE :pattern');
        $statement->execute(['pattern' => '%@' . self::EMAIL_DOMAIN]);

        // One fetch: a second read of the same cursor returns false, which would
        // silently report "already seeded" as false forever.
        $count = $statement->fetchColumn();

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * A demo dataset belongs on a database the demo built. Anything already here was
     * put here by somebody for a reason, and the seed is not entitled to write beside
     * it.
     */
    private function requireEmptyDatabase(): void
    {
        $referrals = $this->count('referrals');
        $appointments = $this->count('appointments');
        $users = $this->count('users');

        if ($referrals > 0 || $appointments > 0 || $users > 0) {
            throw DemoSeedRefused::databaseNotEmpty($referrals, $appointments);
        }
    }

    /**
     * The demonstrations refer to procedures the migrations create, not ones this
     * class invents. Checking first turns "migrations have not run" into a sentence
     * rather than a foreign-key violation three referrals in.
     */
    private function requireProcedureCatalogue(): void
    {
        $missing = [];

        foreach (self::PROCEDURES as $code) {
            $procedure = $this->app->procedures()->find($code);

            if ($procedure === null || !$procedure->active) {
                $missing[] = $code;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'The demo seed needs these active procedures, which the migrations create: '
                . implode(', ', $missing) . '. Run the migrations first.',
            );
        }
    }

    private function count(string $table): int
    {
        if (!in_array($table, ['users', 'referrals', 'appointments'], true)) {
            throw new RuntimeException('Unexpected table in a seed guard: ' . $table);
        }

        $statement = $this->app->pdo()->query('SELECT COUNT(*) FROM ' . $table);

        if ($statement === false) {
            throw new RuntimeException('Could not count ' . $table . ' rows.');
        }

        $value = $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array<string, Actor> */
    private function createPeople(string $hash): array
    {
        $users = $this->app->users();

        $accounts = [
            'admin' => ['Ada Admin', Role::Admin],
            'staff' => ['Sam Reception', Role::Staff],
            'radiologist' => ['Dr Ruth Radiologist', Role::Radiologist],
            'physician' => ['Dr Peter Referrer', Role::Physician],
            'coordinator' => ['Cara Coordinator', Role::Coordinator],
        ];

        $actors = [];

        foreach ($accounts as $key => [$name, $role]) {
            $id = $users->create($key . '@' . self::EMAIL_DOMAIN, $name, $role, $hash);
            $actors[$key] = new Actor($id, $role);
        }

        return $actors;
    }

    /**
     * The verification the demo's approvals rest on (AC-009, Decision 6).
     *
     * Without it every seeded approval would be refused, because the rule applies to
     * demo data exactly as it applies to a real one — which is the point of seeding
     * it through the same command rather than writing the rows directly. The
     * registration number is marked `DEMO` so it cannot be mistaken for a real one,
     * and the evidence reference says where it came from. It is recorded by the
     * radiologist themselves and the register marks that as self-recorded.
     */
    private function credentialTheRadiologist(Actor $radiologist): void
    {
        $credential = $this->app->recordPractitionerCredential()->execute(
            $radiologist,
            $radiologist->userId,
            [
                'credential_type' => 'Specialist registration (demo)',
                'registration_number' => 'DEMO-REG-1',
                'qualifications' => 'Demo dataset: no real qualification is represented.',
                'evidence_reference' => 'Demo dataset — recorded so the approval path has a '
                    . 'verification to check against.',
                'effective_from' => '2026-01-01',
            ],
        );

        foreach (self::PROCEDURES as $code) {
            $this->app->grantPractitionerPrivilege()->execute($radiologist, $credential->id, [
                'procedure_code' => $code,
                'basis' => 'own',
                'effective_from' => '2026-01-01',
            ]);
        }
    }

    /** @return array<string, int> resource ids keyed by a short name */
    private function createResources(): array
    {
        $resources = $this->app->resourceAdmin();

        $weekday = ['opens' => '08:00', 'closes' => '17:00'];

        $week = [1 => $weekday, 2 => $weekday, 3 => $weekday, 4 => $weekday, 5 => $weekday];

        $rooms = [
            'us' => ['demo-room-1', 'Ultrasound Room 1', 'room', 'ultrasound'],
            'ct' => ['demo-suite-1', 'CT Suite 1', 'suite', 'ct'],
        ];

        $ids = [];

        foreach ($rooms as $key => [$code, $name, $kind, $modality]) {
            $resource = $resources->create($code, $name, $kind, $modality, true);
            $resources->replaceDefaultWeek($resource->id, $week);
            $ids[$key] = $resource->id;
        }

        return $ids;
    }

    /**
     * One referral per state worth looking at.
     *
     * Ordered so the demonstration reads as a day at the desk rather than a fixture
     * dump: the newest arrivals first, then the ones waiting on somebody, then the
     * ones already dealt with.
     *
     * @param array<string, Actor> $people
     *
     * @return array<string, Referral>
     */
    private function createReferrals(array $people): array
    {
        $referrals = [];

        $referrals['submitted'] = $this->referral($people['staff'], 1, 'Amina Yusuf', [
            'procedure' => 'image_guided_core_biopsy',
            'urgency' => 'routine',
            'summary' => 'New left breast lump, 2 cm, assessed as suspicious on ultrasound. '
                . 'Requesting image-guided core biopsy for histology.',
            'site' => 'Left breast, upper outer quadrant',
        ]);

        $referrals['administrative'] = $this->referral($people['staff'], 2, 'Bereket Tesfaye', [
            'procedure' => 'image_guided_abscess_drainage',
            'urgency' => 'routine',
            'summary' => 'Recurrent right gluteal abscess, 4 cm, painful, failed two courses of '
                . 'oral antibiotics. Referred for image-guided drainage.',
            'site' => 'Right gluteal region',
        ]);

        $this->advance($people['staff'], $referrals['administrative'], [
            ReferralStatus::Received,
            ReferralStatus::AdministrativeReview,
        ]);

        $referrals['clinical'] = $this->referral($people['staff'], 3, 'Chaltu Bekele', [
            'procedure' => 'image_guided_fnac',
            'urgency' => 'routine',
            'summary' => 'Thyroid nodule 3.1 cm with microcalcification. Requesting fine needle '
                . 'aspiration and core biopsy under ultrasound guidance.',
            'site' => 'Left thyroid lobe',
        ]);

        $this->advance($people['staff'], $referrals['clinical'], [
            ReferralStatus::Received,
            ReferralStatus::AdministrativeReview,
            ReferralStatus::ClinicalReview,
        ]);

        $referrals['approved'] = $this->referral($people['staff'], 4, 'Dawit Mekonnen', [
            'procedure' => 'image_guided_abscess_drainage',
            'urgency' => 'routine',
            'summary' => 'Left psoas collection, 6 cm, in a patient with poorly controlled '
                . 'diabetes and two weeks of fever. Referred for catheter drainage.',
            'site' => 'Left psoas muscle',
        ]);

        $this->advance($people['staff'], $referrals['approved'], [
            ReferralStatus::Received,
            ReferralStatus::AdministrativeReview,
            ReferralStatus::ClinicalReview,
        ]);

        $this->approve($people['radiologist'], $referrals['approved']);
        $this->app->approveForScheduling()->execute($people['staff'], $referrals['approved']->id);

        $referrals['deferred'] = $this->referral($people['staff'], 5, 'Firaol Bekele', [
            'procedure' => 'image_guided_core_biopsy',
            'urgency' => 'routine',
            'summary' => 'Enlarging cervical lymph node, 2.4 cm, in a young adult. Requesting '
                . 'biopsy for suspected lymphoma.',
            'site' => 'Right cervical chain',
        ]);

        $this->advance($people['staff'], $referrals['deferred'], [
            ReferralStatus::Received,
            ReferralStatus::AdministrativeReview,
            ReferralStatus::ClinicalReview,
        ]);

        $this->app->recordClinicalDecision()->execute($people['radiologist'], $referrals['deferred']->id, [
            'decision_type' => 'deferred',
            'rationale' => 'Deferred for two weeks. The node has changed since the referring '
                . 'scan and a repeat ultrasound will show whether it is still enlarging.',
        ]);

        $referrals['information'] = $this->referral($people['staff'], 6, 'Genet Alemu', [
            'procedure' => 'image_guided_abscess_drainage',
            'urgency' => 'routine',
            'summary' => 'Right breast abscess, 3 cm, post-partum, not responding to antibiotics. '
                . 'Referred for drainage.',
            'site' => 'Right breast, lower inner quadrant',
        ]);

        $this->advance($people['staff'], $referrals['information'], [
            ReferralStatus::Received,
            ReferralStatus::AdministrativeReview,
            ReferralStatus::ClinicalReview,
        ]);

        $this->app->recordClinicalDecision()->execute($people['radiologist'], $referrals['information']->id, [
            'decision_type' => 'more_information',
            'rationale' => 'The referral does not say whether the patient is still breastfeeding, '
                . 'which changes how the collection is approached.',
            'information_requested' => 'Confirm current breastfeeding status and the date of the '
                . 'most recent ultrasound.',
        ]);

        $referrals['clinically_approved'] = $this->referral($people['staff'], 7, 'Hanna Tesfaye', [
            'procedure' => 'image_guided_core_biopsy',
            'urgency' => 'routine',
            'summary' => 'Suspicious 1.8 cm renal mass found incidentally on CT. Requesting '
                . 'percutaneous biopsy for tissue diagnosis.',
            'site' => 'Right kidney, lower pole',
        ]);

        $this->advance($people['staff'], $referrals['clinically_approved'], [
            ReferralStatus::Received,
            ReferralStatus::AdministrativeReview,
            ReferralStatus::ClinicalReview,
        ]);

        $this->approve($people['radiologist'], $referrals['clinically_approved']);

        // Booked referrals are created last so their appointments can be placed on
        // one coherent day without colliding with each other.
        $referrals['booked_am'] = $this->referral($people['staff'], 8, 'Eden Girma', [
            'procedure' => 'image_guided_core_biopsy',
            'urgency' => 'routine',
            'summary' => 'Persistent 2.2 cm liver lesion in a patient with a known primary '
                . 'malignancy. Requesting image-guided biopsy.',
            'site' => 'Liver, segment VI',
        ]);

        $referrals['booked_mid'] = $this->referral($people['staff'], 9, 'Kalkidan Wolde', [
            'procedure' => 'image_guided_abscess_drainage',
            'urgency' => 'routine',
            'summary' => 'Left thigh collection, 5 cm, three weeks after orthopaedic surgery, '
                . 'with ongoing wound discharge.',
            'site' => 'Left lateral thigh',
        ]);

        $referrals['booked_pm'] = $this->referral($people['staff'], 10, 'Meseret Haile', [
            'procedure' => 'image_guided_core_biopsy',
            'urgency' => 'routine',
            'summary' => 'Parotid mass, 2.6 cm, slowly enlarging over eight months. Requesting '
                . 'core biopsy to guide surgical planning.',
            'site' => 'Right parotid gland',
        ]);

        foreach (['booked_am', 'booked_mid', 'booked_pm'] as $key) {
            $this->advance($people['staff'], $referrals[$key], [
                ReferralStatus::Received,
                ReferralStatus::AdministrativeReview,
                ReferralStatus::ClinicalReview,
            ]);

            $this->approve($people['radiologist'], $referrals[$key]);
            $this->app->approveForScheduling()->execute($people['staff'], $referrals[$key]->id);
        }

        return $referrals;
    }

    /**
     * @param array<string, Actor>                                                        $people
     * @param array<string, int>                                                          $rooms
     * @param array<string, Referral>                                                     $referrals
     */
    private function bookAppointments(array $people, array $rooms, array $referrals): int
    {
        $day = $this->nextOpenDay();

        $plan = [
            'booked_am' => ['room' => 'us', 'time' => '09:00', 'minutes' => 30],
            'booked_mid' => ['room' => 'us', 'time' => '10:30', 'minutes' => 45],
            'booked_pm' => ['room' => 'ct', 'time' => '09:30', 'minutes' => 30],
        ];

        $booked = 0;

        foreach ($plan as $key => $appointment) {
            $this->app->bookAppointment()->execute($people['staff'], $referrals[$key]->id, [
                'resource_id' => $rooms[$appointment['room']],
                'starts_at' => $this->utc($day, $appointment['time']),
                'duration_minutes' => $appointment['minutes'],
            ]);

            ++$booked;
        }

        return $booked;
    }

    /** @param array<string, Actor> $people */
    private function registerUrgentCase(array $people): int
    {
        $this->app->registerUrgentCase()->execute($people['staff'], [
            'patient_name' => 'Selam Abebe',
            'patient_age_months' => '14',
            'guardian_name' => 'Tigist Abebe',
            'guardian_phone' => self::PATIENT_PHONE_PREFIX . '101',
            'guardian_relationship' => 'Mother',
            'referring_physician_name' => 'Dr Peter Referrer',
            'referring_facility' => 'Demo Referral Hospital',
            'referring_physician_phone' => self::PATIENT_PHONE_PREFIX . '102',
            'receiving_hospital_name' => 'Demo General Hospital',
            'clinical_summary' => 'Fourteen-month-old with colicky abdominal pain, vomiting and '
                . 'blood-stained stool for six hours. Ultrasound shows a target sign in the right '
                . 'lower quadrant.',
            'imaging_summary' => 'Transverse ultrasound of the right lower quadrant demonstrates a '
                . 'target sign measuring 2.8 cm, consistent with ileocolic intussusception.',
        ]);

        return 1;
    }

    /**
     * @param array{procedure: string, urgency: string, summary: string, site: string} $spec
     */
    private function referral(Actor $actor, int $number, string $name, array $spec): Referral
    {
        return $this->app->submitReferral()->execute($actor, [
            'patient_name' => $name,
            'patient_phone' => self::PATIENT_PHONE_PREFIX . str_pad((string) (200 + $number), 3, '0'),
            'patient_dob' => sprintf('19%02d-0%d-1%d', 60 + $number, 1 + ($number % 8), $number % 9),
            'guardian_name' => '',
            'guardian_phone' => '',
            'guardian_relationship' => '',
            'procedure_code' => $spec['procedure'],
            'requested_urgency' => $spec['urgency'],
            'clinical_summary' => $spec['summary'],
            'target_site' => $spec['site'],
            'referring_physician_name' => 'Dr Peter Referrer',
            'referring_facility' => 'Demo Referral Hospital',
            'referring_physician_phone' => self::PATIENT_PHONE_PREFIX . '100',
        ]);
    }

    /** @param list<ReferralStatus> $path */
    private function advance(Actor $actor, Referral $referral, array $path): void
    {
        foreach ($path as $target) {
            $this->app->triageReferral()->execute($actor, $referral->id, $target);
        }
    }

    private function approve(Actor $radiologist, Referral $referral): void
    {
        $this->app->recordClinicalDecision()->execute($radiologist, $referral->id, [
            'decision_type' => 'approved',
            // Demo data, not a clinical claim: an approval must state a category (§13.1,
            // §12.4), and seeding nothing would leave the demo queue inconsistent with
            // the rule the rest of the product enforces.
            'clinical_priority' => 'routine',
            'rationale' => 'Appropriate for image-guided sampling. The request documents the '
                . 'indication and the target, and the patient is fit for a day-case procedure.',
            'approved_technique' => 'Ultrasound-guided core biopsy',
            'modality' => 'ultrasound',
            'sedation' => 'Local anaesthetic',
            'estimated_duration_minutes' => '30',
            'preprocedure_conditions' => 'Check coagulation profile and stop anticoagulants '
                . 'according to the clinic protocol.',
        ]);
    }

    /**
     * The first weekday the demo rooms are open, as a clinic-local date.
     *
     * Appointments have to land on a day the rooms are actually open, or the schedule
     * board shows a booking the calendar says is impossible.
     */
    private function nextOpenDay(): DateTimeImmutable
    {
        $zone = new DateTimeZone($this->app->config()->timezone());
        $today = new DateTimeImmutable('now', $zone);

        for ($offset = 1; $offset <= 14; ++$offset) {
            $candidate = $today->modify('+' . $offset . ' days')->setTime(0, 0);

            if ((int) $candidate->format('N') <= 5) {
                return $candidate;
            }
        }

        throw new RuntimeException('The next fourteen days contain no weekday.');
    }

    /** @param non-empty-string $time clinic-local HH:MM */
    private function utc(DateTimeImmutable $day, string $time): string
    {
        return $day->setTime(
            (int) substr($time, 0, 2),
            (int) substr($time, 3, 2),
        )->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
