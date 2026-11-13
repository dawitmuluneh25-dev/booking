-- 0034_add_guardian_verification.sql
-- Who verified that this person may decide for the patient (PAT-013, §6.3).
--
-- `guardian_verified_at` has existed since 0009 with no writer, which meant a
-- referral could name a guardian and nothing could ever say whether that person's
-- authority had been checked. Naming somebody is not verifying them: §18.2 makes the
-- same point about consent, and the two must not drift apart.
--
-- Verification is a fact with three parts, so the columns come in a set:
--
--   guardian_verified_at            when it was checked
--   guardian_verified_by_user_id    who checked it
--   guardian_verification_basis     what they checked it against
--
-- The CHECKs make a partial answer unrepresentable: a time without a verifier, a
-- verifier without a time, or a verification with no stated basis is not a record.
-- "I know the family" is not a basis a later reader can weigh, which is why the
-- basis has a minimum length rather than being a checkbox.

ALTER TABLE referrals
    ADD COLUMN guardian_verified_by_user_id BIGINT UNSIGNED NULL AFTER guardian_verified_at,
    ADD COLUMN guardian_verification_basis VARCHAR(160) NULL AFTER guardian_verified_by_user_id,
    ADD CONSTRAINT fk_referrals_guardian_verifier
        FOREIGN KEY (guardian_verified_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    ADD CONSTRAINT ck_referrals_guardian_verification CHECK (
        (guardian_verified_at IS NULL) = (guardian_verified_by_user_id IS NULL)
    ),
    ADD CONSTRAINT ck_referrals_guardian_basis CHECK (
        guardian_verified_at IS NULL
        OR CHAR_LENGTH(TRIM(COALESCE(guardian_verification_basis, ''))) >= 10
    );
