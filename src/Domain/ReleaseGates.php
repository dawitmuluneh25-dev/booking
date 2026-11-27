<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * HR-ENG-DEC-R1-003 §3.2's seven release gates, assessed against what this host can see.
 *
 * The record states the gates in a table: what must be true, and who is accountable for it being true.
 * That table has lived in three places since 22 November — the decision record, §4 and §29 of the plan,
 * and the heads of the people who were in the room. This class is the table, executable: one row per
 * gate, the authority that owns it, the evidence it requires, and the state of that evidence here.
 *
 * Three states, and the middle one is why this exists:
 *
 *   - **met** — every element of the required evidence was observed. Not "believed": observed.
 *   - **partial** — some elements are observed and the rest is either an attestation the repository
 *     cannot check (a Legal validation expressed as a flag) or a record that does not exist to be
 *     checked (a restore drill, a named rota). A gate in this state is not a pass, and the report says
 *     exactly which element is missing rather than shading the whole row.
 *   - **open** — the required evidence is absent.
 *
 * **What this class deliberately does not do:** decide anything. It cannot approve a policy, validate a
 * retention period, sign a pathway, appoint a person, or attest that a drill ran. It reports, and names
 * the authority in the row, so the next step is a conversation with a person rather than a hunt through
 * documents. §3.3 is the reason for the restraint — "no item in this record authorizes production
 * release by itself" — and a command that could turn its own rows green would be the mechanism that
 * decision forbids.
 */
final class ReleaseGates
{
    public const MET = 'met';

    public const PARTIAL = 'partial';

    public const OPEN = 'open';

    /**
     * §3.2, transcribed. The order is the record's order, and the strings are the record's strings:
     * a report that renames a gate is a report nobody can check against the decision.
     *
     * @var list<array{id: string, name: string, authority: string, evidence: string}>
     */
    public const GATES = [
        [
            'id' => 'policy_publication',
            'name' => 'Policy publication',
            'authority' => 'Legal / Data Protection',
            'evidence' => 'Approved policy content and dates',
        ],
        [
            'id' => 'retention_activation',
            'name' => 'Retention activation',
            'authority' => 'Legal / Data Protection',
            'evidence' => 'Validated retention periods and exceptions',
        ],
        [
            'id' => 'intussusception_activation',
            'name' => 'Intussusception service activation',
            'authority' => 'Clinical Approver / Receiving institution',
            'evidence' => 'Verified clinical and receiving-institution sign-off',
        ],
        [
            'id' => 'escalation_routes',
            'name' => 'Escalation-route activation',
            'authority' => 'Operations / Clinical',
            'evidence' => 'Real contacts, responsibility, hours, acknowledgement and fallback verified',
        ],
        [
            'id' => 'operational_coverage',
            'name' => 'Operational coverage',
            'authority' => 'Operations',
            'evidence' => 'Named individuals and documented coverage',
        ],
        [
            'id' => 'backup_readiness',
            'name' => 'Backup production readiness',
            'authority' => 'Engineering / Operations',
            'evidence' => 'Encrypted backup and successful restore test',
        ],
        [
            'id' => 'credential_readiness',
            'name' => 'Credential production readiness',
            'authority' => 'Engineering / Owner',
            'evidence' => 'Credential inventory and recovery/rotation procedure',
        ],
    ];

    public function __construct(private readonly ReleaseGateFacts $facts)
    {
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     authority: string,
     *     evidence: string,
     *     state: string,
     *     detail: string
     * }>
     */
    public function assess(): array
    {
        $assessments = [
            'policy_publication' => $this->policyPublication(),
            'retention_activation' => $this->retentionActivation(),
            'intussusception_activation' => $this->intussusceptionActivation(),
            'escalation_routes' => $this->escalationRoutes(),
            'operational_coverage' => $this->operationalCoverage(),
            'backup_readiness' => $this->backupReadiness(),
            'credential_readiness' => $this->credentialReadiness(),
        ];

        $report = [];

        foreach (self::GATES as $gate) {
            $assessment = $assessments[$gate['id']];

            $report[] = $gate + [
                'state' => $assessment['state'],
                'detail' => $assessment['detail'],
            ];
        }

        return $report;
    }

    /**
     * How many gates are not met — the number that has to reach zero before release, and the number the
     * command exits on.
     *
     * @param list<array{state: string}> $report
     */
    public static function blockedCount(array $report): int
    {
        $blocked = 0;

        foreach ($report as $row) {
            if ($row['state'] !== self::MET) {
                $blocked++;
            }
        }

        return $blocked;
    }

