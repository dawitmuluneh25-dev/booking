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

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }
}
