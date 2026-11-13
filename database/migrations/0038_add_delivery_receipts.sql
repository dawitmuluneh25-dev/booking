-- 0038_add_delivery_receipts.sql
-- Let a provider's delivery report be recorded against the message it concerns.
--
-- 0023 kept the provider's identifier for an accepted message, which made it
-- possible to ask "what became of this?" but left nowhere to write the answer.
-- O-3-DEC-01 requires acceptance, delivery and human acknowledgement to stay
-- three distinct states, and `sent_at` alone cannot express two of them: it means
-- the provider took the message, not that it reached anybody.
--
-- Nullable on purpose, and not defaulted. A message with no receipt has unknown
-- delivery, which is a different fact from a message that bounced. Writing a
-- timestamp "just in case" would turn a gap in the evidence into a claim.
--
-- Not backfilled from `sent_at` either: acceptance is not delivery, and filling
-- the column with the moment of sending would erase the distinction this column
-- exists to preserve.

ALTER TABLE notification_outbox
    ADD COLUMN delivered_at DATETIME NULL AFTER sent_at;
