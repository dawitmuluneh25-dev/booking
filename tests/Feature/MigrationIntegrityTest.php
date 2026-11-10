<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\Migrator;
use App\Domain\ReferralStatus;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Proves the migration set applies to an empty schema and that the invariants the
 * workflow documents rely on are actually enforced by the database.
 *
 * Migrations 0009-0011 were written against a schema that already carried data
 * from 0001-0008, which hides ordering mistakes: a table that references a
 * missing parent only fails on a clean install. This test therefore builds a
 * throwaway schema from nothing, asserts structure, and then exercises the
 * constraints instead of trusting that `SHOW CREATE TABLE` looks right.
 *
 * The scratch schema is dropped afterwards. It is skipped, not failed, when
 * MySQL is unreachable or the account cannot create a database, because the unit
 * suite has to run on a host without a server.
 */
final class MigrationIntegrityTest extends TestCase
{
    private const SCRATCH_DATABASE = 'booking_app_migration_test';

    private const CORE_TABLES = [
        'appointment_resources',
        'audit_events',
        'appointment_status_history',
        'appointments',
        'booking_events',
        'booking_slots',
        'bookings',
        'clinic_closures',
        'consent_records',
        'patient_relationships',
        'physician_profiles',
        'clinic_hours',
        'idempotency_keys',
        'notification_outbox',
        'resource_availability',
        'resource_slots',
        'resources',
        'retention_runs',
    ];

    private const REFERRAL_TABLES = [
        'clinical_decisions',
        'document_access_events',
        'referral_documents',
        'referral_status_history',
        'referral_tracking_tokens',
        'referrals',
    ];

    private const URGENT_TABLES = [
        'urgent_acknowledgements',
        'urgent_cases',
        'urgent_status_history',
        'urgent_contact_attempts',
        'urgent_escalations',
        'urgent_events',
    ];

    private static ?PDO $pdo = null;

    /** @var list<string> */
    private static array $applied = [];

