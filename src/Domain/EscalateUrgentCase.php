<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Escalate an urgent case that is not moving.
 *
 * Escalation is recorded as an event, not as a one-way state: a case can be
 * escalated, still not resolved, and escalated again at a higher level, and each
 * of those is its own row with its own reason and assignee. WFS 7.x says the same
 * thing — escalation may recur and is not a substitute for acknowledgement or a
 * clinical decision.
 *
 * Timing rules (how long before a case is overdue) are clinical governance, not
 * code (CG-08). This command records the judgement a person made; it does not
 * decide when to make it.
 */
final class EscalateUrgentCase
{
    public function __construct(private readonly UrgentCaseRepository $repository)
    {
    }

    /**
     * @throws UrgentCaseNotFoundException the case does not exist
     * @throws UrgentTransitionException   the role may not escalate, or the case is
     *                                     already resolved
     * @throws ValidationException         the level or the reason is missing
     */
    public function execute(
        Actor $actor,
        int $caseId,
        int $level,
        string $reason,
        ?int $assignToUserId = null,
        ?string $nowUtc = null,
    ): UrgentCase {
        $case = $this->repository->findById($caseId);

        if ($case === null) {
            throw new UrgentCaseNotFoundException($caseId);
        }

        $reason = trim($reason);
        $errors = [];

        if ($level < 1 || $level > 5) {
            $errors['level'] = 'Escalation level must be between 1 and 5.';
        }

        if (mb_strlen($reason) < 10) {
            $errors['reason'] = 'Say why this is being escalated, in at least ten characters.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // A case already escalated may be escalated again at a higher level; that is
        // a new fact about the case rather than a move it has to make.
        $repeat = $case->coordination === UrgentCoordination::Escalated;

        if (!$repeat && !$case->coordination->canTransitionTo(UrgentCoordination::Escalated)) {
            throw UrgentTransitionException::forCoordination(
                $case->coordination,
                UrgentCoordination::Escalated,
                'This case is ' . $case->coordination->value . ' and cannot be escalated.',
            );
        }

        return $this->repository->escalate(
            $case,
            $actor,
            $level,
            $reason,
            $assignToUserId,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
