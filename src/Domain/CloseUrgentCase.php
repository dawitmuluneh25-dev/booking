<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Close an urgent case, with the record of what happened to the child.
 *
 * Closure is not a tidy-up. §10.3 step 10 says the case "remains open until
 * accountable handover is documented", and `INT-023` makes the outcome mandatory at
 * closure. So the note is the substance of the command, not a comment attached to
 * it: a closed case with no outcome leaves the next reader unable to tell whether
 * the procedure happened, the child was transferred, or nothing happened at all.
 *
 * The command owns the note. The state machine owns the rest — which roles may
 * close (Coordinator and Administrator, never a radiologist alone) and from which
 * states — and the repository owns the guarded write, including `closed_at`. Three
 * layers, each refusing a different way of getting this wrong.
 */
final class CloseUrgentCase
{
    private const MIN_NOTE = 10;

    private const MAX_NOTE = 4000;

    public function __construct(private readonly UrgentCaseRepository $repository)
    {
    }

    /**
     * @throws AuthorisationException      the actor does not coordinate urgent cases
     * @throws UrgentCaseNotFoundException the case does not exist
     * @throws ValidationException         the outcome note is missing or too long
     * @throws UrgentTransitionException   the case cannot be closed, or not by this role
     */
    public function execute(
        Actor $actor,
        int $caseId,
        string $note,
        ?string $nowUtc = null,
    ): UrgentCase {
        if (!$actor->can(Permission::UrgentCoordinate)) {
            throw new AuthorisationException($actor->role, Permission::UrgentCoordinate);
        }

        $case = $this->repository->findById($caseId);

        if ($case === null) {
            throw new UrgentCaseNotFoundException($caseId);
        }

        $note = trim($note);
        $length = mb_strlen($note);

        if ($length < self::MIN_NOTE) {
            throw new ValidationException([
                'resolution_note' => 'Record what happened to this patient, in at least '
                    . self::MIN_NOTE . ' characters.',
            ]);
        }

        if ($length > self::MAX_NOTE) {
            throw new ValidationException([
                'resolution_note' => 'Keep the resolution note under ' . self::MAX_NOTE . ' characters.',
            ]);
        }

        return $this->repository->transitionUrgency(
            $case,
            UrgentStatus::Closed,
            $actor,
            $note,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
