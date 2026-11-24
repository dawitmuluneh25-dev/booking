<?php

/**
 * The credential register required by HR-ENG-DEC-R1-003 D-10.
 *
 * This file holds **names and dates, never values.** It is in version control on purpose: D-10 asks
 * for a named owner and a rotation history for every secret, and a rotation recorded as a commit is a
 * rotation with a date, an author and a diff. The alternative — a spreadsheet beside the vault — is the
 * artefact this project has already lost once.
 *
 * `rotated_on` is the date the current value was put into service. `null` means "no rotation on
 * record", which is the honest state of every credential here: none of them has a rotation history that
 * can be reconstructed, and inventing one would be worse than the gap. `security:credentials` prints
 * that gap on every run, and `--require-recorded` turns it into a build failure for the release gate.
 *
 * `custodian` is a person. It is null wherever nobody has been named, because naming people is an
 * operational act (D-07) and a name typed by engineering would be the invented identity that decision
 * forbids. The `office` field is the accountable role, and it is filled from the approval record that
 * carries D-10, not from assumption.
 *
 * Editing this file is how a rotation is recorded. See docs/operations/credential-rotation.md.
 */

return [
    'credentials' => [
        [
            'key' => 'DB_PASSWORD',
            'source' => 'env',
            'kind' => 'database',
            'function' => 'Runtime database user: every application query, every migration',
            'office' => 'Engineering Lead',
            'custodian' => null,
            'interval_days' => 90,
            'rotated_on' => null,
        ],
        [
            'key' => 'APP_SECRET',
            'source' => 'env',
            'kind' => 'application',
            'function' => 'HMAC key behind the account-request throttle; unset falls back to a derived value',
            'office' => 'Engineering Lead',
            'custodian' => null,
            'interval_days' => 90,
            'rotated_on' => null,
        ],
        [
            'key' => 'BACKUP_ENCRYPTION_KEY',
            'source' => 'env',
            'kind' => 'backup-key',
            'function' => 'Encrypts every database archive; custody is governed by D-08',
            'office' => 'Engineering Lead',
            'custodian' => null,
            // Null rather than 90: D-10 governs this key by the archive rule, not by the calendar.
            // Rotating it on a schedule would strand every archive encrypted under the old key.
            'interval_days' => null,
            'rotated_on' => null,
        ],
        [
            'key' => 'NOTIFY_BREVO_API_KEY',
            'source' => 'env',
            'kind' => 'api',
            'function' => 'Brevo transactional email; not activated in production (NOTIFY_PRODUCTION_APPROVED)',
            'office' => 'Engineering Lead',
            'custodian' => null,
            'interval_days' => 90,
            'rotated_on' => null,
        ],
        [
            'key' => 'NOTIFY_HTTP_TOKEN',
            'source' => 'env',
            'kind' => 'api',
            'function' => 'Bearer token for the notification gateway bridge, when NOTIFY_DRIVER=http',
            'office' => 'Engineering Lead',
            'custodian' => null,
            'interval_days' => 90,
            'rotated_on' => null,
        ],
        [
            // Not an environment variable and not readable from this host. It is in the register
            // because D-10's five events apply to it and because the plan's S1 has been open since
            // 18 November: the same password guards the panel and FTPS.
            'key' => 'PANEL_PASSWORD',
            'source' => 'external',
            'kind' => 'hosting',
            'function' => 'DirectAdmin panel login and the FTPS account (one secret, two surfaces)',
            'office' => 'Operations Lead',
            'custodian' => null,
            'interval_days' => 90,
            'rotated_on' => null,
        ],
        [
            'key' => 'STAGING_BASIC_AUTH',
            'source' => 'external',
            'kind' => 'hosting',
            'function' => 'htpasswd entry in front of the staging site while it is not public',
            'office' => 'Operations Lead',
            'custodian' => null,
            'interval_days' => 90,
            'rotated_on' => null,
        ],
        [
            'key' => 'ADMIN_LOGIN',
            'source' => 'external',
            'kind' => 'application',
            'function' => 'The admin@hakimerad.com application account; a database row, not a variable',
            'office' => 'Product Owner',
            'custodian' => null,
            'interval_days' => 90,
            'rotated_on' => null,
        ],
    ],

    /**
     * Every other environment key this application reads, with why it is not a credential.
     *
     * The point of this list is the test beside it: `CredentialInventoryTest` scans `src/`, `bin/`,
     * `routes/` and `public/` for every `Env::` read and fails if a key appears in neither this list nor
     * `credentials`. A new secret added to the code without a register entry is therefore a red build
     * rather than a second undocumented key in a `.env` file.
     *
     * @var array<string, string>
     */
    'non_secret' => [
        'APP_NAME' => 'display name',
        'APP_ENV' => 'environment selector; drives the production-only refusals',
        'APP_DEBUG' => 'verbosity flag',
        'APP_URL' => 'base URL, used in links and in the throttle fallback',
        'APP_TIMEZONE' => 'clock',
        'BACKUP_RECOVERY_KEY_FILE' => 'a path to the D-08 recovery copy, not the key itself',
        'DB_CHARSET' => 'connection charset',
        'DB_HOST' => 'endpoint',
        'DB_NAME' => 'schema name',
        'DB_PORT' => 'endpoint',
        'DB_USER' => 'account name; the password is DB_PASSWORD',
        'DOCUMENT_MAX_BYTES' => 'upload cap',
        'DOCUMENT_STORAGE_PATH' => 'a path, deliberately outside the document root',
        'DOCUMENT_TYPES' => 'allowlisted extensions',
        'LOG_LEVEL' => 'verbosity',
        'NOTIFY_BREVO_SENDER' => 'verified sender address',
        'NOTIFY_BREVO_SENDER_NAME' => 'display name on outgoing mail',
        'NOTIFY_DRIVER' => 'transport selector',
        'NOTIFY_HTTP_TIMEOUT' => 'timeout in seconds',
        'NOTIFY_HTTP_URL' => 'gateway endpoint',
        'NOTIFY_PRODUCTION_APPROVED' => 'activation flag for production email',
        'RETENTION_DAYS' => 'retention window in days',
        'RETENTION_SCHEDULE_ACTIVE' => 'activation flag for D-02s per-class schedule',
        'SESSION_LIFETIME' => 'session TTL',
        'SESSION_NAME' => 'cookie name',
        'SESSION_SECURE' => 'cookie flag',
        'TRACKING_LINK_TTL_MINUTES' => 'status-link lifetime',
    ],
];
