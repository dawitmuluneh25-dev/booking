<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\Permission;
use App\Domain\Procedure;
use App\Domain\ProcedureRepository;
use App\Domain\ReferralRepository;
use App\Domain\SubmitElectiveReferral;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use PDOException;

/**
 * The referral front door: the form a referrer fills in, and the queue the desk
 * works from.
 *
 * Nothing here decides a status. The form posts fields, `SubmitElectiveReferral`
 * validates and records the referral, and the redirect goes to the referral that
 * was actually stored — not to a success page that could be shown for a
 * submission that failed.
 */
final class ReferralController
{
    /** The fields the command accepts, in the order the form presents them. */
    private const FIELDS = [
        'patient_name',
        'patient_phone',
        'patient_dob',
        'guardian_name',
        'guardian_phone',
        'guardian_relationship',
        'procedure_code',
        'requested_urgency',
        'clinical_summary',
        'target_site',
        'referring_physician_name',
        'referring_facility',
        'referring_physician_phone',
    ];

    private const URGENCIES = ['routine', 'priority'];

    public function __construct(
        private readonly View $view,
        private readonly Gate $gate,
        private readonly SubmitElectiveReferral $submit,
        private readonly ReferralRepository $referrals,
        private readonly ProcedureRepository $procedures,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralTriage);

        return Response::html($this->view->render('referrals/index', [
            'title' => 'Referral queue',
            'referrals' => $this->referrals->queued(100),
            'notice' => Session::pullFlash('notice'),
            'currentUser' => $this->gate->authenticate(),
        ]));
    }

    public function create(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralSubmit);

        return $this->form([], [], 200);
    }

    public function store(Request $request): Response
    {
        $this->gate->authorize(Permission::ReferralSubmit);
        $this->assertCsrf($request);

        $user = $this->gate->authenticate();
        $input = $this->input($request);

        try {
            $referral = $this->submit->execute(
                new Actor($user->id, $user->role),
                $input,
                null,
                $this->idempotencyKey($request),
            );
        } catch (ValidationException $exception) {
            return $this->form($input, $exception->errors, 422);
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not submit a referral.');
        } catch (PDOException) {
            // The procedure foreign key is the authority: if it refuses a code the
            // form offered, the catalogue changed between render and submit.
            return $this->form($input, [
                'procedure_code' => 'That procedure is no longer offered. Choose another.',
            ], 422);
        }

        Session::flash('notice', 'Referral ' . $referral->reference . ' submitted.');

        return Response::redirect('/referrals/' . rawurlencode($referral->reference));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ReferralTriage);

        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw new HttpException(404);
        }

        return Response::html($this->view->render('referrals/show', [
            'title' => 'Referral ' . $referral->reference,
            'referral' => $referral,
            'procedure' => $this->procedures->find($referral->procedureCode),
            'notice' => Session::pullFlash('notice'),
            'currentUser' => $this->gate->authenticate(),
        ]));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(array $values, array $errors, int $status): Response
    {
        /** @var list<Procedure> $catalogue */
        $catalogue = $this->procedures->elective();

        return Response::html($this->view->render('referrals/new', [
            'title' => 'New referral',
            'values' => $values,
            'errors' => $errors,
            'procedures' => $catalogue,
            'urgencies' => self::URGENCIES,
            'idempotencyKey' => bin2hex(random_bytes(16)),
            'currentUser' => $this->gate->authenticate(),
        ]), $status);
    }

    /** @return array<string, string> */
    private function input(Request $request): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $request->input($field, '');

            $values[$field] = is_string($value) ? trim($value) : '';
        }

        return $values;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->input('idempotency_key', '');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }
    }
}
