<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * An error that is safe to surface as an HTTP status to the client.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
    ) {
        parent::__construct($message === '' ? self::reason($status) : $message);
    }

    public static function notFound(string $message = 'Page not found.'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'You are not allowed to do that.'): self
    {
        return new self(403, $message);
    }

    public static function methodNotAllowed(string $allow): self
    {
        return new self(405, 'Method not allowed.', ['Allow' => $allow]);
    }

    public static function tooManyRequests(string $message = 'Too many requests. Try again shortly.'): self
    {
        return new self(429, $message);
    }

    private static function reason(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            403 => 'Forbidden.',
            404 => 'Page not found.',
            405 => 'Method not allowed.',
            419 => 'Your session expired. Please try again.',
            422 => 'The submitted data is invalid.',
            429 => 'Too many requests.',
            default => 'Something went wrong.',
        };
    }
}
