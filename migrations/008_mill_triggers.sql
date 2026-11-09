-- ============================================================
-- 008_mill_triggers.sql — Non-dry-grind milling triggers
--
-- Second trigger list: raw materials that require milling but
-- NOT a dry grind (single pass). An item is scheduled at all
-- only when its DIRECT formula (Context='UI') matches a pattern
-- from this list and/or the dry-grind list. Formulas matching
-- neither are blends of pre-ground materials — never milled,
-- never on the schedule.
-- ============================================================

CREATE TABLE IF NOT EXISTS sched_mill_triggers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pattern    VARCHAR(30)  NOT NULL,   -- exact code or LIKE-style, e.g. 'FLR%'
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_pattern (pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('008_mill_triggers')
ON DUPLICATE KEY UPDATE version = version;
