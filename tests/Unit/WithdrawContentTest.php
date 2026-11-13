<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\ContentStatus;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Domain\WithdrawContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\ContentFactory;
use Tests\Support\InMemoryContentRepository;

/**
 * Taking a page down.
 *
 * §17.5 says to hide withdrawn content, not to forget it. So the assertions here
 * are that the record survives, that the withdrawal is attributed, and that the
 * page genuinely stops being served — which is the only part a patient notices.
 */
final class WithdrawContentTest extends TestCase
{
    private InMemoryContentRepository $content;

    private WithdrawContent $withdraw;

    protected function setUp(): void
    {
        $this->content = new InMemoryContentRepository();
        $this->withdraw = new WithdrawContent($this->content);
    }

    public function testReceptionCannotWithdrawPatientFacingCopy(): void
    {
        $page = $this->content->seed(ContentFactory::published());

        $this->expectException(AuthorisationException::class);

        $this->withdraw->execute(new Actor(7, Role::Staff), $page->id);
    }

    public function testAWithdrawnPageKeepsItsRecordAndStopsBeingServed(): void
    {
        $page = $this->content->seed(ContentFactory::published());
        self::assertTrue($page->isServableOn('2026-11-13'));

        $withdrawn = $this->withdraw->execute(new Actor(1, Role::Admin), $page->id, '2026-11-14 09:00:00');

        self::assertSame(ContentStatus::Withdrawn, $withdrawn->status);
        self::assertSame('2026-11-14 09:00:00', $withdrawn->withdrawnAtUtc);
        self::assertSame(1, $withdrawn->withdrawnByUserId);
        // The provenance the page carried is still there: what was approved and by
        // whom does not stop being true because the page came down.
        self::assertSame('Dawit Muluneh', $withdrawn->approver);
        self::assertFalse($withdrawn->isServableOn('2026-11-14'));
    }

    public function testADraftCannotBeWithdrawn(): void
    {
        $draft = $this->content->seed(ContentFactory::content());

        try {
            $this->withdraw->execute(new Actor(1, Role::Admin), $draft->id);
            self::fail('A draft was withdrawn, writing a withdrawal for an act that did not happen.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('draft', $exception->errors['status'] ?? '');
        }

        self::assertNull($this->content->find($draft->id)?->withdrawnAtUtc);
    }

    public function testASecondWithdrawalIsRefused(): void
    {
        $page = $this->content->seed(ContentFactory::published());
        $this->withdraw->execute(new Actor(1, Role::Admin), $page->id, '2026-11-14 09:00:00');

        try {
            $this->withdraw->execute(new Actor(1, Role::Admin), $page->id, '2026-11-15 09:00:00');
            self::fail('A withdrawn page was withdrawn again.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('withdrawn', $exception->errors['status'] ?? '');
        }

        self::assertSame('2026-11-14 09:00:00', $this->content->find($page->id)?->withdrawnAtUtc);
    }
}
