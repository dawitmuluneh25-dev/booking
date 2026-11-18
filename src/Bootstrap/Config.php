<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Domain\DocumentTypes;
use App\Domain\ReferralTrackingLink;

/**
 * Typed read-only view over configuration values resolved from the environment.
 */
final class Config
{
    /**
     * The directed retention period, in days: five years.
     *
     * Five calendar years are 1826 days, or 1827 when the span holds two leap
     * days. Taking the larger figure means the window never ends even one day
     * before the period the privacy notice states.
     */
    public const DEFAULT_RETENTION_DAYS = 1827;

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

    /**
     * Days after which the personal data of a finished booking is redacted.
     *
     * The fallback is the directed five years, so an unset variable cannot
     * silently shorten a period the privacy notice states.
     */
    public function retentionDays(): int
    {
        return max(1, Env::int('RETENTION_DAYS', self::DEFAULT_RETENTION_DAYS));
    }

    /**
     * The key that turns a visitor's address into a stored counter hash.
     *
     * `APP_SECRET` is the right answer and is set in production. When it is absent the
     * fallback derives a key from values this installation already holds — the
     * database password and the base URL — so the counter is not portable between
     * installations and cannot be reversed by enumerating IPv4 space. It is a weaker
     * secret than a generated one, which is why it is documented rather than silent:
     * an empty `APP_SECRET` is a fallback, not a null.
     */
    public function appSecret(): string
    {
        $secret = trim((string) Env::get('APP_SECRET', ''));

        if ($secret !== '') {
            return $secret;
        }

        $database = $this->database();
        $password = $database['password'] ?? '';

        return hash('sha256', 'account-request-throttle|' . (string) $password . '|' . $this->baseUrl());
    }

    /**
     * Private document storage, deliberately outside `public/`.
     *
     * A relative value is resolved against the application root so a deployment
     * that moves the code does not silently start writing to a path that happens
     * to be web-served.
     */
    public function documentStoragePath(): string
    {
        $configured = trim((string) Env::get('DOCUMENT_STORAGE_PATH', ''));

        if ($configured === '') {
            return $this->root('var/private-uploads');
        }

        $absolute = str_starts_with($configured, DIRECTORY_SEPARATOR)
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $configured) === 1;

        return $absolute
            ? $configured
            : $this->root($configured);
    }

    /** Per-file cap. The 15 MiB default is the reviewed application limit (SEC 14.1). */
    public function documentMaxBytes(): int
    {
        return max(1024, min(Env::int('DOCUMENT_MAX_BYTES', 15 * 1024 * 1024), 64 * 1024 * 1024));
    }

    /** The reviewed allowlist, optionally narrowed - never widened - by environment. */
    public function documentTypes(): DocumentTypes
    {
        $configured = trim((string) Env::get('DOCUMENT_TYPES', ''));

        if ($configured === '') {
            return new DocumentTypes();
        }

        $extensions = array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            explode(',', $configured),
        );

        return new DocumentTypes(array_values(array_filter($extensions)));
    }

    /** How long a status link lives. A security default, not a clinical retention period. */
    public function trackingLinkTtlMinutes(): int
    {
        $configured = Env::int('TRACKING_LINK_TTL_MINUTES', ReferralTrackingLink::DEFAULT_TTL_MINUTES);

        return max(1, min($configured, 10080));
    }

    /** `log` (development), `http` (a gateway bridge you operate) or `brevo` (transactional email, O-3). */
    public function notificationDriver(): string
    {
        $driver = strtolower((string) Env::get('NOTIFY_DRIVER', 'log'));

        return in_array($driver, ['log', 'http', 'brevo'], true) ? $driver : 'log';
    }

    /**
     * Brevo API key. Read from the environment so the secret never lives in the
     * document root, in Git, or in a template somebody can fetch.
     */
    public function brevoApiKey(): string
    {
        return trim((string) Env::get('NOTIFY_BREVO_API_KEY', ''));
    }

    /** The verified sender address, e.g. notifications@hakimerad.com. */
    public function brevoSender(): string
    {
        return trim((string) Env::get('NOTIFY_BREVO_SENDER', ''));
    }

    public function brevoSenderName(): string
    {
        $name = trim((string) Env::get('NOTIFY_BREVO_SENDER_NAME', 'HakimeRAD'));

        return $name === '' ? 'HakimeRAD' : $name;
    }

    /**
     * Whether transactional email may actually send from a production
     * environment.
     *
     * O-3-DEC-01 authorizes the integration but not the activation: production
     * sending stays off until the mailbox, DNS, credential and staging evidence
     * in section 8 has been reviewed. An unset flag means unapproved, which is
     * the safe reading of a missing decision.
     */
    public function mailActivationApproved(): bool
    {
        return Env::bool('NOTIFY_PRODUCTION_APPROVED', false);
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
