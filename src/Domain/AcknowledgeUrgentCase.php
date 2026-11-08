<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Record that a named person has seen an urgent case and is acting on it.
 *
 * This is the only way a case stops being unacknowledged. It is not triggered by
 * a notification, a delivery receipt or a page view: WF-05 exists because "the
 * message was sent" and "somebody is dealing with this child" are different
 * facts, and only the second one matters at three in the morning.
 *
 * There is no permission check here on purpose. Acknowledgement is a claim by any
 * identified member of staff who may act on the case, and that set is already
 * declared by the transition table — a receptionist may acknowledge, a patient
 * may not. Adding a second, differently-shaped gate would let the two drift.
 */
final class AcknowledgeUrgentCase
{
    private const CHANNELS = ['application', 'telephone', 'in_person', 'other'];

    private const MAX_NOTE = 1000;

    public function __construct(private readonly UrgentCaseRepository $repository)
    {
    }

    /**
     * @throws UrgentCaseNotFoundException the case does not exist
     * @throws UrgentTransitionException   already acknowledged, or the role may not
     * @throws ValidationException         the channel is not one of the recorded ones
     */
    public function execute(
        Actor $actor,
        int $caseId,
        string $channel = 'application',
        ?string $note = null,
        ?string $nowUtc = null,
    ): UrgentCase {
        $case = $this->repository->findById($caseId);

        if ($case === null) {
            throw new UrgentCaseNotFoundException($caseId);
        }

        $channel = strtolower(trim($channel));

        if (!in_array($channel, self::CHANNELS, true)) {
            throw new ValidationException([
                'channel' => 'Acknowledge by ' . implode(', ', self::CHANNELS) . '.',
            ]);
        }

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
            throw new ValidationException([
                'note' => 'Keep the acknowledgement note under ' . self::MAX_NOTE . ' characters.',
            ]);
        }

        return $this->repository->acknowledge(
            $case,
            $actor,
            $channel,
            $note,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
