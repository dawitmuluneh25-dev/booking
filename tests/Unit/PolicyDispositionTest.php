<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PolicyController;
use App\Domain\ContentStatus;
use App\Domain\PublishedContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\ContentFactory;

/**
 * D-12's three-way rule: show, hold, or 404.
 *
 * The rule is small and the stakes are not. Before this decision, a visitor clicking "Privacy" in the
 * footer of a site that collects referral data got a 404 — and after D-01 the same click would still
 * have 404'd, because approval does not make a page effective until its effective date.
 *
 * The line that must not move is the third one: **everything that is not a policy key keeps the old
 * 404 discipline.** A withdrawn page, a draft and a key that never existed are identical from outside
 * by design, and widening the holding page to cover them would turn an information-disclosure rule
 * into a courtesy.
 */
final class PolicyDispositionTest extends TestCase
{
    private const POLICY = 'policy.privacy';

    private const SERVICE = 'service.fnac';

    public function testAPolicyKeyWithNoRecordAtAllIsHeldRatherThanLost(): void
    {
        self::assertSame(
            PolicyController::PENDING,
            PolicyController::disposition(null, self::POLICY, '2026-11-25'),
        );
    }

    public function testAPageThatNeverExistedStillDisappears(): void
    {
        self::assertSame(
            PolicyController::NOT_FOUND,
            PolicyController::disposition(null, 'policy.invented', '2026-11-25'),
        );
    }

    public function testAServiceKeyKeepsTheOldDiscipline(): void
    {
        self::assertSame(
            PolicyController::NOT_FOUND,
            PolicyController::disposition(null, self::SERVICE, '2026-11-25'),
        );
    }

    public function testAnApprovedButNotYetEffectivePolicyIsHeld(): void
    {
        $page = $this->page([
            'status' => ContentStatus::Published,
            'effectiveOn' => '2026-12-18',
        ]);

        self::assertSame(
            PolicyController::PENDING,
            PolicyController::disposition($page, self::POLICY, '2026-11-25'),
            'Approval is not the same as being in force; the effective date decides.'
        );
    }

    public function testAnEffectivePolicyIsShown(): void
    {
        $page = $this->page([
            'status' => ContentStatus::Published,
            'effectiveOn' => '2026-11-25',
        ]);

        self::assertSame(
            PolicyController::SHOW,
            PolicyController::disposition($page, self::POLICY, '2026-11-25'),
        );
    }

    public function testAWithdrawnPolicyIsHeldRatherThanExplained(): void
    {
        $page = $this->page([
            'status' => ContentStatus::Withdrawn,
            'effectiveOn' => '2026-11-25',
        ]);

        self::assertSame(
            PolicyController::PENDING,
            PolicyController::disposition($page, self::POLICY, '2026-11-25'),
        );
    }

    public function testAWithdrawnServicePageIsStillA404(): void
    {
        $page = $this->page([
            'status' => ContentStatus::Withdrawn,
            'effectiveOn' => '2026-11-25',
        ]);

        self::assertSame(
            PolicyController::NOT_FOUND,
            PolicyController::disposition($page, self::SERVICE, '2026-11-25'),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function page(array $overrides): PublishedContent
    {
        return ContentFactory::content(self::POLICY, $overrides + ['clinicalReviewer' => null]);
    }
}
