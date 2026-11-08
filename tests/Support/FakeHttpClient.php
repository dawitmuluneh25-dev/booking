<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notifications\HttpClient;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url: string, body: string, headers: array<string, string>, timeout: int}> */
    public array $requests = [];

    public function __construct(public int $status = 202)
    {
    }

    public function post(string $url, string $body, array $headers, int $timeoutSeconds): int
    {
        $this->requests[] = [
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
            'timeout' => $timeoutSeconds,
        ];

        return $this->status;
    }

    /** @return array<string, mixed> */
    public function decoded(int $index = 0): array
    {
        $body = $this->requests[$index]['body'] ?? '{}';
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
