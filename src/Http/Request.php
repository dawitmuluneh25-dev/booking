<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Immutable representation of the incoming HTTP request.
 *
 * Nothing here trusts client-supplied authority: role, identity and ownership
 * must always be resolved server-side from the session.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $server,
        private readonly array $cookies,
    ) {
    }

    public static function capture(): self
    {
        $rawMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($rawMethod) ? strtoupper($rawMethod) : 'GET';

        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($rawUri) ? $rawUri : '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            $method,
            self::normalizePath(is_string($path) ? $path : '/'),
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
        );
    }

    /**
     * Build a request without touching PHP superglobals. Used by tests.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     */
    public static function create(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $server = [],
        array $cookies = [],
    ): self {
        return new self(
            strtoupper($method),
            self::normalizePath($path),
            $query,
            $body,
            $server,
            $cookies,
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? '/';

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Read a trimmed string from the body first, then the query string.
     */
    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? null;

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? $default : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function ip(): string
    {
        $remote = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        return is_string($remote) && $remote !== '' ? $remote : '0.0.0.0';
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? null;

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || $this->header('X-Forwarded-Proto') === 'https';
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('Accept');

        return $accept !== null && str_contains($accept, 'application/json');
    }
}