    /** @return array{state: string, detail: string} */
    private function policyPublication(): array
    {
        $facts = $this->facts;

        if ($facts->policyPagesTotal === 0) {
            return [
                'state' => self::OPEN,
                'detail' => 'No policy page is in the content source at all; nothing can be published.',
            ];
        }

        if ($facts->policyPagesPublished === $facts->policyPagesTotal) {
            return [
                'state' => self::MET,
                'detail' => $facts->policyPagesPublished . ' of ' . $facts->policyPagesTotal
                    . ' policy pages are published with an effective date.',
            ];
        }

        return [
            'state' => self::OPEN,
            'detail' => $facts->policyPagesPublished . ' of ' . $facts->policyPagesTotal
                . ' policy pages are published with an effective date. D-01 needs an approver name and an'
                . ' approval date from Legal / Data Protection; until both exist the publish gate refuses'
                . ' the row (ck_published_content_complete).',
        ];
    }

    /**
     * A flag is an attestation, not evidence. A record with a fingerprint is evidence of an attestation.
     *
     * `RETENTION_SCHEDULE_ACTIVE=true` means somebody in Legal's name decided the periods in D-02 may
     * run. Nothing in this repository can confirm that decision was taken, so a set flag alone is
     * `partial` — the activation is real, the validation behind it is asserted.
     *
     * That was the whole story until 26 November 2026, and it made the gate impossible to close: a
     * row that can only say "asserted" forever stops being read. The record at
     * `database/operations/retention-validation.php` carries the validator, the date, the scope and a
     * fingerprint of the schedule they validated, so the gate can now ask a sharper question — does the
     * schedule this build executes still match the one that was validated? If it does, the row is
     * `met`; if the table moved, the fingerprint moved with it and the row is `partial` again with the
     * change named. The approval is still a human act; only its scope is now checkable.
     *
     * @return array{state: string, detail: string}
     */
    private function retentionActivation(): array
    {
        if (!$this->facts->retentionActive) {
            return [
                'state' => self::OPEN,
                'detail' => 'RETENTION_SCHEDULE_ACTIVE is off, so D-02\'s per-class schedule does not run;'
                    . ' retention:purge still applies the single seven-year window. Activation is a Legal'
                    . ' decision, not a deployment flag.',
            ];
        }

        if ($this->facts->retentionValidation === \App\Support\RetentionValidationRecord::CURRENT) {
            return [
                'state' => self::MET,
                'detail' => ($this->facts->retentionValidationDetail ?? 'The validation record matches the'
                    . ' schedule this build executes.') . ' One class still has a period and no sweep'
                    . ' (clinical_and_procedure, because `procedures` is a catalogue and its milestones belong'
                    . ' to appointments); that is a coverage gap reported by retention:plan, not a validation'
                    . ' gap, and it does not make the period wrong.',
            ];
        }

        return [
            'state' => self::PARTIAL,
            'detail' => 'RETENTION_SCHEDULE_ACTIVE is on and the class sweeps run: bookings, referrals and'
                . ' documents at their class period, outbox rows retired at 365 days. '
                . ($this->facts->retentionValidationDetail ?? 'No validation record could be read.')
                . ' D-02 requires Legal / Data Protection to validate the periods, lawful basis and'
                . ' exceptions before production activation; the record at'
                . ' database/operations/retention-validation.php is where that is asserted.',
        ];
    }

    /** @return array{state: string, detail: string} */
    private function intussusceptionActivation(): array
    {
        if ($this->facts->intussusceptionPublished) {
            return [
                'state' => self::MET,
                'detail' => 'The page is published, which is only possible with an effective date, which'
                    . ' D-03 makes contingent on the recorded sign-off.',
            ];
        }

        return [
            'state' => self::OPEN,
            'detail' => 'The page is held as an unpublishable draft: no effective date, because D-03 makes'
                . ' publication contingent on the Clinical Approver and the receiving paediatric/surgical'
                . ' authority. Neither signature is recorded anywhere this repository can read.',
        ];
    }

    /** @return array{state: string, detail: string} */
    private function escalationRoutes(): array
    {
        $facts = $this->facts;

        if ($facts->escalationRoutesTotal === 0) {
            return [
                'state' => self::OPEN,
                'detail' => 'No escalation route exists. escalation:seed-register creates the five D-06'
                    . ' categories empty and inactive.',
            ];
        }

        if ($facts->escalationRoutesVerified === $facts->escalationRoutesTotal) {
            return [
                'state' => self::MET,
                'detail' => $facts->escalationRoutesVerified . ' of ' . $facts->escalationRoutesTotal
                    . ' routes are active, carry a contact and were verified by a named person.',
            ];
        }

        return [
            'state' => self::OPEN,
            'detail' => $facts->escalationRoutesVerified . ' of ' . $facts->escalationRoutesTotal
                . ' routes are active and verified. The rest are the D-06 placeholders: real contacts,'
                . ' responsibility, hours, acknowledgement and a fallback are Operations / Clinical\'s to'
                . ' supply, and a route that has never been called is not verified by being filled in.',
        ];
    }

