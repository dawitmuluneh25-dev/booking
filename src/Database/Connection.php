<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * PDO factory with predictable session state.
 *
 * Every connection is pinned to UTC and strict mode so timestamps and
 * truncation behaviour do not depend on the server default.
 */
final class Connection
{
    /**
     * @param array{
     *     host: string,
     *     port: int,
     *     database: string,
     *     username: string,
     *     password: string,
     *     charset: string
     * } $config
     */
    public static function create(array $config, bool $administrative = false): PDO
    {
        if ($config['username'] === '') {
            throw new RuntimeException('Database credentials are not configured.');
        }

        if ($config['database'] === '' && !$administrative) {
            throw new RuntimeException('DB_NAME is not configured.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;%scharset=%s',
            $config['host'],
            $config['port'],
            $config['database'] === '' ? '' : 'dbname=' . $config['database'] . ';',
            $config['charset'],
        );

        if ($administrative) {
            // Administrative connection: used only to create or drop a scratch
            // schema for a restore drill, never to serve a request.
            return new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        }

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }
}
