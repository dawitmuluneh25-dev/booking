<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\AuditEvent;
use App\Domain\AuditQuery;
use App\Domain\AuditTrail;
use App\Domain\Role;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The parts of the audit trail that can be checked without a database: how a
 * change reads, how a query clamps itself, and the structural promise that the
 * trail has no way to rewrite what it has already recorded.
 */
final class AuditTrailTest extends TestCase
{
    public function testAChangeReadsAsFromTo(): void
    {
        $event = $this->event(
            before: ['role' => 'staff'],
            after: ['role' => 'radiologist'],
        );

        self::assertSame('role=staff → role=radiologist', $event->changeSummary());
    }

    public function testAnEventWithNoChangeSaysSoRatherThanShowingAnEmptyArrow(): void
    {
        self::assertSame('—', $this->event(before: null, after: null)->changeSummary());
        self::assertSame('role=staff', $this->event(before: null, after: ['role' => 'staff'])->changeSummary());
    }

    public function testTheLimitIsClampedRatherThanTrusted(): void
    {
        self::assertSame(AuditQuery::MAX_LIMIT, (new AuditQuery())->withLimit(100000)->limit);
        self::assertSame(1, (new AuditQuery())->withLimit(0)->limit);
        self::assertSame(50, (new AuditQuery())->withLimit(50)->limit);
    }

    public function testAnUnfilteredQuerySaysItIsUnfiltered(): void
    {
        self::assertTrue((new AuditQuery())->isEmpty());
        self::assertFalse((new AuditQuery(action: 'user.role_changed'))->isEmpty());
    }

    public function testTheTrailCannotRewriteWhatItRecorded(): void
    {
        // A correction is a new row. If the interface ever grows an update or a
        // delete, an "audit trail" becomes a table that can be made to agree with
        // whoever last had access to it.
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => strtolower($method->getName()),
            (new ReflectionClass(AuditTrail::class))->getMethods(),
        );

        self::assertSame(['record', 'search', 'count'], $methods);
    }

    public function testTheRepositoryIssuesNoUpdateOrDeleteAgainstTheTrail(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Repositories/PdoAuditTrail.php');

        if ($source === false) {
            throw new RuntimeException('The audit repository could not be read.');
        }

        // Statement-shaped, not word-shaped: the class docblock legitimately says
        // "there is no update() or delete() here", and a plain word match would
        // fail on the sentence that documents the rule.
        self::assertDoesNotMatchRegularExpression('/\bUPDATE\s+audit_events\b/i', $source);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\s+audit_events\b/i', $source);
    }

    public function testTheActionVocabularyIsUnique(): void
    {
        $values = array_map(
            static fn (AuditAction $action): string => $action->value,
            AuditAction::cases(),
        );

        self::assertSame($values, array_values(array_unique($values)));
    }

    public function testAnActorEntryCarriesTheActorAndNoPasswordField(): void
    {
        $entry = AuditEntry::byActor(
            actor: new \App\Domain\Actor(7, Role::Admin),
            action: AuditAction::UserRoleChanged,
            objectType: 'user',
            objectRef: '12',
            before: ['role' => 'staff'],
            after: ['role' => 'radiologist'],
        );

        self::assertSame(7, $entry->actorUserId);
        self::assertSame(Role::Admin, $entry->actorRole);
        self::assertNotSame('', $entry->occurredAtUtc);
        self::assertArrayNotHasKey('password', $entry->after ?? []);
    }

    /**
     * @param array<string, scalar|null>|null $before
     * @param array<string, scalar|null>|null $after
     */
    private function event(?array $before, ?array $after): AuditEvent
    {
        return new AuditEvent(
            id: 1,
            actorUserId: 7,
            actorEmail: 'admin@example.test',
            actorRole: 'admin',
            action: AuditAction::UserRoleChanged->value,
            objectType: 'user',
            objectRef: '12',
            before: $before,
            after: $after,
            occurredAtUtc: '2026-11-09 08:00:00',
            requestId: 'abc',
        );
    }
}
