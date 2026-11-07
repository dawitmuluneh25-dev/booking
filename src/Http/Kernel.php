<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Logger;
use App\Support\View;
use Throwable;

/**
 * Turns a Request into a Response and guarantees a safe error path.
 */
final class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly View $view,
        private readonly Logger $logger,
        private readonly bool $debug = false,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->router->dispatch($request);
        } catch (HttpException $exception) {
            $response = $this->errorResponse(
                $exception->status,
                $exception->getMessage(),
                $exception->headers,
            );
        } catch (Throwable $exception) {
            $this->logger->error('unhandled_exception', [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
                'location' => $exception->getFile() . ':' . $exception->getLine(),
            ]);

            $response = $this->errorResponse(
                500,
                $this->debug ? $exception->getMessage() : 'Something went wrong. Please try again later.',
            );
        }

        return $this->withSecurityHeaders($response, $request->isSecure());
    }

    /** @param array<string, string> $headers */
    private function errorResponse(int $status, string $message, array $headers = []): Response
    {
        $template = 'errors/' . $status;
        if (!is_file(dirname(__DIR__, 2) . '/templates/' . $template . '.php')) {
            $template = 'errors/500';
        }

        $html = $this->view->render($template, [
            'title' => $status . ' — ' . $this->title($status),
            'status' => $status,
            'message' => $message,
        ]);

        return Response::html($html, $status, $headers);
    }

    private function title(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            419 => 'Session expired',
            429 => 'Too many requests',
            503 => 'Temporarily unavailable',
            default => 'Server error',
        };
    }

    private function withSecurityHeaders(Response $response, bool $secure): Response
    {
        $policy = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "img-src 'self' data:",
            "style-src 'self'",
            "script-src 'self'",
            "connect-src 'self'",
            "manifest-src 'self'",
            "worker-src 'self'",
        ]);

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Content-Security-Policy', $policy);

        if ($secure) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
