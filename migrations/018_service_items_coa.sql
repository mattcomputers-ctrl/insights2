-- ============================================================
-- 018 — Service pseudo-items & COA settings (Matt, 2026-11-04).
--
-- Items with GLGroup 'Services' (wire fees, shipping charges) are not
-- inventoried: or_items carries the flag so coverage treats them as
-- always-ready and the materials check never lists them as short.
--
-- COA generation settings (Admin → System settings): a company logo
-- and a disclaimer statement printed at the bottom of every COA.
-- ============================================================

ALTER TABLE or_items
    ADD COLUMN is_service TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO settings (`key`, `value`) VALUES
    ('coa.disclaimer', ''),
    ('coa.logo', '')
ON DUPLICATE KEY UPDATE `key` = `key`;

INSERT INTO schema_migrations (version) VALUES ('018_service_items_coa')
ON DUPLICATE KEY UPDATE version = version;
