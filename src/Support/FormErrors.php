<?php

declare(strict_types=1);

namespace App\Support;

use App\Security\Session;

/**
 * Carries validation messages across the redirect that follows a rejected POST.
 *
 * Post/redirect/get means the errors cannot simply be rendered: the handler has
 * already decided to send the browser back to the form. Flashing them as JSON
 * keeps field names and messages together without inventing a second session
 * format, and `pull` clears them so a refresh does not resurrect a stale message.
 */
final class FormErrors
{
    /** @param array<string, string> $errors */
    public static function remember(array $errors, string $key = 'form_errors'): void
    {
        Session::flash($key, (string) json_encode($errors, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, string> */
    public static function pull(string $key = 'form_errors'): array
    {
        $raw = Session::pullFlash($key);

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $errors = [];
        foreach ($decoded as $field => $message) {
            if (is_string($field) && is_string($message)) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }
}
