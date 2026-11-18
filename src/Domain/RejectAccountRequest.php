<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Refuse a request, with a reason and a name.
 *
 * The reason is required and at least ten characters, the same floor
 * `VerifyPhysician` uses for a rejection: "no" is not a decision anybody can review
 * later, and the queue is the only place the decision will ever be explained.
 *
 * Rejecting creates nothing. The requester is not emailed — there is no
 * acknowledgement on submission either, so an email at this point would be the first
 * signal the address was ever looked at.
 */
final class RejectAccountRequest
{
    public const MIN_REASON = 10;

    public function __construct(
        private readonly AccountRequestRepository $requests,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @throws AuthorisationException          the actor may not manage accounts
     * @throws AccountRequestNotFoundException no such request
     * @throws ValidationException             the reason is missing or the request is decided
     */
    public function execute(Actor $actor, int $requestId, string $reason, ?string $nowUtc = null): AccountRequest
    {
        if (!$actor->can(Permission::UsersManage)) {
            throw new AuthorisationException($actor->role, Permission::UsersManage);
        }

        $request = $this->requests->findById($requestId);

        if ($request === null) {
            throw new AccountRequestNotFoundException($requestId);
        }

        if (!$request->status->isDecidable()) {
            throw new ValidationException([
                'request' => 'This request is already ' . mb_strtolower($request->status->label()) . '.',
            ]);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw new ValidationException([
                'reason' => 'Say why the request was refused, in at least ' . self::MIN_REASON . ' characters.',
            ]);
        }

        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        if (!$this->requests->markRejected($request->id, $actor->userId, $reason, $now)) {
            throw new ValidationException([
                'request' => 'Another decision was recorded for this request at the same moment.',
            ]);
        }

        $this->audit->record(AuditEntry::byActor(
            actor: $actor,
            action: AuditAction::AccountRequestRejected,
            objectType: 'account_request',
            objectRef: (string) $request->id,
            before: ['status' => $request->status->value],
            after: ['status' => AccountRequestStatus::Rejected->value, 'reason' => $reason],
            occurredAtUtc: $now,
        ));

        return $this->requests->findById($request->id) ?? $request;
    }
}
