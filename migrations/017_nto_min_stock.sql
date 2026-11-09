-- ============================================================
-- 017 — Need to Order: minimum-stock view (Matt, 2026-11-02).
-- Second lens on the buy list: purchased (recipe-less) items whose
-- PROJECTED stock — on hand minus everything needed to complete open
-- orders and open batch tickets — lands below their minimum. The
-- order quantity tops them back UP to minimum.
-- ============================================================

CREATE TABLE IF NOT EXISTS nto_min_items (
    item_code     VARCHAR(40) NOT NULL,
    description   VARCHAR(255) NOT NULL DEFAULT '',
    unit          VARCHAR(16)  NOT NULL DEFAULT '',
    qty_needed    DOUBLE NOT NULL DEFAULT 0,    -- minimum − projected
    minimum_stock DOUBLE NOT NULL DEFAULT 0,
    on_hand       DOUBLE NOT NULL DEFAULT 0,    -- usable right now
    projected     DOUBLE NOT NULL DEFAULT 0,    -- on hand − all requirements
    on_po         DOUBLE NOT NULL DEFAULT 0,
    first_needed  DATE NULL,
    src_ticket    TINYINT(1) NOT NULL DEFAULT 0,
    src_order     TINYINT(1) NOT NULL DEFAULT 0,
    has_recipe    TINYINT(1) NOT NULL DEFAULT 0,
    preferred_vendor VARCHAR(128) NULL,
    vendor        VARCHAR(128) NULL,
    vendor_code   VARCHAR(64)  NULL,
    vendor_count  INT NOT NULL DEFAULT 0,
    refs          TEXT NULL,
    via           TEXT NULL,
    PRIMARY KEY (item_code),
    KEY idx_vendor (vendor),
    KEY idx_pref_vendor (preferred_vendor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('017_nto_min_stock')
ON DUPLICATE KEY UPDATE version = version;
