<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record one contact arrangement (Decision 4).
 *
 * Recording is not verifying. A row created here has no verifier and no date, so it
 * counts toward nothing until somebody confirms that the number is answered — the
 * two facts are kept apart because they are two acts, and only one of them is
 * evidence.
 *
 * An after-hours route that is not staffed is refused at the point of recording,
 * not merely at verification: there is no reason to hold a number in the roster that
 * the decision does not permit anyone to use. "Where coverage genuinely exists"
 * means a route that is not covered does not exist in this table.
 */
final class RecordEscalationRoute
{
    public function __construct(
        private readonly EscalationRouteRepository $routes,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AuthorisationException
     * @throws ValidationException
     */
    public function execute(Actor $actor, array $input, ?string $nowUtc = null): EscalationRoute
    {
        if (!$actor->can(Permission::ClinicConfigure)) {
            throw new AuthorisationException($actor->role, Permission::ClinicConfigure);
        }

        $kind = EscalationRouteKind::tryFrom($this->text($input, 'kind'));

        $data = [
            'label' => $this->text($input, 'label'),
            'contact_name' => $this->text($input, 'contact_name'),
            'contact_phone' => $this->text($input, 'contact_phone'),
            'verified_note' => '',
        ];

        $errors = $this->validator->validate($data, [
            'label' => 'required|min:2|max:120',
            'contact_name' => 'required|min:2|max:120',
            'contact_phone' => 'required|phone|max:32',
        ]);

        if ($kind === null) {
            $errors['kind'] = 'Choose which part of the escalation ladder this route is: '
                . implode(', ', array_map(
                    static fn (EscalationRouteKind $case): string => $case->label(),
                    EscalationRouteKind::cases(),
                )) . '.';
        }

        $isStaffed = $this->text($input, 'is_staffed') !== '0';

        if ($kind !== null && $kind->requiresStaffing() && !$isStaffed) {
            $errors['is_staffed'] = 'An after-hours route that is not staffed cannot be recorded. '
                . 'Decision 4 allows after-hours escalation only where coverage genuinely exists; a '
                . 'number that rings an empty office is worse than no number, because the caller '
                . 'believes the case has been handed on.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');

        /** @var EscalationRouteKind $kind */
        $route = new EscalationRoute(
            id: 0,
            kind: $kind,
            label: $data['label'],
            contactName: $data['contact_name'],
            contactPhone: $data['contact_phone'],
            isStaffed: $isStaffed,
            isActive: true,
            verifiedByUserId: null,
            verifiedAtUtc: null,
            verificationNote: null,
            createdAtUtc: $when,
        );

        return $this->transaction->run(function () use ($actor, $route, $when): EscalationRoute {
            $stored = $this->routes->create($route);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::EscalationRouteRecorded,
                objectType: 'escalation_route',
                objectRef: (string) $stored->id,
                after: [
                    'kind' => $stored->kind->value,
                    'label' => $stored->label,
                    'is_staffed' => $stored->isStaffed,
                    'verified' => false,
                ],
                occurredAtUtc: $when,
            ));

            return $stored;
        });
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
