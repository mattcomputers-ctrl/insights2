-- ============================================================
-- 019 — Backorder flag (Matt, 2026-11-04): an open sales order with
-- ANY material already shipped is a backorder.
-- ============================================================

ALTER TABLE or_orders
    ADD COLUMN is_backorder TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO schema_migrations (version) VALUES ('019_backorder')
ON DUPLICATE KEY UPDATE version = version;
