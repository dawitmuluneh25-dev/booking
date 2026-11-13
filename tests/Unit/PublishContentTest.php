<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\ContentStatus;
use App\Domain\PublishContent;
use App\Domain\Role;
use App\Domain\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ContentFactory;
use Tests\Support\InMemoryContentRepository;

/**
 * Publishing is a named, dated act, and it is refused more often than it succeeds.
 *
 * Every refusal below corresponds to a way a patient-facing page can be wrong:
 * published by somebody without the authority, published with no reviewer behind
 * it, published twice so nobody knows who to ask, or published over a version that
 * is still live.
 */
final class PublishContentTest extends TestCase
{
    private InMemoryContentRepository $content;

    private PublishContent $publish;

    protected function setUp(): void
    {
        $this->content = new InMemoryContentRepository();
        $this->publish = new PublishContent($this->content);
    }

    public function testReceptionCannotPublishPatientFacingCopy(): void
    {
        $draft = $this->content->seed(ContentFactory::content());

        $this->expectException(AuthorisationException::class);

        $this->publish->execute(new Actor(7, Role::Staff), $draft->id);
    }

    public function testAPageWithNoClinicalReviewerIsRefusedAndTheRefusalNamesWhatIsMissing(): void
    {
        $draft = $this->content->seed(ContentFactory::content('service.fnac', [
            'clinicalReviewer' => null,
            'effectiveOn' => null,
            'reviewOn' => null,
        ]));

        try {
            $this->publish->execute($this->admin(), $draft->id);
            self::fail('A record with no reviewer and no dates was published.');
        } catch (ValidationException $exception) {
            $message = $exception->errors['status'] ?? '';

            self::assertStringContainsString('clinical reviewer', $message);
            self::assertStringContainsString('effective date', $message);
            self::assertStringContainsString('review date', $message);
            self::assertStringContainsString('§17.5', $message);
        }

        self::assertSame(ContentStatus::Draft, $this->content->find($draft->id)?->status);
    }

    public function testACompleteDraftIsPublishedWithTheActorAndTheTime(): void
    {
        $draft = $this->content->seed(ContentFactory::content());

        $published = $this->publish->execute($this->admin(), $draft->id, '2026-11-13 10:00:00');

        self::assertSame(ContentStatus::Published, $published->status);
        self::assertSame('2026-11-13 10:00:00', $published->publishedAtUtc);
        self::assertSame(1, $published->publishedByUserId);
    }

    public function testASecondPressIsRefusedRatherThanOverwritingWhoPublishedIt(): void
    {
        $draft = $this->content->seed(ContentFactory::content());
        $this->publish->execute($this->admin(), $draft->id, '2026-11-13 10:00:00');

        try {
            $this->publish->execute(new Actor(2, Role::Admin), $draft->id, '2026-11-14 10:00:00');
            self::fail('A published version was published again.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('already published', $exception->errors['status'] ?? '');
        }

        $stored = $this->content->find($draft->id);
        self::assertNotNull($stored);
        self::assertSame(
            '2026-11-13 10:00:00',
            $stored->publishedAtUtc,
            'The first publication record was overwritten.',
        );
        self::assertSame(1, $stored->publishedByUserId);
    }

    public function testAWithdrawnVersionIsNotReinstatedByPublishingItAgain(): void
    {
        $draft = $this->content->seed(ContentFactory::content());
        $this->publish->execute($this->admin(), $draft->id, '2026-11-13 10:00:00');
        $this->content->withdraw($draft->id, 1, '2026-11-14 10:00:00');

        try {
            $this->publish->execute($this->admin(), $draft->id, '2026-11-15 10:00:00');
            self::fail('A withdrawn version was published again, erasing the withdrawal.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('withdrawn', $exception->errors['status'] ?? '');
            self::assertStringContainsString('superseded', $exception->errors['status'] ?? '');
        }

        self::assertSame('2026-11-14 10:00:00', $this->content->find($draft->id)?->withdrawnAtUtc);
    }

    public function testASecondVersionCannotGoLiveWhileTheFirstStillIs(): void
    {
        $first = $this->content->seed(ContentFactory::content());
        $this->publish->execute($this->admin(), $first->id, '2026-11-13 10:00:00');

        $second = $this->content->seed(ContentFactory::content('service.fnac', [
            'id' => 2,
            'version' => '1.1',
            'supersededVersion' => '1.0',
        ]));

        try {
            $this->publish->execute($this->admin(), $second->id, '2026-11-14 10:00:00');
            self::fail('Two versions of one page were live at once.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('already live', $exception->errors['status'] ?? '');
        }
    }

    public function testAPageWithNoSectionsIsRefusedEvenWithEveryProvenanceField(): void
    {
        $draft = $this->content->seed(ContentFactory::content('service.fnac', [
            'body' => json_encode([], JSON_THROW_ON_ERROR),
        ]));

        $this->expectException(ValidationException::class);

        $this->publish->execute($this->admin(), $draft->id);
    }

    private function admin(): Actor
    {
        return new Actor(1, Role::Admin);
    }
}
