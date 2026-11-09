-- ============================================================
-- 011 — Order Readiness & Production Planning (SPEC Part B)
--
-- or_items / or_inventory / or_orders / or_order_lines / or_tickets /
-- or_ticket_links are SNAPSHOT tables: the sync engine rebuilds them in
-- {table}__staging and swaps atomically. Their schema here is the
-- template the staging tables are created from (CREATE TABLE ... LIKE).
--
-- or_coverage_results / or_production_suggestions are computed per sync.
-- or_order_priority / or_suggestion_marks / or_item_min_stock_history /
-- or_order_notes are DURABLE app data, never swapped.
-- ============================================================

CREATE TABLE IF NOT EXISTS or_items (
    item_code      VARCHAR(40) NOT NULL,
    description    VARCHAR(255) NOT NULL DEFAULT '',
    unit           VARCHAR(16)  NOT NULL DEFAULT '',
    is_pack        TINYINT(1)   NOT NULL DEFAULT 0,
    has_recipe     TINYINT(1)   NOT NULL DEFAULT 0,
    recipe_number  VARCHAR(40)  NULL,
    minimum_stock  DOUBLE       NULL,
    is_discontinued TINYINT(1)  NOT NULL DEFAULT 0,
    last_made      DATETIME     NULL,
    PRIMARY KEY (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_inventory (
    item_code    VARCHAR(40) NOT NULL,
    qty_usable   DOUBLE NOT NULL DEFAULT 0,
    qty_soh      DOUBLE NOT NULL DEFAULT 0,
    qty_hold     DOUBLE NOT NULL DEFAULT 0,
    qty_unusable DOUBLE NOT NULL DEFAULT 0,
    PRIMARY KEY (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_orders (
    ordr            INT NOT NULL,
    status          VARCHAR(8)  NOT NULL,
    bill_to         VARCHAR(40) NOT NULL DEFAULT '',
    bill_to_name    VARCHAR(128) NOT NULL DEFAULT '',
    ship_to         VARCHAR(40) NOT NULL DEFAULT '',
    ship_to_name    VARCHAR(128) NOT NULL DEFAULT '',
    po_number       VARCHAR(64) NULL,
    date_ordered    DATE NULL,
    date_required   DATE NULL,
    dont_ship_before DATE NULL,
    PRIMARY KEY (ordr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_order_lines (
    ord_detail     INT NOT NULL,
    ordr           INT NOT NULL,
    item_code      VARCHAR(40) NOT NULL,
    alias_code     VARCHAR(40) NULL,
    alias_description VARCHAR(255) NULL,
    qty_reqd       DOUBLE NOT NULL DEFAULT 0,
    qty_used       DOUBLE NOT NULL DEFAULT 0,
    qty_remaining  DOUBLE NOT NULL DEFAULT 0,
    qty_committed_cms DOUBLE NOT NULL DEFAULT 0,
    unit           VARCHAR(16) NOT NULL DEFAULT '',
    PRIMARY KEY (ord_detail),
    KEY idx_ordr (ordr),
    KEY idx_item (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_tickets (
    ordr           INT NOT NULL,
    context        VARCHAR(8)  NOT NULL,       -- MFBA | MFPP
    status         VARCHAR(8)  NOT NULL,       -- RLS | NST | BAT
    item_code      VARCHAR(40) NOT NULL,       -- item PRODUCED (PK line)
    qty            DOUBLE NOT NULL DEFAULT 0,
    unit           VARCHAR(16) NOT NULL DEFAULT '',
    date_required  DATE NULL,
    plan_start     DATE NULL,
    date_released  DATE NULL,
    date_scheduled DATE NULL,
    customer_hint  VARCHAR(64) NULL,           -- BatchSheetDemand CustomerCode
    bulk_code      VARCHAR(40) NULL,           -- MFPP only: bulk consumed
    bat_since      DATETIME NULL,              -- first time Insights saw it in BAT
    PRIMARY KEY (ordr),
    KEY idx_item (item_code),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_ticket_links (
    supply_ordr   INT NOT NULL,       -- MFBA
    demand_ordr   INT NOT NULL,       -- MFPP cut from it
    make_item_code VARCHAR(40) NOT NULL DEFAULT '',
    qty_make      DOUBLE NOT NULL DEFAULT 0,
    customer_code VARCHAR(64) NULL,
    PRIMARY KEY (supply_ordr, demand_ordr, make_item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Computed by the coverage engine each sync (rewritten, not swapped —
-- derived purely from the snapshot tables after they swap in).
CREATE TABLE IF NOT EXISTS or_coverage_results (
    ord_detail   INT NOT NULL,
    ordr         INT NOT NULL,
    status       ENUM('ready','waiting','short','short_discontinued') NOT NULL,
    allocations  TEXT NULL,       -- JSON [{type:'inventory'|'ticket', ref, qty, …}]
    covered_qty  DOUBLE NOT NULL DEFAULT 0,
    shortfall    DOUBLE NOT NULL DEFAULT 0,
    reason       TEXT NULL,       -- plain-English explanation
    PRIMARY KEY (ord_detail),
    KEY idx_ordr (ordr),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_production_suggestions (
    item_code    VARCHAR(40) NOT NULL,
    severity     ENUM('negative','below','near') NOT NULL,
    minimum_stock DOUBLE NULL,
    on_hand      DOUBLE NOT NULL DEFAULT 0,
    on_tickets   DOUBLE NOT NULL DEFAULT 0,
    open_demand  DOUBLE NOT NULL DEFAULT 0,
    projected    DOUBLE NOT NULL DEFAULT 0,
    shortfall    DOUBLE NOT NULL DEFAULT 0,
    suggested_qty DOUBLE NOT NULL DEFAULT 0,
    recipe_number VARCHAR(40) NULL,
    last_made    DATETIME NULL,
    PRIMARY KEY (item_code),
    KEY idx_sev (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Durable app data ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS or_order_priority (
    ordr     INT NOT NULL,
    position INT NOT NULL,
    PRIMARY KEY (ordr),
    KEY idx_pos (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_item_min_stock_history (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_code VARCHAR(40) NOT NULL,
    old_value DOUBLE NULL,
    new_value DOUBLE NULL,
    seen_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_item (item_code, seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_suggestion_marks (
    item_code  VARCHAR(40) NOT NULL,
    mark       ENUM('scheduled','skip') NOT NULL,
    skip_until DATE NULL,
    note       VARCHAR(255) NOT NULL DEFAULT '',
    user_id    INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order notes (Matt, 2026-10-29): keyed by CMS order number, kept forever,
-- searchable after the order completes.
CREATE TABLE IF NOT EXISTS or_order_notes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ordr       INT NOT NULL,
    note       TEXT NOT NULL,
    user_id    INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ordr (ordr),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tool settings
INSERT INTO settings (`key`, `value`) VALUES
    ('or.near_minimum_pct', '20'),
    ('or.badge_days', '14'),
    ('or.overdue_days', '0')
ON DUPLICATE KEY UPDATE `key` = `key`;

INSERT INTO schema_migrations (version) VALUES ('011_order_readiness')
ON DUPLICATE KEY UPDATE version = version;
