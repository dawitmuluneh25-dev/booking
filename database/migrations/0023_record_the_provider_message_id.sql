-- 0023_record_the_provider_message_id.sql
-- Keep the provider's own identifier for an accepted message.
--
-- O-3-DEC-01 requires that provider acceptance, delivery and human
-- acknowledgement remain distinct states, and that delivery and failure be
-- recorded where the provider supports it. None of that is possible from the
-- outbox alone: once a message leaves, the provider is the only party that knows
-- what became of it, and it will only answer questions about an identifier it
-- issued.
--
-- Without this column the only way to match a delivery report back to a message
-- would be recipient and time — which is a guess, and a guess that becomes wrong
-- the moment somebody is sent two notifications in an afternoon.
--
-- Nullable on purpose. Messages already sent have no identifier and never will;
-- a NOT NULL column would either fail on them or invite a fabricated value.

ALTER TABLE notification_outbox
    ADD COLUMN provider_message_id VARCHAR(190) NULL AFTER last_error;
