<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record who holds an operational appointment, and who deputises (D-05).
 *
 * Three rules, each of which exists because of a specific failure this project has already met:
 *
 *   1. **The role key must be one the enum declares.** An appointment invented by a typo would sit in
 *      the table for ever, count toward nothing, and never appear on the screen that lists the five.
 *   2. **A holder cannot deputise for themselves.** The deputy exists to be called when the holder is
 *      unreachable; the same name in both columns is a rota with no cover that looks covered.
 *   3. **Clearing is a first-class act.** An empty field means the appointment is vacant, which the
 *      coverage gate counts as unmet. It is stored as NULL rather than an empty string so that
 *      "vacant" is one value, not several ways of writing nothing.
 *
 * The state change and its audit row are written inside one transaction: an appointment that changed
 * with no record of who changed it is exactly the artefact that makes a rota untrustworthy.
 */
final class SaveOperationalAppointment
{
    public function __construct(
        private readonly OperationalAppointmentRepository $appointments,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input `role`, `holder`, `deputy`
     *
     * @throws AuthorisationException
     * @throws ValidationException
     */
    public function execute(Actor $actor, array $input, ?string $nowUtc = null): OperationalAppointment
    {
        if (!$actor->can(Permission::ClinicConfigure)) {
            throw new AuthorisationException($actor->role, Permission::ClinicConfigure);
        }

        $roleKey = $this->text($input, 'role');
        $appointment = OperationalAppointment::tryFrom($roleKey);

        if ($appointment === null) {
            throw new ValidationException([
                'role' => 'That is not one of the five operational appointments.',
            ]);
        }

        $holder = $this->nullableText($input, 'holder');
        $deputy = $this->nullableText($input, 'deputy');

        $errors = $this->validator->validate(
            ['holder' => $holder ?? '', 'deputy' => $deputy ?? ''],
            // `nullable` because vacant is legal: the rule is about length when a name is present.
            ['holder' => 'nullable|min:2|max:120', 'deputy' => 'nullable|min:2|max:120'],
        );

        if ($holder !== null && $deputy !== null && $holder === $deputy) {
            $errors['deputy'] = 'The deputy has to be somebody other than the holder, or nobody is covered.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');
        $before = $this->appointments->all()[$appointment->value] ?? ['holder' => null, 'deputy' => null];

        $this->transaction->run(function () use ($appointment, $holder, $deputy, $actor, $now, $before): void {
            $this->appointments->save($appointment->value, $holder, $deputy, $actor, $now);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::OperationalAppointmentChanged,
                objectType: 'operational_appointment',
                objectRef: $appointment->value,
                before: ['holder' => $before['holder'], 'deputy' => $before['deputy']],
                after: ['holder' => $holder, 'deputy' => $deputy],
                occurredAtUtc: $now,
            ));
        });

        return $appointment;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $input */
    private function nullableText(array $input, string $key): ?string
    {
        $value = $this->text($input, $key);

        return $value === '' ? null : $value;
    }
}
