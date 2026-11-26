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
     * The directed retention period, in days: seven years.
     *
     * Seven calendar years are 2555 days, or 2557 when the span holds two leap
     * days. Taking the larger figure means the window never ends even one day
     * before the period the privacy notice states. It was 1827 — five years —
     * until DEC-2026-11-20-02 replaced that number with the Ethiopian medical
     * record-keeping directive's seven years, which is the figure the notice now
     * publishes. Shortening it again would dispose of clinical records two years
     * before the directive allows, so `RetentionWindowTest` pins the value.
     */
    public const DEFAULT_RETENTION_DAYS = 2557;

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

    /** The document root: nothing secret may live under it. */
    public function publicRoot(): string
    {
        return $this->root('public');
    }

    /** The file that holds the environment, which is where the backup key is expected to live. */
    public function envFile(): string
    {
        return $this->root('.env');
    }

    /**
     * `BACKUP_RECOVERY_KEY_FILE` — the independent copy of the backup key (D-08).
     *
     * Null when the variable is unset, which is the honest state today: no recovery copy exists, so
     * losing this host loses every archive. `backup:key-check` reports that as a warning on every run
     * rather than letting it be discovered during an incident.
     */
    public function backupRecoveryKeyFile(): ?string
    {
        $path = trim((string) Env::get('BACKUP_RECOVERY_KEY_FILE', ''));

        return $path === '' ? null : $path;
    }

    /**
     * `BACKUP_ENCRYPTION_KEY` — the base64 key that turns a dump into a file nobody can read without it.
     *
     * Null when unset, and that is a supported state rather than an error. The hourly dump has to keep
     * running on a host whose key has not been provisioned yet, and a backup that refuses to run is a
     * worse outcome than one that states in its output that it is not encrypted. `db:backup
     * --require-encryption` is how a cron entry fails closed once the key exists — the operator chooses
     * the day that becomes true, rather than discovering it when the dumps stop.
     */
    public function backupEncryptionKey(): ?string
    {
        $key = trim((string) Env::get('BACKUP_ENCRYPTION_KEY', ''));

        return $key === '' ? null : $key;
    }

    /**
     * Days after which the personal data of a finished booking is redacted.
     *
     * The fallback is the directed seven years, so an unset variable cannot
     * silently shorten a period the privacy notice states.
     */
    public function retentionDays(): int
    {
        return max(1, Env::int('RETENTION_DAYS', self::DEFAULT_RETENTION_DAYS));
    }

    /**
     * Whether D-02's per-class schedule has been validated and may run.
     *
     * Off unless somebody sets `RETENTION_SCHEDULE_ACTIVE=true`, and setting it is a Legal decision
     * rather than an engineering one: D-02's legal condition is that Legal/Data Protection validate
     * the periods, the lawful basis, the preservation exceptions and the applicable Ethiopian
     * requirements before production activation. A default of `false` means the bad outcome of a
     * forgotten environment variable is "the old single window keeps running", not "records start
     * being deleted on a schedule nobody validated".
     */
    public function retentionScheduleActive(): bool
    {
        return Env::bool('RETENTION_SCHEDULE_ACTIVE', false);
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
     * Whether a production environment may actually deliver notifications.
     *
     * The key name says mail because transactional email is where the question
     * first arose (O-3-DEC-01), but the rule it enforces is about delivery, not
     * about a medium: the HTTP bridge answers the same question and is gated by
     * the same flag. O-3-DEC-01 authorizes the integration but not the
     * activation, so production sending stays off until the evidence in section
     * 8 has been reviewed. An unset flag means unapproved, which is the safe
     * reading of a missing decision.
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
