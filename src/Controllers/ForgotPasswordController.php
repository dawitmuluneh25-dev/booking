<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\RequestPasswordReset;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Session;
use App\Support\View;

/**
 * The public "I cannot sign in" form.
 *
 * It asks for an address and says the same thing whatever it is told. There is no
 * branch that admits an address is unknown, because that branch is a way to find
 * out who has an account here without guessing a password.
 *
 * A malformed address is rejected on the form, which reveals nothing: the format of
 * an address is visible to whoever typed it.
 */
final class ForgotPasswordController
{
    public function __construct(
        private readonly View $view,
        private readonly RequestPasswordReset $request,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->form([], 200);
    }

    public function store(Request $request): Response
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $email = trim((string) $request->input('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->form(
                ['email' => 'Enter the email address you sign in with.'],
                422,
            );
        }

        $this->request->execute($email);

        Session::flash(
            'notice',
            'If that address has an account here, a reset link is on its way. '
                . 'It can be used once and expires in an hour.',
        );

        return Response::redirect('/login', 303);
    }

    /** @param array<string, string> $errors */
    private function form(array $errors, int $status): Response
    {
        return Response::html($this->view->render('auth/forgot', [
            'title' => 'Reset your password',
            'errors' => $errors,
            'currentUser' => null,
        ]), $status);
    }
}
