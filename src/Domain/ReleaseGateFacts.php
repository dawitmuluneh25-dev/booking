<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What this host can observe about HR-ENG-DEC-R1-003 §3.2's seven release gates.
 *
 * Every field is either a number, a boolean, or **null meaning "cannot be observed from here"**. The
 * distinction is the whole point of the type: a gate that depends on a signature, a rota, or a drill
 * someone performed on the host is not improved by pretending a `false` means "no". A `false` says
 * "checked, and absent"; a `null` says "no record exists to check", and the report has to say which.
 *
 * The object is built by the console command from the database, the environment and the credential
 * register. Nothing in it is computed here, which is what lets `ReleaseGates` be tested without a
 * database and keeps the queries in one place.
 */
final readonly class ReleaseGateFacts
{
    /**
     * @param int      $policyPagesTotal        `policy.%` pages the source file carries
     * @param int      $policyPagesPublished    those published with an effective date on or before today
     * @param bool     $retentionActive         `RETENTION_SCHEDULE_ACTIVE`, which only Legal can justify
     * @param bool     $intussusceptionPublished whether the restricted page is published at all
     * @param int      $escalationRoutesTotal   rows in `escalation_routes`
     * @param int      $escalationRoutesVerified active rows with a named contact and a verification
     * @param int      $appointmentsDefined     appointments the operating model defines
     * @param int|null $appointmentsNamed       null: the repository holds no names, by design
     * @param bool     $backupKeyConfigured     `BACKUP_ENCRYPTION_KEY` is set
     * @param int      $encryptedArchives       `*.enc` archives on disk
     * @param bool|null $restoreDrillRecorded   null: a drill prints its result and stores none
     * @param int      $credentialsTotal        credentials in the D-10 register
     * @param int      $credentialsWithRotation those carrying a rotation date
     * @param int      $credentialsWithCustodian those naming a person
     * @param bool     $recoveryKeyPresent      the D-08 independent recovery copy exists
     * @param string|null $retentionValidation  `RetentionValidationRecord::*`; null when the caller
     *                                          cannot read the register, which is not the same as
     *                                          "no validation" but sorts with it
     * @param string|null $retentionValidationDetail the record's own explanation, so the gate can
     *                                          quote it rather than paraphrase
     */
    public function __construct(
        public int $policyPagesTotal = 0,
        public int $policyPagesPublished = 0,
        public bool $retentionActive = false,
        public bool $intussusceptionPublished = false,
        public int $escalationRoutesTotal = 0,
        public int $escalationRoutesVerified = 0,
        public int $appointmentsDefined = 0,
        public ?int $appointmentsNamed = null,
        public bool $backupKeyConfigured = false,
        public int $encryptedArchives = 0,
        public ?bool $restoreDrillRecorded = null,
        public int $credentialsTotal = 0,
        public int $credentialsWithRotation = 0,
        public int $credentialsWithCustodian = 0,
        public bool $recoveryKeyPresent = false,
        public ?string $retentionValidation = null,
        public ?string $retentionValidationDetail = null,
    ) {
    }
}
