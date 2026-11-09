-- ============================================================
-- 013 — Inventory Browser module (Matt, 2026-10-29)
--
-- Snapshot tables rebuilt by the sync engine. Columns on the browser are
-- individually permission-gated (inventory.col_*), declared by the module
-- manifest and assignable per group in the normal group editor.
-- ============================================================

CREATE TABLE IF NOT EXISTS inv_items (
    item_code     VARCHAR(40) NOT NULL,
    description   VARCHAR(255) NOT NULL DEFAULT '',
    unit          VARCHAR(16)  NOT NULL DEFAULT '',
    item_context  VARCHAR(16)  NOT NULL DEFAULT '',
    qty_on_hand   DOUBLE NOT NULL DEFAULT 0,   -- InventoryTotal.QtyUsable (all owners)
    qty_on_so     DOUBLE NOT NULL DEFAULT 0,   -- open SH lines, remaining
    qty_making    DOUBLE NOT NULL DEFAULT 0,   -- open MFBA/MFPP PK output
    qty_consuming DOUBLE NOT NULL DEFAULT 0,   -- open ticket UI lines, still to issue
    qty_on_po     DOUBLE NOT NULL DEFAULT 0,   -- open PO lines, remaining
    PRIMARY KEY (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_vendor_codes (
    item_code   VARCHAR(40)  NOT NULL,
    vendor      VARCHAR(128) NOT NULL,
    vendor_code VARCHAR(128) NOT NULL,
    po_count    INT NOT NULL DEFAULT 0,
    last_po     DATE NULL,
    PRIMARY KEY (item_code, vendor, vendor_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('013_inventory_browser')
ON DUPLICATE KEY UPDATE version = version;
