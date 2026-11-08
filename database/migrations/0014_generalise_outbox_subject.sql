-- 0014_generalise_outbox_subject.sql
-- Let the outbox carry a referral, not only a booking.
--
-- Phase 2 §15 requires the outbox row and the state change to commit together, so
-- a referral submission must be able to write its notification in the same
-- transaction. The original table was keyed to `bookings` with a NOT NULL column,
-- which made that impossible without inventing a booking.
--
-- Two unique keys replace the single one, because `(NULL, template, channel)`
-- never collides: one key per subject family keeps the "send once per transition"
-- guarantee for bookings and gives referrals the same guarantee. A single key over
-- a nullable column would have silently allowed duplicates.
--
-- The check only requires that a message names some subject. Adding a further
-- subject later is another migration, not a relocation of this one.

ALTER TABLE notification_outbox
    MODIFY booking_id BIGINT UNSIGNED NULL,
    ADD COLUMN referral_id BIGINT UNSIGNED NULL AFTER booking_id;

ALTER TABLE notification_outbox
    DROP INDEX uq_outbox_transition,
    ADD UNIQUE KEY uq_outbox_booking (booking_id, template, channel),
    ADD UNIQUE KEY uq_outbox_referral (referral_id, template, channel),
    ADD KEY ix_outbox_referral (referral_id),
    ADD CONSTRAINT fk_outbox_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE CASCADE,
    ADD CONSTRAINT ck_outbox_subject
        CHECK (booking_id IS NOT NULL OR referral_id IS NOT NULL);
