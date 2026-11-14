<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\ChangePractitionerCredentialStatus;
use App\Domain\EndPractitionerPrivilege;
use App\Domain\GrantPractitionerPrivilege;
use App\Domain\Permission;
use App\Domain\PractitionerCredentialNotFoundException;
use App\Domain\PractitionerCredentialRepository;
use App\Domain\PractitionerCredentialStatus;
use App\Domain\ProcedureRepository;
use App\Domain\RecordPractitionerCredential;
use App\Domain\User;
use App\Domain\UserRepository;
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
use PDOException;

/**
 * The credential register: who may approve which procedure, and what says so
 * (Decision 6, AC-009, LP-003).
 *
 * The administrator is deliberately not admitted. Decision 5 leaves the system
 * administrator technical authority only, and Decision 6 forbids exactly the bypass
 * an administrator account would create if it could write the register that governs
 * clinical approval. This screen is a clinical-governance act, so it is behind
 * `clinical.credential.manage`, which only the clinical role holds.
 *
 * The controller authenticates, lists and translates refusals into sentences. Every
 * rule — windows, delegation, what a suspended credential may acquire — lives in the
 * command behind it.
 */
final class PractitionerRegisterController
{
    private const BASE = '/practitioners';

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly UserRepository $users,
        private readonly PractitionerCredentialRepository $credentials,
        private readonly ProcedureRepository $procedures,
        private readonly RecordPractitionerCredential $recordCredential,
        private readonly ChangePractitionerCredentialStatus $changeStatus,
        private readonly GrantPractitionerPrivilege $grantPrivilege,
        private readonly EndPractitionerPrivilege $endPrivilege,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        // The route declares the same permission. Repeating it here is deliberate:
        // the read side of the register is the half most easily left to the router,
        // and a controller that renders a clinical register without asking is one
        // refactor away from rendering it for the wrong role.
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::BASE));
        }

        $this->gate->authorize(Permission::ClinicalCredentialManage);

        return $this->render([], 200);
    }

    public function recordCredential(Request $request): Response
    {
        $user = $this->authorise($request);

        try {
            $credential = $this->recordCredential->execute(
                new Actor($user->id, $user->role),
                (int) $request->input('practitioner_id', '0'),
                [
                    'credential_type' => (string) $request->input('credential_type', ''),
                    'registration_number' => (string) $request->input('registration_number', ''),
                    'qualifications' => (string) $request->input('qualifications', ''),
                    'evidence_reference' => (string) $request->input('evidence_reference', ''),
                    'institutional_privilege_status' => (string) $request->input('institutional_privilege_status', ''),
                    'supervision_restriction' => (string) $request->input('supervision_restriction', ''),
                    'effective_from' => (string) $request->input('effective_from', ''),
                    'expires_on' => (string) $request->input('expires_on', ''),
                ],
            );
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422);
        } catch (PDOException) {
            // Two verifiers recording the same registration number at once: the
            // unique key refuses the second, and reloading is the honest answer.
            return $this->render([
                'registration_number' => 'A credential with this type and registration number is '
                    . 'already recorded. Check the register before recording it again.',
            ], 422);
        }

        Session::flash(
            'notice',
            'Verification recorded for ' . $credential->credentialType . ' (' . $credential->registrationNumber . ').',
        );

        return Response::redirect(self::BASE, 303);
    }

    /** @param array<string, string> $params */
    public function changeStatus(Request $request, array $params): Response
    {
        $user = $this->authorise($request);

        $status = PractitionerCredentialStatus::tryFrom((string) $request->input('status', ''));

        if ($status === null) {
            return $this->render([
                'status' => 'Choose a state: verified, suspended or revoked.',
            ], 422);
        }

        try {
            $this->changeStatus->execute(
                new Actor($user->id, $user->role),
                (int) ($params['credentialId'] ?? 0),
                $status,
                (string) $request->input('note', ''),
            );
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422);
        } catch (PractitionerCredentialNotFoundException $exception) {
            throw HttpException::notFound($exception->getMessage());
        }

        Session::flash('notice', 'The credential is now ' . $status->label() . '.');

        return Response::redirect(self::BASE, 303);
    }

    /** @param array<string, string> $params */
    public function grantPrivilege(Request $request, array $params): Response
    {
        $user = $this->authorise($request);

        try {
            $privilege = $this->grantPrivilege->execute(
                new Actor($user->id, $user->role),
                (int) ($params['credentialId'] ?? 0),
                [
                    'procedure_code' => (string) $request->input('procedure_code', ''),
                    'basis' => (string) $request->input('basis', 'own'),
                    'delegated_from_user_id' => (string) $request->input('delegated_from_user_id', ''),
                    'supervision' => (string) $request->input('supervision', ''),
                    'effective_from' => (string) $request->input('effective_from', ''),
                    'effective_to' => (string) $request->input('effective_to', ''),
                    'note' => (string) $request->input('note', ''),
                ],
            );
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422);
        } catch (PractitionerCredentialNotFoundException $exception) {
            throw HttpException::notFound($exception->getMessage());
        } catch (PDOException) {
            return $this->render([
                'procedure_code' => 'A privilege for this procedure already starts on that day. '
                    . 'Check the register before granting it again.',
            ], 422);
        }

        Session::flash('notice', 'A privilege for ' . $privilege->procedureCode . ' was recorded.');

        return Response::redirect(self::BASE, 303);
    }

    /** @param array<string, string> $params */
    public function endPrivilege(Request $request, array $params): Response
    {
        $user = $this->authorise($request);

        try {
            $ended = $this->endPrivilege->execute(
                new Actor($user->id, $user->role),
                (int) ($params['privilegeId'] ?? 0),
                (string) $request->input('ended_on', ''),
            );
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422);
        } catch (PractitionerCredentialNotFoundException $exception) {
            throw HttpException::notFound($exception->getMessage());
        }

        Session::flash(
            'notice',
            'The privilege for ' . $ended->procedureCode . ' ended on ' . (string) $ended->endsOn() . '.',
        );

        return Response::redirect(self::BASE, 303);
    }

    /**
     * Authenticate, authorise, and validate the token in one place, so a new POST
     * handler cannot be added without it.
     */
    private function authorise(Request $request): User
    {
        if (!$this->auth->check()) {
            throw HttpException::forbidden('Sign in to continue.');
        }

        $this->gate->authorize(Permission::ClinicalCredentialManage);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        return $this->gate->authenticate();
    }

    /**
     * @param array<string, string> $errors
     */
    private function render(array $errors, int $status): Response
    {
        $everyone = $this->users->all();
        $names = [];

        foreach ($everyone as $account) {
            $names[$account->id] = $account->name;
        }

        $practitioners = [];

        foreach ($everyone as $account) {
            if (!$account->role->can(Permission::ClinicalApprove)) {
                continue;
            }

            $credentials = [];

            foreach ($this->credentials->forUser($account->id) as $credential) {
                $privileges = [];

                foreach ($credential->privileges as $privilege) {
                    $privileges[] = [
                        'privilege' => $privilege,
                        'delegatedFrom' => $privilege->delegatedFromUserId === null
                            ? null
                            : ($names[$privilege->delegatedFromUserId] ?? 'Unknown account'),
                    ];
                }

                $credentials[] = [
                    'credential' => $credential,
                    'verifiedBy' => $names[$credential->verifiedByUserId] ?? 'Unknown account',
                    'verifiedAt' => $this->localTime($credential->verifiedAtUtc),
                    'privileges' => $privileges,
                ];
            }

            $practitioners[] = [
                'user' => $account,
                'credentials' => $credentials,
            ];
        }

        return Response::html($this->view->render('practitioners/index', [
            'title' => 'Practitioner credentials',
            'practitioners' => $practitioners,
            'procedures' => $this->procedures->elective(),
            'accounts' => $everyone,
            'errors' => $errors,
            'notice' => Session::pullFlash('notice'),
            'currentUser' => $this->gate->authenticate(),
            'today' => (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('Y-m-d'),
        ]), $status);
    }

    private function localTime(string $utc): string
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($at === false) {
            return $utc;
        }

        return $at->setTimezone(new DateTimeZone($this->timezone))->format('j M Y, H:i');
    }
}
