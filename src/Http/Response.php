<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers, $body);
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $body = '{"error":"encoding_failed"}';
            $status = 500;
        }

        return new self($status, ['Content-Type' => 'application/json; charset=UTF-8'], $body);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, ['Location' => $location], '');
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=UTF-8'], $body);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->status, $headers, $this->body);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}
