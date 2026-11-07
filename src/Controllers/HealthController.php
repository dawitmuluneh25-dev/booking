<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use Closure;
use PDO;
use Throwable;

/**
 * Liveness/readiness probe. Public output stays generic; details are limited
 * to which dependency failed, never why.
 */
final class HealthController
{
    /** @param Closure(): PDO $pdoFactory */
    public function __construct(private readonly Closure $pdoFactory)
    {
    }

    public function __invoke(Request $request): Response
    {
        $checks = ['app' => 'ok'];
        $status = 200;

        try {
            $pdo = ($this->pdoFactory)();
            $pdo->query('SELECT 1');
            $checks['database'] = 'ok';

            $migrations = $pdo->query('SELECT COUNT(*) FROM schema_migrations');
            if ($migrations !== false) {
                $checks['migrations'] = (string) $migrations->fetchColumn();
            }
        } catch (Throwable) {
            $checks['database'] = 'unavailable';
            $status = 503;
        }

        return Response::json([
            'status' => $status === 200 ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $status);
    }
}
