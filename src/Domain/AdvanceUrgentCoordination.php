<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Move the operational side of an urgent case forward: `coordinating`, or `resolved`.
 *
 * These are the two states of `UrgentCoordination` that had no writer. They are
 * operational statements, not clinical ones — "the receiving team is engaged and we
 * are arranging the procedure", and "there is nothing further to coordinate" — and
 * the aggregate already names who may make them (`Coordinator`, `Radiologist`).
 *
 * What this command deliberately does not do is infer either state:
 *
 *  - a contact attempt says somebody tried to reach someone, which is `contacting`,
 *    not `coordinating`; the difference is whether the other end engaged;
 *  - closing a case says the clinical question is settled, which is not the same as
 *    saying the coordination is finished — and an Administrator may close a case
 *    without being named as able to resolve it. Resolving on closure would hand them
 *    the act through the back door.
 *
 * So both moves are something a person states, and `UrgentCoordination::canProgressTo()`
 * is asked before either, because it is the method that says resolution needs an owner.
 */
final class AdvanceUrgentCoordination
{
    /**
     * The two states this command can enter.
     *
     * A whitelist rather than "any target": `acknowledged`, `contacting` and
     * `escalated` each have their own command with their own evidence — an
     * acknowledgement row, a contact attempt, an escalation with a reason. Accepting
     * them here would let a case be marked contacted with no record of a call.
     *
     * @var list<UrgentCoordination>
     */
    private const TARGETS = [UrgentCoordination::Coordinating, UrgentCoordination::Resolved];

    public function __construct(private readonly UrgentCaseRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException      the actor may not coordinate
     * @throws UrgentCaseNotFoundException the case does not exist
     * @throws ValidationException         the target is not one of the two, or does not fit
     * @throws UrgentTransitionException   the transition table refuses the move
     */
    public function execute(Actor $actor, int $caseId, array $input, ?string $nowUtc = null): UrgentCase
    {
        if (!$actor->can(Permission::UrgentCoordinate)) {
            throw new AuthorisationException($actor->role, Permission::UrgentCoordinate);
        }

        $case = $this->repository->findById($caseId);

        if ($case === null) {
            throw new UrgentCaseNotFoundException($caseId);
        }

        $target = UrgentCoordination::tryFrom($this->text($input, 'coordination'));

        if ($target === null || !in_array($target, self::TARGETS, true)) {
            throw new ValidationException([
                'coordination' => 'Choose what has actually happened: '
                    . implode(' or ', array_map(
                        static fn (UrgentCoordination $state): string => $state->label(),
                        self::TARGETS,
                    )) . '.',
            ]);
        }

        if (!$case->coordination->canTransitionTo($target)) {
            throw new UrgentTransitionException(
                $case->coordination->value,
                $target->value,
                'That move is not open from where this case is.',
                'coordination',
            );
        }

        if (!in_array($actor->role, $case->coordination->allowedActors($target), true)) {
            throw new UrgentTransitionException(
                $case->coordination->value,
                $target->value,
                sprintf('The %s role may not perform this transition.', $actor->role->value),
                'coordination',
            );
        }

        if (!$case->coordination->canProgressTo($target)) {
            throw new UrgentTransitionException(
                $case->coordination->value,
                $target->value,
                'Nobody has acknowledged this case, so there is nothing to have resolved.',
                'coordination',
            );
        }

        return $this->repository->transitionCoordination(
            $case,
            $target,
            $actor,
            $this->nullable($this->text($input, 'note')),
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
