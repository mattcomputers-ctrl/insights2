-- ============================================================
-- 014 — Batch-ticket materials check (Matt, 2026-10-29):
-- "check to see if we have the inventory required (what's used in
-- the batch tickets) to make the batches needed to complete the orders."
--
-- or_ticket_materials: SNAPSHOT of each open ticket's UI (material)
-- lines, summed per item (CMS lists the same material twice on some
-- tickets). Rebuilt in staging and swapped atomically.
--
-- or_ticket_readiness: computed per sync by TicketMaterialsEngine —
-- can this ticket actually be made with the materials on hand?
-- ============================================================

CREATE TABLE IF NOT EXISTS or_ticket_materials (
    ordr       INT NOT NULL,                  -- the ticket (MFBA or MFPP)
    item_code  VARCHAR(40) NOT NULL,          -- material consumed
    qty_reqd   DOUBLE NOT NULL DEFAULT 0,     -- remaining to issue (summed)
    unit       VARCHAR(16) NOT NULL DEFAULT '',
    PRIMARY KEY (ordr, item_code),
    KEY idx_item (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS or_ticket_readiness (
    ordr       INT NOT NULL,
    status     ENUM('ok','short') NOT NULL,
    shortages  TEXT NULL,                     -- JSON [{item_code,need,short,unit}]
    from_mfba  DOUBLE NOT NULL DEFAULT 0,     -- MFPP: bulk taken from its linked batch
    mfba_short TINYINT(1) NOT NULL DEFAULT 0, -- MFPP: its linked MFBA is itself short
    PRIMARY KEY (ordr),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('014_ticket_materials')
ON DUPLICATE KEY UPDATE version = version;
