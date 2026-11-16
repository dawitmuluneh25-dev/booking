-- 0039_let_the_outbox_carry_an_account.sql
-- Let a message name the account it concerns, not only a booking or a referral.
--
-- A password reset belongs to nobody's appointment. Until now the outbox could
-- carry only a booking or a referral, so the one message that has to reach a person
-- before they can sign in had no way to travel through it — it could only be
-- printed by a console command and read out over a telephone.
--
-- A third subject column rather than a nullable anything: the foreign key is the
-- integrity. A pending reset for a deleted account is meaningless, and ON DELETE
-- CASCADE removes it along with the account instead of leaving a message that can
-- never be delivered and will be retried until it dead-letters.
--
-- The check stays a check. Dropping it to "some subject or none" would make the
-- column decorative: every row carries a dedupe key, so the constraint would be
-- satisfied by nothing in particular. Naming one of the three subjects is still
-- required, and adding a fourth is another migration like this one.

ALTER TABLE notification_outbox
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER referral_id,
    ADD KEY ix_outbox_user (user_id),
    ADD CONSTRAINT fk_outbox_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

ALTER TABLE notification_outbox
    DROP CONSTRAINT ck_outbox_subject,
    ADD CONSTRAINT ck_outbox_subject
        CHECK (booking_id IS NOT NULL OR referral_id IS NOT NULL OR user_id IS NOT NULL);
