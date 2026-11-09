-- ============================================================
-- 005_mill_dry_grind.sql — Mills flagged dry-grind capable
-- Dry-grind batches only place on capable mills. Default 1
-- (capable) so existing mills keep current behavior until an
-- admin unchecks the ones that can't do it.
-- ============================================================

ALTER TABLE sched_mills
    ADD COLUMN dry_grind_capable TINYINT(1) NOT NULL DEFAULT 1 AFTER max_batch_lbs;

INSERT INTO schema_migrations (version) VALUES ('005_mill_dry_grind')
ON DUPLICATE KEY UPDATE version = version;
