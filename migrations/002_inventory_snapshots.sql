-- ============================================================
-- 002_inventory_snapshots.sql
-- Caches daily inventory snapshots locally so the dashboard
-- doesn't have to call the slow CMS GetInventoryAtDate TVF
-- (~45 seconds per call) on every page load.
--
-- Populated nightly by cron/snapshot-inventory.php. Stores one
-- row per (snapshot_date, gl_group). Total-across-groups is
-- computed in SQL on read so we don't store derived data.
-- ============================================================

CREATE TABLE IF NOT EXISTS inventory_snapshots (
    snapshot_date           DATE NOT NULL,
    gl_group                VARCHAR(64) NOT NULL,
    total_qty               DOUBLE NOT NULL DEFAULT 0,
    total_actual_value      DECIMAL(18, 2) NOT NULL DEFAULT 0,
    total_replacement_value DECIMAL(18, 2) NOT NULL DEFAULT 0,
    item_count              INT UNSIGNED NOT NULL DEFAULT 0,
    captured_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (snapshot_date, gl_group),
    KEY idx_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('002_inventory_snapshots')
ON DUPLICATE KEY UPDATE version = version;
