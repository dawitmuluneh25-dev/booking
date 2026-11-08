<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turn an approved referral into a booked appointment.
 *
 * This is the operation the plan calls both `BookAppointment` and
 * `ConvertToAppointment`; they are one command because they are one transaction.
 * Splitting them would create a state in which the referral says "converted" and
 * no appointment exists, which is exactly the inconsistency the separation of
 * states is meant to prevent (WF-15).
 *
 * What it refuses, and why each refusal exists:
 *
 * - no standing clinical approval (WF-02, API 12.3). The status column alone is
 *   not enough: a referral can read "approved for scheduling" while its decision
 *   has been deferred or superseded, and trusting the column would book that
 *   patient anyway;
 * - a quantum already occupied. The refusal comes from `resource_slots`, not from
 *   a prior check, so two schedulers cannot both be told the slot is free;
 * - anything that is not a whole number of quanta, because a partial slot cannot
 *   be represented by the occupancy table.
 */
final class BookAppointment
{
    /** Booking granularity. Changing this is a data migration, not a tweak. */
    public const SLOT_MINUTES = 15;

    private const MIN_MINUTES = 15;

    private const MAX_MINUTES = 480;

    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly AppointmentRepository $appointments,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * @param array<string, mixed> $input resource_id, starts_at (UTC), duration_minutes
     *
     * @throws AuthorisationException      the actor may not schedule
     * @throws ReferralNotFoundException   the referral does not exist
     * @throws ValidationException         the request is incomplete or malformed
     * @throws ReferralTransitionException the referral is not ready, or its approval
     *                                     is missing or superseded
     * @throws SlotUnavailableException    the resource is already booked
     */
    public function execute(Actor $actor, int $referralId, array $input, ?string $nowUtc = null): Appointment
    {
        if (!$actor->can(Permission::ScheduleManage)) {
            throw new AuthorisationException($actor->role, Permission::ScheduleManage);
        }

        $referral = $this->referrals->findById($referralId);

        if ($referral === null) {
            throw new ReferralNotFoundException('#' . $referralId);
        }

        if ($referral->status !== ReferralStatus::ApprovedForScheduling) {
            throw new ReferralTransitionException($referral->status, ReferralStatus::ConvertedToAppointment);
        }

        $decision = $this->referrals->currentDecision($referral->id);

        if ($decision === null || $decision->type !== ClinicalDecisionType::Approved) {
            throw new ReferralTransitionException(
                $referral->status,
                ReferralStatus::ConvertedToAppointment,
                'Booking needs a standing clinical approval; this referral has none.',
            );
        }

        $resourceId = $this->integer($input, 'resource_id');
        $duration = $this->integer($input, 'duration_minutes');
        $startsAt = $this->timestamp($this->text($input, 'starts_at'));

        $errors = [];

        if ($resourceId === null || $resourceId < 1) {
            $errors['resource_id'] = 'Choose the room or suite to book.';
        }

        if ($duration === null || $duration < self::MIN_MINUTES || $duration > self::MAX_MINUTES) {
            $errors['duration_minutes'] = sprintf(
                'Duration must be between %d and %d minutes.',
                self::MIN_MINUTES,
                self::MAX_MINUTES,
            );
        } elseif ($duration % self::SLOT_MINUTES !== 0) {
            $errors['duration_minutes'] = sprintf(
                'Duration must be a multiple of %d minutes.',
                self::SLOT_MINUTES,
            );
        }

        if ($startsAt === null) {
            $errors['starts_at'] = 'Give the start time as YYYY-MM-DD HH:MM in UTC.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Both are guaranteed non-null by the checks above; the assertions keep the
        // analyser honest without a second set of null checks below.
        \assert($resourceId !== null && $duration !== null && $startsAt !== null);

        $endsAt = $startsAt->modify('+' . $duration . ' minutes');

        if ($endsAt === false) {
            throw new ValidationException(['duration_minutes' => 'That duration is not a real time span.']);
        }

        $createdAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');

        $appointment = new Appointment(
            id: 0,
            reference: $this->reference(),
            referralId: $referral->id,
            status: AppointmentStatus::Scheduled,
            version: 1,
            startsAtUtc: $startsAt->format('Y-m-d H:i:s'),
            endsAtUtc: $endsAt->format('Y-m-d H:i:s'),
            durationMinutes: $duration,
            patientName: $referral->patientName,
            patientPhone: $referral->patientPhone,
            createdByUserId: $actor->userId,
            createdAtUtc: $createdAtUtc,
        );

        return $this->transaction->run(
            function () use (
                $appointment,
                $resourceId,
                $startsAt,
                $duration,
                $referral,
                $actor,
                $createdAtUtc,
            ): Appointment {
                $booked = $this->appointments->book(
                    $appointment,
                    $resourceId,
                    $this->slotStarts($startsAt, $duration),
                    $actor,
                );

                $this->referrals->transition(
                    $referral,
                    ReferralStatus::ConvertedToAppointment,
                    $actor,
                    null,
                    $createdAtUtc,
                );

                return $booked;
            },
        );
    }

    /**
     * @return list<string>
     */
    private function slotStarts(DateTimeImmutable $startsAt, int $duration): array
    {
        $slots = [];

        for ($offset = 0; $offset < $duration; $offset += self::SLOT_MINUTES) {
            $slot = $startsAt->modify('+' . $offset . ' minutes');

            if ($slot === false) {
                continue;
            }

            $slots[] = $slot->format('Y-m-d H:i:s');
        }

        return $slots;
    }

    private function reference(): string
    {
        $suffix = '';
        $max = strlen(self::REFERENCE_ALPHABET) - 1;

        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::REFERENCE_ALPHABET[random_int(0, $max)];
        }

        return 'BK-' . gmdate('ymd') . '-' . $suffix;
    }

    private function timestamp(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $parsed === false || $parsed->format('Y-m-d H:i:s') !== $value ? null : $parsed;
    }

    /** @param array<string, mixed> $input */
    private function integer(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^[0-9]{1,6}$/', trim($value)) === 1
            ? (int) trim($value)
            : null;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
