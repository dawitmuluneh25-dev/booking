<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\ContentStatus;
use App\Domain\Role;
use App\Repositories\PdoContentRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ContentFactory;

/**
 * Patient-facing content against MySQL (§17.5).
 *
 * The point of these tests is that two of §17.5's rules are enforced by the schema
 * rather than by the command: a row cannot reach 'published' with a field missing,
 * and two versions of one page cannot be live at once. Both are asserted here by
 * going around the repository and writing SQL directly, because a rule that only
 * holds when the application asks politely is not a rule.
 */
final class ContentRepositoryTest extends TestCase
{
    private const NOW = '2026-11-13 10:00:00';

    private ?PDO $pdo = null;

    private ?PdoContentRepository $repository = null;

    private int $actorUserId = 0;

    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $this->pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->repository = new PdoContentRepository($this->pdo);
        $this->actorUserId = $this->insertUser();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach ($this->createdIds as $id) {
                $this->pdo->exec('DELETE FROM published_content WHERE id = ' . $id);
            }

            if ($this->actorUserId > 0) {
                $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->actorUserId);
            }
        }

        $this->createdIds = [];
        $this->actorUserId = 0;
        $this->pdo = null;
        $this->repository = null;
    }

    public function testADraftRoundTripsWithItsProvenanceAndItsSections(): void
    {
        $id = $this->import(ContentFactory::content('service.roundtrip'));

        $reloaded = $this->repository()->find($id);

        self::assertNotNull($reloaded);
        self::assertSame('service.roundtrip', $reloaded->contentKey);
        self::assertSame('1.0', $reloaded->version);
        self::assertSame(ContentStatus::Draft, $reloaded->status);
        self::assertSame('Dawit Muluneh', $reloaded->approver);
        self::assertSame('2026-11-13', $reloaded->approvedOn);
        self::assertSame('Dr Reviewer', $reloaded->clinicalReviewer);
        // A field the record genuinely does not have must come back absent rather
        // than as an empty string that reads like a value.
        self::assertNull($reloaded->supersededVersion);
        self::assertSame('A sampling procedure.', $reloaded->sections()[0]['paragraphs'][0]);
    }

    public function testImportingTheSameVersionTwiceDoesNotCreateASecondCopy(): void
    {
        $first = $this->import(ContentFactory::content('service.repeat'));
        $second = $this->import(ContentFactory::content('service.repeat'));

        self::assertSame($first, $second);

        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM published_content WHERE content_key = :key');
        $statement->execute(['key' => 'service.repeat']);

        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testReimportingADraftFillsInTheFieldsThatArrivedLater(): void
    {
        $id = $this->import(ContentFactory::content('service.later', [
            'clinicalReviewer' => null,
            'effectiveOn' => null,
        ]));

        $before = $this->repository()->find($id);
        self::assertNotNull($before);
        self::assertSame(['clinical reviewer', 'effective date'], $before->missingForPublication());

        // The reviewer's name and the effective date arrive afterwards. Re-running
        // the import has to be able to record them; otherwise the only way in would
        // be to delete the row and pretend it had never been imported.
        $updated = $this->repository()->reconcileDraft(
            $id,
            ContentFactory::content('service.later'),
            '2026-11-14 09:00:00',
        );

        self::assertTrue($updated);

        $after = $this->repository()->find($id);
        self::assertNotNull($after);
        self::assertSame('Dr Reviewer', $after->clinicalReviewer);
        self::assertSame('2026-11-13', $after->effectiveOn);
        self::assertSame(ContentStatus::Draft, $after->status, 'Reconciliation changed the status.');
        self::assertSame('2026-11-13 10:00:00', $after->createdAtUtc, 'Reconciliation rewrote when it was imported.');
    }

    public function testReconcilingLeavesAPublishedRowExactlyAsItWasApproved(): void
    {
        $id = $this->import(ContentFactory::content('service.approved'));
        $this->repository()->publish($id, $this->actorUserId, self::NOW);

        $updated = $this->repository()->reconcileDraft(
            $id,
            ContentFactory::content('service.approved', ['title' => 'Rewritten after approval']),
            '2026-11-14 09:00:00',
        );

        self::assertFalse($updated, 'An import was allowed to rewrite a published page.');

        $stored = $this->repository()->find($id);
        self::assertNotNull($stored);
        self::assertNotSame('Rewritten after approval', $stored->title);
    }

    public function testTheSchemaRefusesToPublishARowThatIsMissingAReviewer(): void
    {
        $id = $this->import(ContentFactory::content('service.incomplete', ['clinicalReviewer' => null]));

        $this->expectException(PDOException::class);

        // Straight past the command: this is the CHECK doing the work, not the
        // ValidationException the command would have thrown first.
        $this->pdo()->exec(
            "UPDATE published_content
                SET status = 'published', published_at = UTC_TIMESTAMP(), published_by_user_id = "
                . $this->actorUserId . '
              WHERE id = ' . $id
        );
    }

    public function testTheSchemaRefusesAReviewDateBeforeTheApprovalItReviews(): void
    {
        $this->expectException(PDOException::class);

        $this->import(ContentFactory::content('service.backwards', ['reviewOn' => '2026-01-01']));
    }

    public function testTwoVersionsOfOnePageCannotBothBeLive(): void
    {
        $first = $this->import(ContentFactory::content('service.one-live', ['version' => '1.0']));
        $this->repository()->publish($first, $this->actorUserId, self::NOW);

        $second = $this->import(ContentFactory::content('service.one-live', [
            'version' => '1.1',
            'supersededVersion' => '1.0',
        ]));

        $this->expectException(PDOException::class);

        $this->repository()->publish($second, $this->actorUserId, self::NOW);
    }

    public function testPublishingAndWithdrawingRecordWhoDidItAndWhen(): void
    {
        $id = $this->import(ContentFactory::content('service.attribution'));

        $this->repository()->publish($id, $this->actorUserId, self::NOW);

        $published = $this->repository()->find($id);
        self::assertNotNull($published);
        self::assertSame(ContentStatus::Published, $published->status);
        self::assertSame(self::NOW, $published->publishedAtUtc);
        self::assertSame($this->actorUserId, $published->publishedByUserId);

        $this->repository()->withdraw($id, $this->actorUserId, '2026-11-14 10:00:00');

        $withdrawn = $this->repository()->find($id);
        self::assertNotNull($withdrawn);
        self::assertSame(ContentStatus::Withdrawn, $withdrawn->status);
        self::assertSame('2026-11-14 10:00:00', $withdrawn->withdrawnAtUtc);
        self::assertSame($this->actorUserId, $withdrawn->withdrawnByUserId);
    }

    public function testTheServableQueryExcludesExpiredFutureAndWithdrawnPages(): void
    {
        $live = $this->import(ContentFactory::content('service.live'));
        $this->repository()->publish($live, $this->actorUserId, self::NOW);

        // Approved in October and due for review before today: expired, and it was
        // legitimate when it was approved, which is what makes it a real case.
        $expired = $this->import(ContentFactory::content('service.expired', [
            'approvedOn' => '2026-10-01',
            'effectiveOn' => '2026-10-01',
            'reviewOn' => '2026-11-12',
        ]));
        $this->repository()->publish($expired, $this->actorUserId, self::NOW);

        $future = $this->import(ContentFactory::content('service.future', ['effectiveOn' => '2026-12-01']));
        $this->repository()->publish($future, $this->actorUserId, self::NOW);

        $this->import(ContentFactory::content('service.draft'));

        $servable = $this->repository()->servable('2026-11-13');
        $keys = array_map(static fn ($page): string => $page->contentKey, $servable);

        self::assertContains('service.live', $keys);
        self::assertNotContains('service.expired', $keys, 'A page past its review date was served.');
        self::assertNotContains('service.future', $keys, 'A page before its effective date was served.');
        self::assertNotContains('service.draft', $keys, 'A draft was served.');
    }

    public function testTheKeyLanguageVersionTripleIsUnique(): void
    {
        $this->import(ContentFactory::content('service.triple'));

        $this->expectException(PDOException::class);

        $this->pdo()->exec(
            "INSERT INTO published_content
                (content_key, audience, language, title, body, version, author, status,
                 created_at, updated_at)
             VALUES
                ('service.triple', 'patient', 'en', 'Duplicate', '[]', '1.0', 'x', 'draft',
                 UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
    }

    private function import(\App\Domain\PublishedContent $content): int
    {
        $id = $this->repository()->importDraft($content, self::NOW);
        $this->createdIds[] = $id;

        return $id;
    }

    private function repository(): PdoContentRepository
    {
        if ($this->repository === null) {
            throw new RuntimeException('The repository was not initialised.');
        }

        return $this->repository;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The connection was not initialised.');
        }

        return $this->pdo;
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active, auth_version, created_at, updated_at)
             VALUES (:name, :email, :hash, :role, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'name' => 'Content Test Administrator',
            'email' => 'content-' . bin2hex(random_bytes(4)) . '@example.test',
            'hash' => password_hash('correct horse', PASSWORD_DEFAULT),
            'role' => Role::Admin->value,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }
}
