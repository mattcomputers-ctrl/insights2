-- ============================================================
-- 003_scheduling.sql — Production Scheduling module
-- Mills (equipment), per-item scheduling config, permission seed.
-- Color order lives in `settings` under key sched.color_order
-- (seeded by code with the default ladder when absent).
-- ============================================================

CREATE TABLE IF NOT EXISTS sched_mills (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                 VARCHAR(64)  NOT NULL,
    lbs_per_hour         DOUBLE       NOT NULL DEFAULT 0,
    washup_like_minutes  DOUBLE       NOT NULL DEFAULT 0,   -- same color -> same color
    washup_next_minutes  DOUBLE       NOT NULL DEFAULT 0,   -- forward move down the ladder
    washup_deep_minutes  DOUBLE       NOT NULL DEFAULT 0,   -- backward move / restart
    hours_per_day        DOUBLE       NOT NULL DEFAULT 8,
    max_batch_lbs        DOUBLE       NOT NULL DEFAULT 0,   -- 0 = unlimited
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order           INT          NOT NULL DEFAULT 0,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-BULK-item scheduling config. Packs inherit from their bulk item.
CREATE TABLE IF NOT EXISTS sched_item_config (
    bulk_item_code  VARCHAR(30) NOT NULL,
    color           VARCHAR(20) NOT NULL,        -- one of the 12 ladder colors
    passes          INT         NOT NULL DEFAULT 1,
    batch_size_1    DOUBLE      NOT NULL,        -- primary standard batch (lbs)
    batch_size_2    DOUBLE      NULL,            -- optional second standard batch (lbs)
    updated_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bulk_item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant read on the new module to the default Standard Users group
INSERT IGNORE INTO group_permissions (group_id, page_key, access_level)
SELECT g.id, 'scheduling', 'read'
FROM permission_groups g
WHERE g.name = 'Standard Users';

INSERT INTO schema_migrations (version) VALUES ('003_scheduling')
ON DUPLICATE KEY UPDATE version = version;
