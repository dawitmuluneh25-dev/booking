<?php

declare(strict_types=1);

namespace App\Domain;

use App\Security\Session;

/**
 * The third anti-abuse layer: a question a script has to read and answer.
 *
 * Deliberately self-hosted and text-based. A hosted CAPTCHA would add a third-party
 * processor to a form that already collects personal data before any relationship
 * exists, and would put that processor in the privacy notice without anybody having
 * decided to invite it. A spoken-aloud arithmetic question also survives a screen
 * reader, which an image grid does not.
 *
 * What this is not: proof of humanity. It raises the cost of a naive form filler and
 * is the weakest of the three controls; the honeypot catches drive-by bots and the
 * per-network cap bounds the damage either way.
 *
 * One outstanding question per session. Issuing a new one discards the previous,
 * which is what makes reloading the form safe.
 */
final class HumanCheck
{
    private const TOKEN_KEY = '_account_request_check_token';

    private const ANSWER_KEY = '_account_request_check_answer';

    private const TTL_SECONDS = 900;

    /** @return array{token: string, question: string} */
    public static function issue(): array
    {
        $left = random_int(2, 9);
        $right = random_int(2, 9);
        $token = bin2hex(random_bytes(16));

        Session::put(self::TOKEN_KEY, $token);
        Session::put(
            self::ANSWER_KEY,
            $left . '|' . $right . '|' . (string) (time() + self::TTL_SECONDS),
        );

        return ['token' => $token, 'question' => sprintf('What is %d + %d?', $left, $right)];
    }

    /**
     * Single use: the stored question is discarded whatever the answer, so a wrong
     * answer cannot be retried against the same challenge.
     */
    public static function verify(string $token, string $answer): bool
    {
        $storedToken = Session::get(self::TOKEN_KEY);
        $storedAnswer = Session::get(self::ANSWER_KEY);

        Session::forget(self::TOKEN_KEY);
        Session::forget(self::ANSWER_KEY);

        if ($storedToken === null || $storedAnswer === null || $token === '') {
            return false;
        }

        if (!hash_equals($storedToken, $token)) {
            return false;
        }

        $parts = explode('|', $storedAnswer);
        if (count($parts) !== 3) {
            return false;
        }

        [$left, $right, $expires] = $parts;

        if ((int) $expires < time()) {
            return false;
        }

        $expected = (string) ((int) $left + (int) $right);

        return hash_equals($expected, trim($answer));
    }
}
