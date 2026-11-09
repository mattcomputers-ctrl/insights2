-- ============================================================
-- 020 — Batch size tiers + defaults (Matt, 2026-11-04):
-- up to FIVE standard batch sizes per item (seeded from previous batch
-- history as a starting point), plus global default batch sizes used
-- for items with no config or history.
-- ============================================================

ALTER TABLE sched_item_config
    ADD COLUMN batch_size_3 DOUBLE NULL AFTER batch_size_2,
    ADD COLUMN batch_size_4 DOUBLE NULL AFTER batch_size_3,
    ADD COLUMN batch_size_5 DOUBLE NULL AFTER batch_size_4;

INSERT INTO settings (`key`, `value`) VALUES ('sched.default_batch_sizes', '')
ON DUPLICATE KEY UPDATE `key` = `key`;

INSERT INTO schema_migrations (version) VALUES ('020_batch_size_tiers')
ON DUPLICATE KEY UPDATE version = version;
