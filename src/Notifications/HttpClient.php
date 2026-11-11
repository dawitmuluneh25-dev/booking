<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Minimal POST client, injected so the transport can be tested without a
 * network and so a different client can be substituted on hosting without curl.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     *
     * @return HttpResponse status and body; the body carries a provider
     *                      identifier when the provider returns one
     */
    public function post(string $url, string $body, array $headers, int $timeoutSeconds): HttpResponse;
}
