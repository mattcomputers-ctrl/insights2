-- ============================================================
-- 007_not_milled.sql — "Not Milled / Not Scheduled" item flag
-- Flagged bulk items are excluded from schedule generation
-- entirely (no batches, no warnings). They still count as
-- "configured" so they drop off the needs-configuration list.
-- ============================================================

ALTER TABLE sched_item_config
    ADD COLUMN not_milled TINYINT(1) NOT NULL DEFAULT 0 AFTER batch_size_2;

INSERT INTO schema_migrations (version) VALUES ('007_not_milled')
ON DUPLICATE KEY UPDATE version = version;
