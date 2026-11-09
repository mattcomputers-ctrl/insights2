-- ============================================================
-- 016 — Need to Order: preferred vendor (Matt, 2026-11-02).
-- Two vendor columns: the item's PREFERRED vendor (Item.Supplier in
-- CMS) and the vendor LAST PURCHASED from; groupable by either.
-- ============================================================

ALTER TABLE nto_items
    ADD COLUMN preferred_vendor VARCHAR(128) NULL AFTER has_recipe,
    ADD KEY idx_pref_vendor (preferred_vendor);

INSERT INTO schema_migrations (version) VALUES ('016_nto_preferred_vendor')
ON DUPLICATE KEY UPDATE version = version;
