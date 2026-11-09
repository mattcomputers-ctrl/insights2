-- ============================================================
-- 006_mill_dry_rate.sql — Separate throughput for dry grinding
-- lbs_per_hour      = standard (non-dry-grind) rate
-- lbs_per_hour_dry  = rate while dry grinding
-- Seeded from the existing rate so behavior is unchanged until
-- the admin tunes the dry rate per mill.
-- ============================================================

ALTER TABLE sched_mills
    ADD COLUMN lbs_per_hour_dry DOUBLE NOT NULL DEFAULT 0 AFTER lbs_per_hour;

UPDATE sched_mills SET lbs_per_hour_dry = lbs_per_hour WHERE lbs_per_hour_dry = 0;

INSERT INTO schema_migrations (version) VALUES ('006_mill_dry_rate')
ON DUPLICATE KEY UPDATE version = version;
