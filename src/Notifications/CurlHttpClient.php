<?php

declare(strict_types=1);

namespace App\Notifications;

use RuntimeException;

final class CurlHttpClient implements HttpClient
{
    public function post(string $url, string $body, array $headers, int $timeoutSeconds): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl is required for the HTTP notification transport.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Cannot initialise the HTTP client.');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        ]);

        $result = curl_exec($handle);
        if ($result === false) {
            $message = curl_error($handle);
            curl_close($handle);

            throw new RuntimeException('Notification gateway is unreachable: ' . $message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        // The body is returned rather than discarded: a provider that answers with
        // a message identifier is the only way to reconcile a send against a later
        // delivery report.
        return new HttpResponse($status, is_string($result) ? $result : '');
    }
}
