<?php

declare(strict_types=1);

namespace App\Domain;

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
    public const SLOT_MINUTES = ResourceSchedule::SLOT_MINUTES;

    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly AppointmentRepository $appointments,
        private readonly Transaction $transaction,
        private readonly OutboxRepository $outbox,
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

        $schedule = SchedulingInput::fromArray($input);
        $createdAtUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');

        $appointment = new Appointment(
            id: 0,
            reference: $this->reference(),
            referralId: $referral->id,
            status: AppointmentStatus::Scheduled,
            version: 1,
            startsAtUtc: $schedule->startsAtUtc(),
            endsAtUtc: $schedule->endsAtUtc(),
            durationMinutes: $schedule->durationMinutes,
            patientName: $referral->patientName,
            patientPhone: $referral->patientPhone,
            createdByUserId: $actor->userId,
            createdAtUtc: $createdAtUtc,
        );

        return $this->transaction->run(
            function () use ($appointment, $schedule, $referral, $actor, $createdAtUtc): Appointment {
                // The explicit lock protocol: every writer takes the resource row
                // first, so two schedulers booking overlapping multi-quantum
                // windows are serialised here instead of meeting inside
                // `resource_slots`, where a partial insert could deadlock. The
                // primary key of that table is still the guard; this is what makes
                // the guard reachable one writer at a time.
                $this->appointments->lockResource($schedule->resourceId);

                $booked = $this->appointments->book(
                    $appointment,
                    $schedule->resourceId,
                    $schedule->slotStarts(),
                    $actor,
                );

                $this->referrals->transition(
                    $referral,
                    ReferralStatus::ConvertedToAppointment,
                    $actor,
                    null,
                    $createdAtUtc,
                );

                // Inside the same unit of work: a booking nobody was told about
                // and a message about a booking that does not exist are both
                // worse than a failed request (WF-14). The body carries the
                // reference and the time; the patient's name is not in it.
                $this->outbox->record(null, $referral->id, [
                    new OutboundMessage(
                        channel: NotificationChannel::Sms,
                        template: NotificationTemplate::AppointmentBooked,
                        recipient: $appointment->patientPhone,
                        payload: [
                            'reference' => $booked->reference,
                            'scheduled_at_utc' => $booked->startsAtUtc,
                        ],
                        dedupeKey: 'appointment:' . $booked->reference . ':booked',
                    ),
                ]);

                return $booked;
            },
        );
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
}
