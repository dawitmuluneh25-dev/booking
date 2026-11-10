<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionType;
use App\Domain\Permission;
use App\Domain\ProcedureRepository;
use App\Domain\RecordClinicalDecision;
use App\Domain\Referral;
use App\Domain\ReferralNotFoundException;
use App\Domain\ReferralRepository;
use App\Domain\ReferralTransitionException;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The radiologist's queue and the decision form.
 *
 * Viewing and deciding are separate permissions on purpose (WF-03): a coordinator
 * may need to see what is waiting, but only a holder of `clinical.approve` may
 * decide. The controller enforces the stronger one on the POST and the weaker one
 * on the two GETs, so the queue cannot be used to slip a decision through.
 */
final class ReviewController
{
    public function __construct(
        private readonly View $view,
        private readonly Gate $gate,
        private readonly RecordClinicalDecision $decision,
        private readonly ReferralRepository $referrals,
        private readonly ProcedureRepository $procedures,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->authorize(Permission::ClinicalReview);

        return Response::html($this->view->render('review/index', [
            'title' => 'Clinical review',
            'referrals' => $this->referrals->awaitingClinicalReview(100),
            'labels' => $this->labels(),
            'notice' => Session::pullFlash('notice'),
            'currentUser' => $this->gate->authenticate(),
        ]));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ClinicalReview);

        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw new HttpException(404);
        }

        if (!$referral->status->isClinicalReviewState()) {
            // Opening a decided referral here would invite a second decision on a
            // record that has already moved on.
            throw new HttpException(404);
        }

        return $this->form($referral, [], 200);
    }

    /** @param array<string, string> $params */
    public function decide(Request $request, array $params): Response
    {
        $this->gate->authorize(Permission::ClinicalApprove);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $user = $this->gate->authenticate();
        $referral = $this->referrals->findByReference((string) ($params['reference'] ?? ''));

        if ($referral === null) {
            throw new HttpException(404);
        }

        $input = [
            'decision_type' => (string) $request->input('decision_type', ''),
            'rationale' => (string) $request->input('rationale', ''),
            'approved_technique' => (string) $request->input('approved_technique', ''),
            'modality' => (string) $request->input('modality', ''),
            'sedation' => (string) $request->input('sedation', ''),
            'estimated_duration_minutes' => (string) $request->input('estimated_duration_minutes', ''),
            'preprocedure_conditions' => (string) $request->input('preprocedure_conditions', ''),
            'redirect_destination' => (string) $request->input('redirect_destination', ''),
            'information_requested' => (string) $request->input('information_requested', ''),
        ];

        try {
            $decided = $this->decision->execute(new Actor($user->id, $user->role), $referral->id, $input);
        } catch (ValidationException $exception) {
            return $this->form($referral, $exception->errors, 422);
        } catch (AuthorisationException) {
            throw HttpException::forbidden('You are not allowed to record a clinical decision.');
        } catch (ReferralTransitionException) {
            throw new HttpException(404, 'This referral is no longer awaiting review.');
        } catch (ReferralNotFoundException) {
            throw new HttpException(404);
        }

        Session::flash('notice', 'Decision recorded for ' . $decided->reference . '.');

        return Response::redirect('/review');
    }

    /** @return array<string, string> code => label, for the queue table */
    private function labels(): array
    {
        $labels = [];

        foreach ($this->procedures->elective() as $procedure) {
            $labels[$procedure->code] = $procedure->label;
        }

        return $labels;
    }

    /** @param array<string, string> $errors */
    private function form(Referral $referral, array $errors, int $status): Response
    {
        return Response::html($this->view->render('review/show', [
            'title' => 'Review ' . $referral->reference,
            'referral' => $referral,
            'procedure' => $this->procedures->find($referral->procedureCode),
            'decisions' => ClinicalDecisionType::cases(),
            'history' => $this->history($referral->id),
            'errors' => $errors,
            'currentUser' => $this->gate->authenticate(),
        ]), $status);
    }

    /**
     * The decision history, with the two facts the reader needs: who decided, in
     * the clinic's own time, and which rows a later decision replaced.
     *
     * The times are converted here rather than in the template because UTC in a
     * clinical record is a reading error waiting to happen: a clinician in Addis
     * reading "14:10 UTC" for a decision taken at 17:10 local has no way to tell
     * which clock the page means.
     *
     * @return list<array{decision: ClinicalDecision, reviewer: string, decidedAt: string, superseded: bool}>
     */
    private function history(int $referralId): array
    {
        $rows = [];

        foreach ($this->referrals->decisionHistory($referralId) as $record) {
            $rows[] = [
                'decision' => $record->decision,
                'reviewer' => $record->reviewerName === '' ? 'Unknown reviewer' : $record->reviewerName,
                'decidedAt' => $this->localTime($record->decision->decidedAtUtc),
                'superseded' => !$record->isCurrent(),
            ];
        }

        return $rows;
    }

    private function localTime(string $utc): string
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($at === false) {
            return $utc;
        }

        return $at->setTimezone(new DateTimeZone($this->timezone))->format('D j M Y, H:i')
            . ' (' . $this->timezone . ')';
    }
}
