<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\InvalidCredentialsException;
use App\Security\Session;
use App\Security\TooManyAttemptsException;
use App\Support\View;

final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/bookings');
        }

        return $this->loginForm($request, null, null, 200);
    }

    public function login(Request $request): Response
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $email = (string) $request->input('email', '');
        $password = (string) $request->raw('password');

        try {
            $this->auth->attempt($email, $password, $request->ip());
        } catch (TooManyAttemptsException $exception) {
            return $this->loginForm($request, $exception->getMessage(), $email, 429);
        } catch (InvalidCredentialsException) {
            return $this->loginForm($request, 'Email or password is incorrect.', $email, 422);
        }

        return Response::redirect($this->safeNext($request));
    }

    public function logout(Request $request): Response
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $this->auth->logout();
        Session::flash('notice', 'Signed out.');

        // The sign-in page owns the notice: redirecting to `/` left the flash
        // in the session, and the next page that rendered a notice claimed the
        // user had just signed out after they had signed back in.
        return Response::redirect('/login');
    }

    private function loginForm(Request $request, ?string $error, ?string $email, int $status): Response
    {
        return Response::html($this->view->render('auth/login', [
            'title' => 'Staff sign in',
            'error' => $error,
            'notice' => Session::pullFlash('notice'),
            'email' => $email ?? '',
            'next' => $this->safeNext($request),
            'currentUser' => null,
        ]), $status);
    }

    /**
     * Only local absolute paths are accepted, so `next` cannot become an open
     * redirect.
     */
    private function safeNext(Request $request): string
    {
        $next = $request->input('next', '/bookings') ?? '/bookings';

        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return '/bookings';
        }

        return $next;
    }
}
