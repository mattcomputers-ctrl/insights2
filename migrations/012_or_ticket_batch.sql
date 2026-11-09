-- ============================================================
-- 012 — Order Readiness: main batch numbers + open-ticket credit
--
-- or_tickets.batch_ordr: the MAIN batch order (MFBA) number people
-- recognise. For an MFPP packaging ticket it comes from BatchSheetDemand
-- (its supplying MFBA); for an MFBA it is the ticket itself. All
-- user-facing text names this number, never the internal MFPP row.
--
-- or_production_suggestions.open_ticket: an open ticket already exists
-- for this item (or its bulk) — shown on the production plan so nobody
-- schedules a batch that is already cut, even when CMS has no quantity
-- on the ticket yet.
-- ============================================================

ALTER TABLE or_tickets
    ADD COLUMN IF NOT EXISTS batch_ordr INT NULL AFTER customer_hint;

ALTER TABLE or_production_suggestions
    ADD COLUMN IF NOT EXISTS open_ticket INT NULL AFTER last_made;

INSERT INTO schema_migrations (version) VALUES ('012_or_ticket_batch')
ON DUPLICATE KEY UPDATE version = version;
