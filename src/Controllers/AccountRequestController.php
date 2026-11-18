<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AccountRequestThrottle;
use App\Domain\AccountRequestThrottledException;
use App\Domain\HumanCheck;
use App\Domain\RequestAccount;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Session;
use App\Support\View;

/**
 * The public "I would like a referring account" form (ADR-013).
 *
 * The page is reachable without a session because the person who needs it has no
 * account — that is the premise. What it must never do is answer differently for an
 * address that already has an account, so there is exactly one success response and
 * it carries a reference number rather than a promise.
 *
 * Nothing is emailed on submission: the requester is told on screen, and no
 * anonymous caller can cause mail to be sent to a third party.
 */
final class AccountRequestController
{
    public function __construct(
        private readonly View $view,
        private readonly RequestAccount $request,
        private readonly string $throttleKey,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->form([], [], 200);
    }

    public function store(Request $request): Response
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $input = [
            'full_name' => (string) $request->input('full_name', ''),
            'email' => (string) $request->input('email', ''),
            'practice_name' => (string) $request->input('practice_name', ''),
            'practice_city' => (string) $request->input('practice_city', ''),
            'registration_no' => (string) $request->input('registration_no', ''),
            'note' => (string) $request->input('note', ''),
            'website' => (string) $request->input('website', ''),
            'check_token' => (string) $request->input('check_token', ''),
            'check_answer' => (string) $request->input('check_answer', ''),
        ];

        $ipHash = AccountRequestThrottle::hashAddress($request->ip(), $this->throttleKey);

        try {
            $reference = $this->request->execute($input, $ipHash);
        } catch (ValidationException $exception) {
            return $this->form($exception->errors, $input, 422);
        } catch (AccountRequestThrottledException $exception) {
            return Response::html($this->view->render('account-request/throttled', [
                'title' => 'Too many requests',
                'retryAfterMinutes' => $exception->retryAfterMinutes,
                'currentUser' => null,
            ]), 429);
        }

        // One response, whatever happened: stored, collapsed as a duplicate, or
        // discarded because the honeypot was filled.
        return Response::html($this->view->render('account-request/received', [
            'title' => 'Request received',
            'reference' => $reference,
            'currentUser' => null,
        ]), 200);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $previous
     */
    private function form(array $errors, array $previous, int $status): Response
    {
        $challenge = HumanCheck::issue();

        // A wrong answer is a form error like any other, and the question is reissued
        // rather than repeated — the one that was just answered is spent.
        unset($errors['check_answer_consumed']);

        return Response::html($this->view->render('account-request/new', [
            'title' => 'Request a referring account',
            'errors' => $errors,
            'previous' => $previous,
            'challenge' => $challenge,
            'currentUser' => null,
            'notice' => Session::pullFlash('notice'),
        ]), $status);
    }
}
