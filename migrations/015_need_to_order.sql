-- ============================================================
-- 015 — Need to Order module (Matt, 2026-11-02): the consolidated
-- buy list — materials needed to run every open batch ticket AND
-- the batches that still have to be cut for open sales orders,
-- recipe-exploded and netted against stock + open tickets + POs.
--
-- nto_items is a SNAPSHOT computed each sync (staged + swapped).
-- ============================================================

CREATE TABLE IF NOT EXISTS nto_items (
    item_code    VARCHAR(40) NOT NULL,
    description  VARCHAR(255) NOT NULL DEFAULT '',
    unit         VARCHAR(16)  NOT NULL DEFAULT '',
    qty_needed   DOUBLE NOT NULL DEFAULT 0,     -- to buy (already netted)
    on_po        DOUBLE NOT NULL DEFAULT 0,     -- already on open POs
    first_needed DATE NULL,
    src_ticket   TINYINT(1) NOT NULL DEFAULT 0, -- needed by an open ticket
    src_order    TINYINT(1) NOT NULL DEFAULT 0, -- needed by a batch still to cut
    has_recipe   TINYINT(1) NOT NULL DEFAULT 0, -- could also be made in-house
    vendor       VARCHAR(128) NULL,             -- most recent vendor
    vendor_code  VARCHAR(64)  NULL,             -- their code at that vendor
    vendor_count INT NOT NULL DEFAULT 0,
    refs         TEXT NULL,                     -- JSON [{ref,src}] roots
    via          TEXT NULL,                     -- JSON [intermediates to make]
    PRIMARY KEY (item_code),
    KEY idx_vendor (vendor),
    KEY idx_needed (first_needed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('015_need_to_order')
ON DUPLICATE KEY UPDATE version = version;
