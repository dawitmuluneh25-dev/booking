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
     * Record a message about an account rather than an appointment.
     *
     * Same contract as record(): it joins the caller's transaction and never opens
     * one. A password reset has no booking and no referral, which is why the outbox
     * was widened to carry an account (0039).
     *
     * @param list<OutboundMessage> $messages
     */
    public function recordForUser(int $userId, array $messages): void;

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

    /**
     * Empty a message body that must not outlive the send.
     *
     * A password reset link is a bearer credential: whoever holds it can take over
     * the account. It has to sit in the payload for the dispatcher to render it,
     * but once the provider has accepted the message the copy in our database is
     * no longer delivering anything — it is only waiting to appear in a backup.
     * The provider identifier and the times remain; the body does not.
     *
     * Called after a successful send, never before: scrubbing a queued message
     * would leave a row that can never be rendered and will fail forever.
     */
    public function scrubPayload(int $id): void;

    public function markFailed(int $id, string $error, string $availableAtUtc): void;

    public function markDead(int $id, string $error): void;

    /**
     * Record that the provider reported this message delivered.
     *
     * The row is found by the provider's own identifier, because that is the only
     * handle a delivery report carries (0023). A receipt is accepted only for a
     * message this system sent: a report about a message we never handed over is
     * either a provider error or a forged callback, and recording it would make
     * the row claim a delivery that cannot have happened.
     *
     * Returns whether a receipt was recorded. Repeating the same report is not an
     * error — providers retry callbacks — and does not move the timestamp.
     */
    public function markDelivered(string $providerMessageId, string $deliveredAtUtc): bool;

    /** @return list<OutboundMessage> */
    public function recent(int $limit): array;
}
