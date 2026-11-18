-- 0044_create_account_requests.sql
-- A referring physician asks for an account; a person decides; nothing here is a
-- credential (ADR-013, owner decision 18 November 2026).
--
-- Separate from `users` because a request is not a user: it has no password, no
-- role, and most requests will never become anything. Putting pending strangers in
-- `users` would mean every query that lists staff has to start filtering them out,
-- and one missed filter is a stranger in the staff directory.
--
-- Four decisions are visible in the columns:
--
--   * `email` is indexed but NOT unique. The same person may ask twice after a
--     rejection, and a unique key would turn a public form into an existence
--     oracle: "that address already has a request" is an answer a stranger must
--     not be able to obtain. The queue flags duplicates instead.
--
--   * there is no password column, and there never will be. Nothing on this table
--     is a credential; the account is created by `CreateUserAccount`, which shows
--     a temporary password once and stores only its hash.
--
--   * `note` is the one place a stranger writes into the system, so it is capped
--     and treated as hostile: escaped on output, never rendered as HTML, never
--     copied into a notification body.
--
--   * `decided_by` and `decided_at` are both-or-neither, and only on a decided
--     row. A rejection without a name is an anonymous act, which is the thing the
--     published-content rule already refuses.
--
-- `account_request_attempts` exists because the rate cap has to count attempts
-- that never become rows. Counting submissions would let a bot exceed the cap
-- indefinitely: the sixth submission would be the first one stored, so the cap
-- would never see attempts six through six hundred. It stores a hash of the
-- address, never the address, and is purged after 24 hours by `retention:purge`.

CREATE TABLE account_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference CHAR(12) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    practice_name VARCHAR(160) NOT NULL,
    practice_city VARCHAR(120) NOT NULL,
    registration_no VARCHAR(60) NULL,
    note VARCHAR(1000) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    decided_by BIGINT UNSIGNED NULL,
    decision_reason VARCHAR(500) NULL,
    created_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_requests_reference (reference),
    KEY idx_account_requests_status_created (status, created_at),
    KEY idx_account_requests_email (email),
    CONSTRAINT fk_account_requests_decided_by
        FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_account_requests_user
        FOREIGN KEY (created_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_account_requests_status
        CHECK (status IN ('pending', 'approved', 'rejected', 'expired')),
    CONSTRAINT ck_account_requests_decided
        CHECK (
            (status IN ('pending', 'expired') AND decided_at IS NULL AND decided_by IS NULL)
            OR (status IN ('approved', 'rejected') AND decided_at IS NOT NULL AND decided_by IS NOT NULL)
        ),
    -- Only an approval produces an account, and every approval produces one.
    CONSTRAINT ck_account_requests_created_user
        CHECK (
            (status = 'approved' AND created_user_id IS NOT NULL)
            OR (status <> 'approved' AND created_user_id IS NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE account_request_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_account_request_attempts_ip_time (ip_hash, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
