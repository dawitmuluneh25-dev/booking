<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\ClinicalDocumentRetentionService;
use App\Domain\RetentionPolicy;
use App\Domain\StorageKey;
use App\Repositories\PdoClinicalDocumentRetentionRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InMemoryDocumentStorage;

/**
 * The uploaded-document sweep against the real schema.
 *
 * Two things only this level can prove. First, that a document whose parent referral is **still live** is
 * swept on its own 1,825-day period — the case the referral sweep structurally cannot reach, because it
 * only looks at referrals past 2,557 days. Second, that the parent referral is left alone: the whole point
 * of a separate trigger is that it retires the attachment without destroying the record the schedule still
 * protects.
 *
 * Getting either wrong is invisible in a unit test with a fake repository, which is why the fixture builds
 * a real referral with a real foreign key.
 */
final class ClinicalDocumentRetentionTest extends TestCase
{
    /** Marks every row this test owns, so a shared development database is never swept by accident. */
    private const PREFIX = 'T-DOCRET-';

    private ?PDO $pdo = null;

    private ?PdoClinicalDocumentRetentionRepository $repository = null;

    private InMemoryDocumentStorage $storage;

    private int $userId = 0;

    private int $referralId = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $this->pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('No database available: ' . $exception->getMessage());
        }

        $this->repository = new PdoClinicalDocumentRetentionRepository($this->pdo);
        $this->storage = new InMemoryDocumentStorage();
        $this->clean();
        $this->userId = $this->insertUser();
        $this->referralId = $this->insertReferral();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    public function testADocumentOnALiveReferralIsSweptOnItsOwnPeriod(): void
    {
        $key = $this->key('1');
        $this->storage->seed($key, 'letter');

        $documentId = $this->insertDocument($key, time() - (2000 * 86400));

        // The referral is ten days old: comfortably inside its 2,557-day period.
        $freshReferral = $this->referralIsRedacted();

        self::assertFalse($freshReferral, 'Fixture precondition: the referral must still be live.');

        $result = $this->service()->purge('cli:test', 10, 5);

        self::assertSame(1, $result['documents']);
        self::assertSame(1, $result['files']);
        self::assertFalse($this->storage->exists($key));

        $row = $this->documentRow($documentId);

        self::assertSame('[redacted]', $row['original_name']);
        self::assertNotNull($row['redacted_at']);
        self::assertNotNull($row['deleted_at']);

        // The point of a separate trigger: the referral is untouched.
        self::assertFalse($this->referralIsRedacted(), 'The parent referral must not be redacted by this sweep.');
    }

    public function testADocumentInsideItsOwnPeriodIsLeftAloneEvenOnAnOldReferral(): void
    {
        $key = $this->key('2');
        $this->storage->seed($key, 'letter');

        $documentId = $this->insertDocument($key, time() - 10);

        self::assertSame(0, $this->service()->purge('cli:test', 10, 5)['documents']);

        $row = $this->documentRow($documentId);

        self::assertNotSame('[redacted]', $row['original_name']);
        self::assertNull($row['redacted_at']);
        self::assertTrue($this->storage->exists($key));
    }

    public function testTheRunIsRecordedAgainstTheUploadedDocumentColumn(): void
    {
        $revealed = $this->service()->purge('cli:test', 10, 5);

        self::assertSame(0, $revealed['documents']);

        $this->insertDocument($this->key('3'), time() - (2000 * 86400));
        $this->storage->seed($this->key('3'), 'letter');

        $this->service()->purge('cli:test:upload', 10, 5);

        $row = $this->query(
            'SELECT uploaded_documents_redacted, documents_redacted
               FROM retention_runs WHERE actor = :actor ORDER BY id DESC LIMIT 1',
            ['actor' => 'cli:test:upload'],
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        $uploaded = $row['uploaded_documents_redacted'] ?? null;
        $referralFiles = $row['documents_redacted'] ?? null;

        self::assertTrue(is_numeric($uploaded) && (int) $uploaded === 1);
        // The referral sweep's counter stays zero: two different acts, two different columns.
        self::assertTrue(is_numeric($referralFiles) && (int) $referralFiles === 0);
    }

    private function service(): ClinicalDocumentRetentionService
    {
        return new ClinicalDocumentRetentionService(
            $this->repository ?? self::fail('No repository'),
            $this->storage,
            new RetentionPolicy(1825),
        );
    }

    private function insertUser(): int
    {
        $this->query(
            'INSERT INTO users (email, name, role, password_hash, is_active, auth_version, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, 1, :created_at, :updated_at)',
            [
                'email' => self::PREFIX . 'owner@example.invalid',
                'name' => 'Doc Retention Fixture',
                'role' => 'admin',
                'hash' => password_hash('fixture', PASSWORD_DEFAULT),
                // Two names for the same instant: PDO with native prepares rejects a named placeholder
                // used twice in one statement, and the error reads as "invalid parameter number".
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
        );

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertReferral(): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->query(
            'INSERT INTO referrals
                (reference, status, patient_name, patient_phone, procedure_code, requested_urgency,
                 submitted_by_user_id, submitted_on_behalf_of, created_at, updated_at)
             VALUES
                 (:reference, :status, :name, :phone, :procedure, :urgency, :user, :on_behalf,
                  :created_at, :updated_at)',
            [
                'reference' => self::PREFIX . '0001',
                'status' => 'submitted',
                'name' => 'Fixture Patient',
                'phone' => '+251 000 000 111',
                'procedure' => 'image_guided_fnac',
                'urgency' => 'routine',
                'user' => $this->userId,
                'on_behalf' => 'self',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertDocument(StorageKey $key, int $createdAt): int
    {
        $this->query(
            'INSERT INTO referral_documents
                (document_ref, referral_id, kind, original_name, mime_type, file_extension, byte_size,
                 sha256, storage_key, uploaded_by_user_id, created_at)
             VALUES
                (:ref, :referral, :kind, :name, :mime, :extension, 11, :sha, :key, :user, :created_at)',
            [
                'ref' => substr(hash('sha256', $key->value), 0, 32),
                'referral' => $this->referralId,
                'kind' => 'referral_letter',
                'name' => 'patient-letter.pdf',
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'sha' => hash('sha256', $key->value),
                'key' => $key->value,
                'user' => $this->userId,
                'created_at' => gmdate('Y-m-d H:i:s', $createdAt),
            ],
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function documentRow(int $documentId): array
    {
        $row = $this->query(
            'SELECT original_name, redacted_at, deleted_at FROM referral_documents WHERE id = :id',
            ['id' => $documentId],
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        return $row;
    }

    private function referralIsRedacted(): bool
    {
        $value = $this->query(
            'SELECT redacted_at FROM referrals WHERE id = :id',
            ['id' => $this->referralId],
        )->fetchColumn();

        return $value !== null && $value !== false;
    }

    private function clean(): void
    {
        $this->pdo?->prepare('DELETE FROM retention_runs WHERE actor LIKE :actor')
            ->execute(['actor' => 'cli:test%']);
        $this->pdo?->prepare(
            'DELETE d FROM referral_documents d
               JOIN referrals r ON r.id = d.referral_id
              WHERE r.reference LIKE :reference'
        )->execute(['reference' => self::PREFIX . '%']);
        $this->pdo?->prepare('DELETE FROM referrals WHERE reference LIKE :reference')
            ->execute(['reference' => self::PREFIX . '%']);
        $this->pdo?->prepare('DELETE FROM users WHERE email LIKE :email')
            ->execute(['email' => self::PREFIX . '%']);
    }

    /** @param array<string, mixed> $parameters */
    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('No database connection; the test was skipped.');
        }

        return $this->pdo;
    }

    private function key(string $digit): StorageKey
    {
        return StorageKey::fromString(substr(str_repeat($digit, 32), 0, 32));
    }
}
