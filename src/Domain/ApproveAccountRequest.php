<?php

declare(strict_types=1);

namespace App\Domain;

use Throwable;

/**
 * Turn a request into an account — two acts, two roles (owner answer Q1, 18 Nov 2026).
 *
 * This command performs the **account** half: it creates the account and a
 * `pending` referring profile. It deliberately grants no referral authority.
 * Authority is the second act, performed by the clinical approver through
 * `VerifyPhysician`, so that "the administrator created an account" can never be
 * read as "the clinic accepts referrals from this person". The distinction is the
 * whole reason the profile is separate from the user.
 *
 * Reusing `CreateUserAccount` rather than inserting a second way into `users` keeps
 * one path by which a `Role` enters the system: the role is a literal here, never a
 * posted value, so a tampered form cannot produce an administrator.
 */
final class ApproveAccountRequest
{
    public function __construct(
        private readonly AccountRequestRepository $requests,
        private readonly CreateUserAccount $createAccount,
        private readonly PhysicianProfileRepository $profiles,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @return array{request: AccountRequest, user: User, temporary_password: string, profile: PhysicianProfile}
     *
     * @throws AuthorisationException    the actor may not create accounts
     * @throws AccountRequestNotFoundException no such request
     * @throws ValidationException       the request was already decided, or the address is taken
     */
    public function execute(Actor $actor, int $requestId, string $reason = '', ?string $nowUtc = null): array
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

        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        // One unit of work: the account, its pending profile and the request's new
        // state are all or nothing. A half-approval — an account nobody can trace to a
        // request, or a request marked approved with no account — is worse than a
        // refusal, because it looks finished.
        /** @var array{array{user: User, temporary_password: string}, PhysicianProfile} $result */
        $result = $this->transaction->run(function () use ($actor, $request, $now, $reason): array {
            $created = $this->createAccount->execute($actor, [
                'email' => $request->email,
                'name' => $request->fullName,
                'role' => Role::Physician->value,
            ]);

            $profile = $this->profiles->create(new PhysicianProfile(
                id: 0,
                userId: $created['user']->id,
                practiceNumber: $request->provisionalPracticeNumber(),
                institution: $request->practiceName,
                specialty: null,
                status: PhysicianStatus::Pending,
                verifiedByUserId: null,
                verifiedAtUtc: null,
                reviewNote: 'Created from account request ' . $request->reference
                    . '. Referral authority requires credential verification.',
                createdAtUtc: $now,
            ), $now);

            $marked = $this->requests->markApproved(
                $request->id,
                $actor->userId,
                $created['user']->id,
                $now,
                $reason === '' ? null : $reason,
            );

            if (!$marked) {
                // Somebody decided this request between the read above and this write.
                // Throwing rolls the account back, so no orphan account survives.
                throw new ValidationException([
                    'request' => 'Another decision was recorded for this request at the same moment. '
                        . 'Nothing was created; reload the queue.',
                ]);
            }

            return [$created, $profile];
        });

        [$created, $profile] = $result;

        $decided = $this->requests->findById($request->id) ?? $request;

        // The reason is free text an administrator typed; the note a stranger typed is
        // deliberately absent from the trail.
        $this->audit->record(AuditEntry::byActor(
            actor: $actor,
            action: AuditAction::AccountRequestApproved,
            objectType: 'account_request',
            objectRef: (string) $request->id,
            before: ['status' => $request->status->value],
            after: [
                'status' => AccountRequestStatus::Approved->value,
                'request_reference' => $request->reference,
                'user_id' => $created['user']->id,
                'referral_authority' => 'not granted; awaiting credential verification',
            ],
            occurredAtUtc: $now,
        ));

        return [
            'request' => $decided,
            'user' => $created['user'],
            'temporary_password' => $created['temporary_password'],
            'profile' => $profile,
        ];
    }
}