    private static string $migrationDirectory = '';

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);
        $settings = $config->database();
        self::$migrationDirectory = $root . '/database/migrations';

        $pdo = self::openScratchSchema($settings);
        self::$pdo = $pdo;
        self::dropAllTables($pdo);

        $migrator = new Migrator($pdo, self::$migrationDirectory);
        self::$applied = $migrator->migrate();
    }

    public static function tearDownAfterClass(): void
    {
        // The scratch schema is left in place minus its tables so a repeated run
        // does not need CREATE DATABASE rights; every run starts from empty.
        if (self::$pdo !== null) {
            self::dropAllTables(self::$pdo);
        }

        self::$pdo = null;
        self::$applied = [];
    }

    /**
     * Opens the scratch schema, creating it if the account is allowed to.
     *
     * Runtime credentials are deliberately unprivileged, so a developer needs to
     * provision the schema once with `.tools/bootstrap.sql`. When it is missing
     * and cannot be created the test skips with the reason rather than failing,
     * because the unit suite must run on a host with no database at all.
     *
     * @param array{
     *     host: string, port: int, database: string,
     *     username: string, password: string, charset: string
     * } $settings
     */
    private static function openScratchSchema(array $settings): PDO
    {
        $scratch = ['database' => self::SCRATCH_DATABASE] + $settings;

        try {
            return Connection::create($scratch, true);
        } catch (PDOException | RuntimeException $exception) {
            $reason = $exception instanceof PDOException
                ? (int) ($exception->errorInfo[1] ?? 0)
                : 0;

            if ($reason !== 1049) {
                self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
            }
        }

        try {
            $admin = Connection::create(['database' => ''] + $settings, true);
            $admin->exec(
                'CREATE DATABASE `' . self::SCRATCH_DATABASE . '` CHARACTER SET utf8mb4'
            );
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped(
                'The scratch schema ' . self::SCRATCH_DATABASE . ' does not exist and cannot be '
                . 'created: ' . $exception->getMessage() . '. Provision it once with the GRANT in '
                . '.tools/bootstrap.sql.'
            );
        }

        return Connection::create($scratch, true);
    }

    private static function dropAllTables(PDO $pdo): void
    {
        $statement = $pdo->query(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );

        if ($statement === false) {
            return;
        }

        $tables = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                $tables[] = $row['name'];
            }
        }

        if ($tables === []) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testEveryMigrationAppliesToAnEmptySchema(): void
    {
        $files = glob(self::$migrationDirectory . DIRECTORY_SEPARATOR . '*.sql') ?: [];

        self::assertNotSame([], $files, 'No migration files were found.');
        self::assertCount(
            count($files),
            self::$applied,
            'Every migration must apply to an empty schema.'
        );

        // A second run must be a no-op rather than a re-application.
        $again = (new Migrator(self::pdo(), self::$migrationDirectory))->migrate();
        self::assertSame([], $again, 'Applied migrations must not run twice.');
    }

    public function testAllExpectedTablesExist(): void
    {
        $tables = $this->tableNames();

        foreach (array_merge(self::CORE_TABLES, self::REFERRAL_TABLES, self::URGENT_TABLES) as $table) {
            self::assertContains($table, $tables, 'Missing table ' . $table . '.');
        }

        self::assertContains('users', $tables);
        self::assertContains('schema_migrations', $tables);
    }

    public function testEveryForeignKeyDeclaresAnExplicitDeleteRule(): void
    {
        $rows = $this->query(
            'SELECT rc.CONSTRAINT_NAME AS name, rc.DELETE_RULE AS rule
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()'
        );

        self::assertNotSame([], $rows, 'No foreign keys were created at all.');

        foreach ($rows as $row) {
            // NO ACTION means nobody decided; the schema spec requires an
            // explicit choice on every relationship.
            self::assertNotSame(
                'NO ACTION',
                self::text($row['rule']),
                'Foreign key ' . self::text($row['name']) . ' has no explicit delete rule.'
            );
        }
    }

    public function testHistoryTablesRefuseCascadeDelete(): void
    {
        $historyTables = [
            'referral_status_history',
            'clinical_decisions',
            'urgent_acknowledgements',
            'urgent_contact_attempts',
            'urgent_escalations',
            'urgent_events',
            'appointment_status_history',
            'audit_events',
        ];

        foreach ($historyTables as $table) {
            foreach ($this->foreignKeysOf($table) as $foreignKey) {
                self::assertNotSame(
                    'CASCADE',
                    $foreignKey['rule'],
                    $table . ' must not cascade-delete history through ' . $foreignKey['name'] . '.'
                );
            }
        }
    }

    public function testResourceSlotPrimaryKeyIsTheReservationGuard(): void
    {
        // The double-booking guard is the primary key, not a SELECT that raced, so
        // the assertion is on key structure: one row per resource per instant.
        self::assertSame(
            ['resource_id', 'slot_start'],
            $this->primaryKeyColumns('resource_slots')
        );
    }

    public function testReferralStatusColumnAcceptsExactlyTheDomainVocabulary(): void
    {
        // The column and the enum are two statements of the same rule; if they
        // drift, the application can offer a transition the database refuses. So
        // ask the database which values it accepts rather than reading the clause
        // text, which is stored in MySQL's own escaped form.
        $this->insertUser('reception-probe@example.test', 'staff');
        $userId = $this->userId('reception-probe@example.test');

        $statement = self::pdo()->prepare(
            "INSERT INTO referrals (reference, status, patient_name, patient_phone,
                procedure_code, submitted_by_user_id, submitted_on_behalf_of,
                created_at, updated_at)
             VALUES ('REF-VOCAB-1', 'submitted', 'Probe Patient', '+251911000003',
                'image_guided_biopsy', :user, 'referrer', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute(['user' => $userId]);
        $referralId = (int) self::pdo()->lastInsertId();

        foreach (ReferralStatus::cases() as $case) {
            self::assertTrue(
                $this->acceptsValue('referrals', 'status', $referralId, $case->value),
                'The enum offers ' . $case->value . ' but ck_referrals_status refuses it.'
            );
        }

        foreach (['scheduled', 'in_progress', 'completed', 'checked_in', 'approved'] as $state) {
            self::assertFalse(
                $this->acceptsValue('referrals', 'status', $referralId, $state),
                'WF-01/WF-15: a referral status must not borrow the state ' . $state . '.'
            );
        }
    }

    public function testUrgentVocabularyExcludesElectiveAndAppointmentStates(): void
    {
        $this->insertUrgentCase('URG-VOCAB-1', 'received');
        $caseId = $this->urgentCaseId('URG-VOCAB-1');

        $urgencyStates = [
            'received',
            'under_clinical_review',
            'accepted_for_coordination',
            'redirected',
            'declined',
            'transferred',
            'completed',
            'closed',
        ];

        foreach ($urgencyStates as $state) {
            self::assertTrue(
                $this->acceptsValue('urgent_cases', 'urgency_status', $caseId, $state),
                'ck_urgent_status refuses ' . $state . '.'
            );
        }

        foreach (['converted_to_appointment', 'approved_for_scheduling', 'scheduled'] as $elective) {
            self::assertFalse(
                $this->acceptsValue('urgent_cases', 'urgency_status', $caseId, $elective),
                'WF-04: an urgent case must not borrow the elective state ' . $elective . '.'
            );
        }

        // Acknowledgement is tracked apart from the clinical status, because a
        // case can be acknowledged while still under clinical review.
        foreach (['unacknowledged', 'acknowledged', 'contacting', 'coordinating', 'escalated', 'resolved'] as $state) {
            self::assertTrue($this->acceptsValue('urgent_cases', 'coordination_status', $caseId, $state));
        }

        foreach (['scheduled', 'completed'] as $appointmentState) {
            self::assertFalse(
                $this->acceptsValue('urgent_cases', 'coordination_status', $caseId, $appointmentState)
            );
        }
    }

    public function testUrgentAggregateIsSeparateFromElectiveAndScheduling(): void
    {
        $allowedParents = ['users', 'urgent_cases'];

        foreach (self::URGENT_TABLES as $table) {
            foreach ($this->foreignKeysOf($table) as $foreignKey) {
                self::assertContains(
                    $foreignKey['referenced'],
                    $allowedParents,
                    'WF-04: ' . $table . ' must not reach into ' . $foreignKey['referenced'] . '.'
                );
            }
        }
    }

    public function testUrgentWorkflowHasNoPaymentGate(): void
    {
        $forbidden = '/payment|invoice|billing|fee|price|charge|subscription/i';

        foreach (self::URGENT_TABLES as $table) {
            foreach ($this->columnNames($table) as $column) {
                self::assertDoesNotMatchRegularExpression(
                    $forbidden,
                    $column,
                    'WF-07: ' . $table . '.' . $column . ' would let billing gate urgent care.'
                );
            }
        }
    }

    public function testReferralRoleWideningIsEnforced(): void
    {
        $this->insertUser('coordinator-probe@example.test', 'coordinator');

        $this->expectException(PDOException::class);
        $this->insertUser('unknown-role-probe@example.test', 'superuser');
    }

    public function testUrgentCaseRejectsAnElectiveOrAppointmentStatus(): void
    {
        try {
            $this->insertUrgentCase('URG-BAD-1', 'scheduled');
            self::fail('An appointment state was accepted as an urgent urgency_status.');
        } catch (PDOException) {
            // Expected: the CHECK constraint keeps the vocabularies apart.
        }

        $this->insertUrgentCase('URG-OK-1', 'received');
        self::assertSame(1, $this->countWhere('urgent_cases', "reference = 'URG-OK-1'"));
    }

    public function testTelephoneContactAttemptNeedsTheNumberThatWasDialled(): void
    {
        $this->insertUrgentCase('URG-CONTACT-1', 'received');
        $caseId = $this->urgentCaseId('URG-CONTACT-1');
        $userId = $this->userId('coordinator-probe@example.test');

        try {
            $this->insertContactAttempt($caseId, $userId, 'telephone', null);
            self::fail('WF-06: a telephone attempt without a number was accepted.');
        } catch (PDOException) {
            // Expected.
        }

        $this->insertContactAttempt($caseId, $userId, 'telephone', '+251911000000');
        self::assertSame(
            1,
            $this->countWhere('urgent_contact_attempts', 'urgent_case_id = ' . $caseId)
        );

        // History is append-only: a recorded attempt blocks deleting its case.
        try {
            self::pdo()->exec('DELETE FROM urgent_cases WHERE id = ' . $caseId);
            self::fail('WF-09: a case with contact history was deleted.');
        } catch (PDOException) {
            self::assertSame(1, $this->countWhere('urgent_cases', 'id = ' . $caseId));
        }
    }

    public function testClinicalDecisionVocabularyAndSupersedeChain(): void
    {
        $this->insertUser('radiologist-probe@example.test', 'radiologist');
        $userId = $this->userId('radiologist-probe@example.test');

        self::pdo()->exec(
            "INSERT INTO referrals (reference, status, patient_name, patient_phone,
                procedure_code, submitted_by_user_id, submitted_on_behalf_of,
                created_at, updated_at)
             VALUES ('REF-PROBE-1', 'clinical_review', 'Probe Patient', '+251911000001',
                'image_guided_biopsy', " . $userId . ", 'referrer', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $referralId = (int) self::pdo()->lastInsertId();

        // The column set is the vocabulary; a decision type outside the agreed
        // five must not be storable. Whether a rationale is *meaningful* is a
        // command-layer question (a whitespace string satisfies NOT NULL), so that
        // is deliberately not asserted here.
        try {
            self::pdo()->exec(
                'INSERT INTO clinical_decisions (referral_id, decision_type, rationale,
                    decided_by_user_id, decided_at)
                 VALUES (' . $referralId . ", 'rubber_stamped', 'not a real decision', "
                . $userId . ', UTC_TIMESTAMP())'
            );
            self::fail('An unknown decision type was accepted.');
        } catch (PDOException) {
            self::assertSame(
                0,
                $this->countWhere('clinical_decisions', 'referral_id = ' . $referralId)
            );
        }

        self::pdo()->exec(
            'INSERT INTO clinical_decisions (referral_id, decision_type, rationale,
                decided_by_user_id, decided_at)
             VALUES (' . $referralId . ", 'approved', 'Imaging reviewed; suitable for biopsy.', "
            . $userId . ', UTC_TIMESTAMP())'
        );
        $first = (int) self::pdo()->lastInsertId();

        self::pdo()->exec(
            'INSERT INTO clinical_decisions (referral_id, decision_type, rationale,
                decided_by_user_id, decided_at, supersedes_decision_id)
             VALUES (' . $referralId . ", 'deferred', 'Coagulation results outstanding.', "
            . $userId . ', UTC_TIMESTAMP(), ' . $first . ')'
        );

        try {
            self::pdo()->exec(
                'INSERT INTO clinical_decisions (referral_id, decision_type, rationale,
                    decided_by_user_id, decided_at, supersedes_decision_id)
                 VALUES (' . $referralId . ", 'declined', 'Duplicate supersede attempt.', "
                . $userId . ', UTC_TIMESTAMP(), ' . $first . ')'
            );
            self::fail('A decision was superseded twice.');
        } catch (PDOException) {
            // Expected: uq_decisions_supersedes keeps the chain linear.
        }

        self::assertSame(2, $this->countWhere('clinical_decisions', 'referral_id = ' . $referralId));
    }

    /**
     * 0016: the document vocabulary, the opaque reference and the quarantine
     * default are enforced by the database, not by the repository that happens to
     * write them.
     */
    public function testDocumentStorageInvariantsAreEnforced(): void
    {
        $this->insertUser('document-probe@example.test', 'staff');
        $userId = $this->userId('document-probe@example.test');

        self::pdo()->exec(
            "INSERT INTO referrals (reference, status, patient_name, patient_phone,
                procedure_code, submitted_by_user_id, submitted_on_behalf_of,
                created_at, updated_at)
             VALUES ('REF-DOC-1', 'awaiting_imaging', 'Probe Patient', '+251911000002',
                'image_guided_biopsy', " . $userId . ", 'referrer', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $referralId = (int) self::pdo()->lastInsertId();

        $insert = static function (string $ref, string $scanStatus) use ($referralId, $userId): void {
            self::pdo()->exec(
                'INSERT INTO referral_documents
                    (document_ref, referral_id, kind, original_name, mime_type, byte_size, sha256,
                     scan_status, storage_key, uploaded_by_user_id, created_at)
                 VALUES (' . self::pdo()->quote($ref) . ', ' . $referralId . ", 'imaging_report',
                    'scan.pdf', 'application/pdf', 12, '" . str_repeat('c', 64) . "',
                    " . self::pdo()->quote($scanStatus) . ', ' . self::pdo()->quote(bin2hex(random_bytes(16)))
                    . ', ' . $userId . ', UTC_TIMESTAMP())'
            );
        };

        // The default is quarantine, not release: a row inserted without an
        // explicit verdict must not be servable.
        self::pdo()->exec(
            'INSERT INTO referral_documents
                (document_ref, referral_id, kind, original_name, mime_type, byte_size, sha256,
                 storage_key, uploaded_by_user_id, created_at)
             VALUES (' . self::pdo()->quote(str_repeat('1', 32)) . ', ' . $referralId . ", 'other',
                'letter.pdf', 'application/pdf', 10, '" . str_repeat('d', 64) . "', '"
                . bin2hex(random_bytes(16)) . "', " . $userId . ', UTC_TIMESTAMP())'
        );

        $default = $this->query(
            'SELECT scan_status, access_class FROM referral_documents WHERE document_ref = :ref',
            ['ref' => str_repeat('1', 32)]
        );
        self::assertSame('pending', self::text($default[0]['scan_status'] ?? null));
        self::assertSame('clinical_private', self::text($default[0]['access_class'] ?? null));

        // The opaque reference is unique: two rows may not share a URL identity.
        $insert(str_repeat('2', 32), 'pending');
        try {
            $insert(str_repeat('2', 32), 'pending');
            self::fail('Two documents shared one reference.');
        } catch (PDOException) {
            $where = "document_ref = '" . str_repeat('2', 32) . "'";
            self::assertSame(1, $this->countWhere('referral_documents', $where));
        }

        // The scan vocabulary is closed.
        try {
            $insert(str_repeat('3', 32), 'probably_fine');
            self::fail('An invented scan status was accepted.');
        } catch (PDOException) {
            $where = "document_ref = '" . str_repeat('3', 32) . "'";
            self::assertSame(0, $this->countWhere('referral_documents', $where));
        }

        $defaultRow = $this->query(
            'SELECT id FROM referral_documents WHERE document_ref = :ref',
            ['ref' => str_repeat('1', 32)]
        );
        $defaultDocumentId = self::number($defaultRow[0]['id'] ?? null);

        foreach (['pending', 'clean', 'infected', 'error', 'not_required'] as $status) {
            self::assertTrue(
                $this->acceptsValue('referral_documents', 'scan_status', $defaultDocumentId, $status),
                'ck_documents_scan refuses ' . $status . '.'
            );
        }

        // A document with access history cannot be deleted: the audit chain wins
        // over convenience, and retention redacts instead of removing (SEC 14.4).
        $documentId = $this->query(
            'SELECT id FROM referral_documents WHERE document_ref = :ref',
            ['ref' => str_repeat('2', 32)]
        );
        $documentIdValue = self::number($documentId[0]['id'] ?? null);

        self::pdo()->exec(
            "INSERT INTO document_access_events (document_id, user_id, action, outcome, occurred_at)
             VALUES (" . $documentIdValue . ', ' . $userId . ", 'download', 'allowed', UTC_TIMESTAMP())"
        );

        try {
            self::pdo()->exec('DELETE FROM referral_documents WHERE id = ' . $documentIdValue);
            self::fail('A document with access history was deleted.');
        } catch (PDOException) {
            self::assertSame(1, $this->countWhere('referral_documents', 'id = ' . $documentIdValue));
        }

        // Status-link tokens are unique by hash and cannot outlive their referral.
        self::pdo()->exec(
            "INSERT INTO referral_tracking_tokens (referral_id, token_hash, expires_at, created_at)
             VALUES (" . $referralId . ", '" . str_repeat('e', 64) . "', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        $this->expectException(PDOException::class);
        self::pdo()->exec(
            "INSERT INTO referral_tracking_tokens (referral_id, token_hash, expires_at, created_at)
             VALUES (" . $referralId . ", '" . str_repeat('e', 64) . "', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
    }

    /**
     * 0019: `procedure_code` is a reference, not a string that happens to look
     * like one, and a clinical field set cannot be added without naming who
     * approved it (REF-02, ADM-03).
     */
    public function testProcedureCatalogueRefusesUnknownCodesAndUnapprovedFieldSets(): void
    {
        $this->insertUser('procedure-probe@example.test', 'staff');
        $userId = $this->userId('procedure-probe@example.test');

        $insert = static function (string $code) use ($userId): void {
            $statement = self::pdo()->prepare(
                "INSERT INTO referrals (reference, status, patient_name, patient_phone,
                    procedure_code, submitted_by_user_id, submitted_on_behalf_of,
                    created_at, updated_at)
                 VALUES ('REF-PROC-1', 'submitted', 'Probe Patient', '+251911000009',
                    :code, :user, 'referrer', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            );
            $statement->execute(['code' => $code, 'user' => $userId]);
        };

        try {
            $insert('not_a_procedure');
            self::fail('An unknown procedure code was accepted by the database.');
        } catch (PDOException) {
            // Expected: fk_referrals_procedure.
        }

        $insert('image_guided_biopsy');
        self::assertSame(1, $this->countWhere('referrals', "reference = 'REF-PROC-1'"));

        // A field set with nobody's name on it is exactly what REF-02 forbids.
        try {
            self::pdo()->exec(
                "UPDATE procedures SET required_fields = '{\"lesion_size\":\"required\"}'
                 WHERE code = 'image_guided_biopsy'"
            );
            self::fail('An unapproved clinical field set was stored.');
        } catch (PDOException) {
            // Expected: ck_procedures_approved.
        }

        self::pdo()->exec(
            "UPDATE procedures SET required_fields = '{\"lesion_size\":\"required\"}',
                approved_by = 'Clinical lead', approved_at = UTC_TIMESTAMP()
             WHERE code = 'image_guided_biopsy'"
        );

        $rows = $this->query(
            "SELECT approved_by FROM procedures WHERE code = 'image_guided_biopsy'"
        );
        self::assertSame('Clinical lead', self::text($rows[0]['approved_by'] ?? null));
    }

    private static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('The scratch schema was not initialised.');
        }

        return self::$pdo;
    }

    /** Reads a value the driver may return as any scalar. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $rows = $this->query(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = :type',
            ['type' => 'BASE TABLE']
        );

        $names = [];
        foreach ($rows as $row) {
            $names[] = self::text($row['name']);
        }

        return $names;
    }

    /** @return list<string> */
    private function columnNames(string $table): array
    {
        $rows = $this->query(
            'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => $table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = self::text($row['name']);
        }

        self::assertNotSame([], $columns, 'Table ' . $table . ' does not exist.');

        return $columns;
    }

    /** @return list<string> */
    private function primaryKeyColumns(string $table): array
    {
        $rows = $this->query(
            "SELECT COLUMN_NAME AS name FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = 'PRIMARY'
             ORDER BY SEQ_IN_INDEX",
            ['table' => $table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = self::text($row['name']);
        }

        return $columns;
    }

    /**
     * @return list<array{name: string, rule: string, referenced: string}>
     */
    private function foreignKeysOf(string $table): array
    {
        $rows = $this->query(
            'SELECT rc.CONSTRAINT_NAME AS name, rc.DELETE_RULE AS rule,
                    kcu.REFERENCED_TABLE_NAME AS referenced
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
              AND kcu.TABLE_NAME = rc.TABLE_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.TABLE_NAME = :table
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             GROUP BY rc.CONSTRAINT_NAME, rc.DELETE_RULE, kcu.REFERENCED_TABLE_NAME',
            ['table' => $table]
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[] = [
                'name' => self::text($row['name']),
                'rule' => self::text($row['rule']),
                'referenced' => self::text($row['referenced']),
            ];
        }

        return $keys;
    }

    /**
     * Asks the database whether a column will store a value, by trying it.
     *
     * `$table`, `$column` and `$id` are only ever internal constants, never input.
     */
    private function acceptsValue(string $table, string $column, int $id, string $value): bool
    {
        try {
            $update = self::pdo()->prepare(
                'UPDATE ' . $table . ' SET ' . $column . ' = :value WHERE id = :id'
            );
            $update->execute(['value' => $value, 'id' => $id]);
        } catch (PDOException) {
            return false;
        }

        // Read it back: an update that was accepted but not stored is still a
        // rejection as far as the caller is concerned.
        $rows = $this->query(
            'SELECT ' . $column . ' AS current_value FROM ' . $table . ' WHERE id = :id',
            ['id' => (string) $id]
        );

        return ($rows[0]['current_value'] ?? null) === $value;
    }

    /**
     * @param array<string, string> $parameters
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $parameters = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    private function countWhere(string $table, string $condition): int
    {
        $rows = $this->query('SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . $condition);

        return self::number($rows[0]['total'] ?? 0);
    }

    private function insertUser(string $email, string $role): void
    {
        $statement = self::pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => $email,
            'name' => 'Probe ' . $role,
            'role' => $role,
            'hash' => str_repeat('0', 60),
        ]);
    }

    private function insertUrgentCase(string $reference, string $urgencyStatus): void
    {
        $statement = self::pdo()->prepare(
            'INSERT INTO urgent_cases (reference, procedure_code, urgency_status,
                patient_name, guardian_phone, clinical_summary, received_at,
                created_at, updated_at)
             VALUES (:reference, :procedure, :urgency, :patient, :phone, :summary,
                UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'reference' => $reference,
            'procedure' => 'hydrostatic_intussusception_reduction',
            'urgency' => $urgencyStatus,
            'patient' => 'Probe Child',
            'phone' => '+251911000002',
            'summary' => 'Probe clinical summary.',
        ]);
    }

    private function insertContactAttempt(int $caseId, int $userId, string $channel, ?string $phone): void
    {
        $statement = self::pdo()->prepare(
            'INSERT INTO urgent_contact_attempts (urgent_case_id, attempted_by_user_id,
                contact_role, contact_name, contact_phone, channel, outcome, attempted_at)
             VALUES (:case, :user, :role, :name, :phone, :channel, :outcome, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'case' => $caseId,
            'user' => $userId,
            'role' => 'patient_family',
            'name' => 'Probe Guardian',
            'phone' => $phone,
            'channel' => $channel,
            'outcome' => 'answered',
        ]);
    }

    private function urgentCaseId(string $reference): int
    {
        $rows = $this->query(
            'SELECT id FROM urgent_cases WHERE reference = :reference',
            ['reference' => $reference]
        );

        self::assertNotSame([], $rows, 'Urgent case ' . $reference . ' was not created.');

        return self::number($rows[0]['id']);
    }

    private function userId(string $email): int
    {
        $rows = $this->query('SELECT id FROM users WHERE email = :email', ['email' => $email]);

        self::assertNotSame([], $rows, 'User ' . $email . ' was not created.');

        return self::number($rows[0]['id']);
    }
}
