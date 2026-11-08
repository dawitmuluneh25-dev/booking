<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\InvalidResetTokenException;
use App\Security\Session;
use App\Support\View;

final class PasswordResetController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $token = $params['token'] ?? '';
        if (!$this->auth->resetTokenIsValid($token)) {
            throw new HttpException(410, 'That reset link is invalid or has expired.');
        }

        return $this->form($token, [], 200);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $token = $params['token'] ?? '';

        try {
            $this->auth->resetPassword(
                $token,
                (string) $request->raw('password'),
                (string) $request->raw('password_confirmation'),
            );
        } catch (ValidationException $exception) {
            return $this->form($token, $exception->errors, 422);
        } catch (InvalidResetTokenException $exception) {
            throw new HttpException(410, $exception->getMessage());
        }

        Session::flash('notice', 'Password updated. Sign in with the new password.');

        return Response::redirect('/login');
    }

    /** @param array<string, string> $errors */
    private function form(string $token, array $errors, int $status): Response
    {
        return Response::html($this->view->render('auth/reset', [
            'title' => 'Set a new password',
            'token' => $token,
            'errors' => $errors,
            'currentUser' => null,
        ]), $status);
    }
}
