<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Session-bound synchroniser token.
 *
 * Every state-changing form must include csrf_field() and the handler must
 * call Csrf::validate() before touching persisted state.
 */
final class Csrf
{
    public const FIELD = '_token';
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $existing = Session::get(self::SESSION_KEY);
        if ($existing !== null && strlen($existing) === 64) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        Session::put(self::SESSION_KEY, $token);

        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }

    public static function validate(?string $submitted): bool
    {
        $stored = Session::get(self::SESSION_KEY);
        if ($stored === null || $submitted === null || $submitted === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }
}
