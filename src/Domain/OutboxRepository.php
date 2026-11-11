<?php

declare(strict_types=1);

namespace App\Domain;

interface OutboxRepository
{
    /**
     * Record what somebody must be told, inside the caller's transaction.
     *
     * It takes no lock and starts no transaction: the message has to commit or
     * roll back with the change it describes, never on its own (WF-14). A
     * duplicate — the same dedupe key — is ignored rather than raised, because a
     * retried request must not fail on a notification it already queued.
     *
     * @param int|null $bookingId  the legacy booking subject, when there is one
     * @param int|null $referralId the referral the change belongs to
     * @param list<OutboundMessage> $messages
     */
    public function record(?int $bookingId, ?int $referralId, array $messages): void;

    /**
     * Reserve a batch for this worker. Claims are atomic, so two concurrent
     * dispatchers cannot send the same row.
     *
     * @param string $staleBeforeUtc rows stuck in "sending" before this time are requeued
     *
     * @return list<OutboundMessage>
     */
    public function claim(int $limit, string $staleBeforeUtc): array;

    /**
     * Record acceptance. The provider identifier, when the provider issues one,
     * is what lets a later delivery report or bounce be matched to this message
     * rather than guessed at from recipient and time.
     */
    public function markSent(int $id, ?string $providerMessageId = null): void;

    public function markFailed(int $id, string $error, string $availableAtUtc): void;

    public function markDead(int $id, string $error): void;

    /** @return list<OutboundMessage> */
    public function recent(int $limit): array;
}