    /**
     * Names are not a repository artefact.
     *
     * D-07 defines five appointments with titles and responsibilities and deliberately names nobody:
     * a person named in a commit would be an identity engineering invented, which that decision
     * forbids. So the fact is null — unobservable — and the gate cannot be closed from here however
     * complete the code is.
     *
     * @return array{state: string, detail: string}
     */
    private function operationalCoverage(): array
    {
        $facts = $this->facts;

        if ($facts->appointmentsDefined === 0) {
            return [
                'state' => self::OPEN,
                'detail' => 'No operating appointments are defined.',
            ];
        }

        if ($facts->appointmentsNamed === null) {
            return [
                'state' => self::PARTIAL,
                'detail' => $facts->appointmentsDefined . ' appointments are defined with titles and'
                    . ' responsibilities, but this database has no table holding the holders — migration'
                    . ' 0048 has not been applied here, so coverage is unobservable from this host.',
            ];
        }

        // "Covered" means a holder and a deputy, which is the rule the Operations screen states. A
        // holder with no deputy is an appointment with no cover, and counting it would make this row
        // greener than the page a coordinator reads.
        $detail = $facts->appointmentsNamed . ' of ' . $facts->appointmentsDefined
            . ' appointments have both a holder and a deputy.';

        return [
            'state' => $facts->appointmentsNamed >= $facts->appointmentsDefined ? self::MET : self::OPEN,
            'detail' => $detail,
        ];
    }

    /** @return array{state: string, detail: string} */
    private function backupReadiness(): array
    {
        $facts = $this->facts;

        if (!$facts->backupKeyConfigured) {
            return [
                'state' => self::OPEN,
                'detail' => 'BACKUP_ENCRYPTION_KEY is not set, so archives are plain gzipped SQL. D-09'
                    . ' makes the hourly job fail closed once it is set; until then it is not met.',
            ];
        }

        if ($facts->encryptedArchives === 0) {
            return [
                'state' => self::OPEN,
                'detail' => 'The key is set but no encrypted archive exists on this host yet.',
            ];
        }

        if ($facts->restoreDrillRecorded === true) {
            return [
                'state' => self::MET,
                'detail' => $facts->encryptedArchives . ' encrypted archive(s) on disk, key configured,'
                    . ' and a successful restore drill is on record.',
            ];
        }

        return [
            'state' => self::PARTIAL,
            'detail' => $facts->encryptedArchives . ' encrypted archive(s) on disk and the key is'
                . ' configured, but no successful restore drill within the last 90 days is recorded.'
                . ' Run `php bin/console db:restore-drill --record`: the row is written only after the'
                . ' comparison passes, because a backup nobody has restored is a hypothesis.',
        ];
    }

    /** @return array{state: string, detail: string} */
    private function credentialReadiness(): array
    {
        $facts = $this->facts;

        if ($facts->credentialsTotal === 0) {
            return [
                'state' => self::OPEN,
                'detail' => 'No credential register exists.',
            ];
        }

        $gaps = [];

        if ($facts->credentialsWithRotation < $facts->credentialsTotal) {
            $gaps[] = ($facts->credentialsTotal - $facts->credentialsWithRotation)
                . ' with no rotation on record';
        }

        if ($facts->credentialsWithCustodian < $facts->credentialsTotal) {
            $gaps[] = ($facts->credentialsTotal - $facts->credentialsWithCustodian)
                . ' with no named custodian';
        }

        if (!$facts->recoveryKeyPresent) {
            $gaps[] = 'no independent recovery copy of the backup key (D-08)';
        }

        if ($gaps === []) {
            return [
                'state' => self::MET,
                'detail' => $facts->credentialsTotal . ' credentials registered, rotated, held by a named'
                    . ' custodian, with the recovery copy in place.',
            ];
        }

        return [
            'state' => self::PARTIAL,
            'detail' => 'The register and the rotation procedures exist (' . $facts->credentialsTotal
                . ' credentials), so the inventory half of this gate is met. Outstanding: '
                . implode('; ', $gaps) . '. Under D-10 the panel password is already an exposure event.',
        ];
    }
}
