-- 0021_give_the_outbox_a_dedupe_key.sql
-- Let the outbox carry more than one message per subject.
--
-- 0014 keyed the "send once" guarantee on (subject, template, channel). That is
-- right for a submission acknowledgement and wrong for an appointment: an
-- appointment can be moved twice, and the second move would collide with the
-- first unique key and be silently dropped by INSERT IGNORE. The patient would
-- then be told about the first move and not the second, which is worse than
-- telling them nothing because the record now looks correct.
--
-- The guarantee the outbox actually needs is "this transition notifies once", not
-- "this subject notifies once per template". The producer therefore names the
-- transition, and the key becomes a single column. Existing rows are backfilled
-- with the old tuple, which was unique by construction, so no message is lost and
-- no duplicate appears.

ALTER TABLE notification_outbox
    ADD COLUMN dedupe_key VARCHAR(120) NULL AFTER referral_id;

UPDATE notification_outbox
   SET dedupe_key = CONCAT(
           'legacy:',
           COALESCE(booking_id, 0), ':',
           COALESCE(referral_id, 0), ':',
           template, ':',
           channel
       )
 WHERE dedupe_key IS NULL;

ALTER TABLE notification_outbox
    MODIFY dedupe_key VARCHAR(120) NOT NULL;

-- The foreign key on booking_id needs an index of its own before the unique key
-- that used to provide one can be dropped.
ALTER TABLE notification_outbox
    ADD KEY ix_outbox_booking (booking_id);

ALTER TABLE notification_outbox
    DROP INDEX uq_outbox_booking,
    DROP INDEX uq_outbox_referral,
    ADD UNIQUE KEY uq_outbox_dedupe (dedupe_key);
