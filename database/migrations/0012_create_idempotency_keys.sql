-- 0012_create_idempotency_keys.sql
-- Idempotency records for commands that must not run twice.
--
-- A coordinator on a slow connection presses "Approve" twice; the browser retries
-- the POST; a proxy replays it. Without a record, that is two clinical decisions,
-- two history rows and two notifications for one intent.
--
-- The key is the client's token, hashed: the raw value never reaches the database,
-- so a leaked table cannot be replayed against the API. The request fingerprint
-- hashes the normalised payload, which lets the store answer three questions:
--
--   new        the key has not been seen, carry on
--   replay     the same key and the same request finished; return the first result
--   conflict   the same key arrived with a different request, or is still running
--
-- `response_reference` stores the human reference of whatever was created (a
-- referral reference, an appointment reference), not a serialised response, so a
-- replay can be answered by reading the real row instead of trusting a snapshot.
--
-- Retention: rows are disposable after `expires_at`. Nothing may reference them,
-- so an expired key is a forgotten request, not a broken link.

CREATE TABLE idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(60) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'in_progress',
    response_reference VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency_key (scope, key_hash),
    KEY ix_idempotency_expiry (expires_at),
    KEY ix_idempotency_actor (actor_user_id, created_at),
    CONSTRAINT fk_idempotency_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_idempotency_status CHECK (status IN ('in_progress', 'completed')),
    CONSTRAINT ck_idempotency_completed CHECK (
        status <> 'completed' OR (response_reference IS NOT NULL AND completed_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
