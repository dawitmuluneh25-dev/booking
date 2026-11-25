<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\OperationalAppointment;
use App\Domain\OperationalAppointmentRepository;
use App\Domain\Permission;
use App\Domain\SaveOperationalAppointment;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The five operational appointments, and who holds them (D-05, D-08).
 *
 * This screen is the "operational values live in the database" half of the hybrid D-08 approved. It is
 * deliberately small: five titles, five holders, five deputies. What makes it worth a page rather than a
 * migration is the two things around the fields — the responsibility text stays beside the name so
 * nobody appoints somebody without knowing what the appointment owns, and the last change is shown
 * against each row so "who set this and when" is answered without opening the audit trail.
 *
 * A submission that changes nothing writes nothing. The screen shows five rows and a single save button,
 * so a naive implementation would write five audit entries every time somebody corrected one name,
 * and an audit trail that records four non-events per change is a trail nobody reads.
 */
final class AdminOperationsController
{
    private const BASE = '/admin/operations';

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly OperationalAppointmentRepository $appointments,
        private readonly SaveOperationalAppointment $save,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::BASE));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        return $this->render([]);
    }

    public function update(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::BASE));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        // `authenticate()` rather than `user()`: it returns the account or throws, so the actor built
        // from it cannot carry a null role into the audit row.
        $user = $this->gate->authenticate();
        $actor = new Actor($user->id, $user->role);

        $payload = $request->all();
        $holders = $this->map($payload['holder'] ?? null);
        $deputies = $this->map($payload['deputy'] ?? null);
        $current = $this->appointments->all();

        $errors = [];
        $changes = 0;

        foreach (OperationalAppointment::cases() as $appointment) {
            $key = $appointment->value;
            $holder = $this->name($holders[$key] ?? null);
            $deputy = $this->name($deputies[$key] ?? null);

            $existing = $current[$key] ?? ['holder' => null, 'deputy' => null];

            if ($holder === ($existing['holder'] ?? null) && $deputy === ($existing['deputy'] ?? null)) {
                continue;
            }

            try {
                $this->save->execute($actor, [
                    'role' => $key,
                    'holder' => $holder ?? '',
                    'deputy' => $deputy ?? '',
                ]);
                $changes++;
            } catch (ValidationException $exception) {
                foreach ($exception->errors as $message) {
                    $errors[$key] = $appointment->title() . ': ' . $message;
                }
            }
        }

        if ($errors !== []) {
            return $this->render($errors, 422);
        }

        Session::flash(
            'notice',
            $changes === 0
                ? 'No change: every appointment already reads that way.'
                : $changes . ' appointment(s) updated, with the change recorded against your account.',
        );

        return Response::redirect(self::BASE);
    }

    /** @param array<string, string> $errors */
    private function render(array $errors, int $status = 200): Response
    {
        $stored = $this->appointments->all();
        $rows = [];

        foreach (OperationalAppointment::cases() as $appointment) {
            $record = $stored[$appointment->value] ?? [
                'holder' => null,
                'deputy' => null,
                'updatedBy' => null,
                'updatedAt' => null,
            ];

            $rows[] = [
                'key' => $appointment->value,
                'title' => $appointment->title(),
                'responsibility' => $appointment->responsibility(),
                'holder' => $record['holder'] ?? null,
                'deputy' => $record['deputy'] ?? null,
                'updatedBy' => $record['updatedBy'] ?? null,
                'updatedAt' => $this->localTime($record['updatedAt'] ?? null),
            ];
        }

        $vacant = count(array_filter($rows, static fn (array $row): bool => $row['holder'] === null));

        return Response::html($this->view->render('admin/operations', [
            'title' => 'Operations',
            'rows' => $rows,
            'vacant' => $vacant,
            // The view decides whether to render inputs or plain text from this, and the controller
            // decides it from the permission — not from a list of role names in a template.
            'canEdit' => $this->gate->allows(Permission::ClinicConfigure),
            'errors' => $errors,
            'currentUser' => $this->auth->user(),
            'notice' => Session::pullFlash('notice'),
        ]), $status);
    }

    /**
     * The form posts `holder[role_key]`, so PHP hands us a nested array. Anything else — a crafted body
     * sending a string — is ignored rather than coerced.
     *
     * @return array<string, mixed>
     */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function name(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function localTime(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        try {
            $instant = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

            return $instant->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');
        } catch (\Exception) {
            return null;
        }
    }
}
