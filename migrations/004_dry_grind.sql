-- ============================================================
-- 004_dry_grind.sql — Derived mill passes via dry-grind triggers
--
-- Passes are no longer configured per item. Instead:
--   - a formula is a DRY GRIND if any raw material in its DIRECT
--     recipe (Context='UI') matches a trigger pattern below
--   - dry grind => global passes setting (settings key
--     sched.dry_grind_passes); otherwise 1 pass
--   - intermediates that were themselves dry grinds do NOT
--     propagate (they were ground out when the intermediate
--     was made) — only direct formula lines are inspected
-- ============================================================

CREATE TABLE IF NOT EXISTS sched_dry_grind_triggers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pattern    VARCHAR(30)  NOT NULL,   -- exact code or LIKE-style, e.g. 'PGK%'
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_pattern (pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Passes column is superseded by derivation
ALTER TABLE sched_item_config DROP COLUMN passes;

INSERT INTO schema_migrations (version) VALUES ('004_dry_grind')
ON DUPLICATE KEY UPDATE version = version;
