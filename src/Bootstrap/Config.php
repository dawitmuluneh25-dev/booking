<?php

declare(strict_types=1);

namespace App\Bootstrap;

/**
 * Typed read-only view over configuration values resolved from the environment.
 */
final class Config
{
    public function __construct(private readonly string $root)
    {
    }

    public function root(string $path = ''): string
    {
        return $path === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    public function appName(): string
    {
        return (string) Env::get('APP_NAME', 'Booking');
    }

    public function environment(): string
    {
        return (string) Env::get('APP_ENV', 'production');
    }

    public function debug(): bool
    {
        return Env::bool('APP_DEBUG', false);
    }

    public function baseUrl(): string
    {
        return rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/');
    }

    public function timezone(): string
    {
        return (string) Env::get('APP_TIMEZONE', 'UTC');
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string, charset: string} */
    public function database(): array
    {
        return [
            'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
            'port' => Env::int('DB_PORT', 3306),
            'database' => (string) Env::get('DB_NAME', ''),
            'username' => (string) Env::get('DB_USER', ''),
            'password' => (string) Env::get('DB_PASSWORD', ''),
            'charset' => (string) Env::get('DB_CHARSET', 'utf8mb4'),
        ];
    }

    public function sessionName(): string
    {
        return (string) Env::get('SESSION_NAME', 'booking_session');
    }

    public function sessionLifetime(): int
    {
        return Env::int('SESSION_LIFETIME', 7200);
    }

    public function sessionSecure(): bool
    {
        return Env::bool('SESSION_SECURE', false);
    }

    public function logLevel(): string
    {
        return (string) Env::get('LOG_LEVEL', 'debug');
    }

    public function logPath(): string
    {
        return $this->root('var/logs/app.log');
    }

    public function logDirectory(): string
    {
        return $this->root('var/logs');
    }

    public function backupDirectory(): string
    {
        return $this->root('var/backups');
    }

    /** Days after which the personal data of a finished booking is redacted. */
    public function retentionDays(): int
    {
        return max(1, Env::int('RETENTION_DAYS', 730));
    }

    /** `log` (development) or `http` (a gateway bridge you operate). */
    public function notificationDriver(): string
    {
        $driver = strtolower((string) Env::get('NOTIFY_DRIVER', 'log'));

        return in_array($driver, ['log', 'http'], true) ? $driver : 'log';
    }

    public function notificationUrl(): string
    {
        return (string) Env::get('NOTIFY_HTTP_URL', '');
    }

    public function notificationToken(): string
    {
        return (string) Env::get('NOTIFY_HTTP_TOKEN', '');
    }

    public function notificationTimeout(): int
    {
        return max(1, min(Env::int('NOTIFY_HTTP_TIMEOUT', 5), 30));
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }
}
