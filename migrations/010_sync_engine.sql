-- ============================================================
-- 010 — Shared CMS sync engine (SPEC-order-readiness §3, step 4)
-- ============================================================

CREATE TABLE IF NOT EXISTS sync_runs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trigger_type  ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    triggered_by  INT UNSIGNED NULL,                -- user id for manual runs
    override_blackout TINYINT(1) NOT NULL DEFAULT 0,
    status        ENUM('queued','running','success','failed','aborted') NOT NULL DEFAULT 'queued',
    current_step  VARCHAR(160) NULL,
    step_num      INT NULL,
    step_total    INT NULL,
    started_at    DATETIME NULL,
    finished_at   DATETIME NULL,
    error         TEXT NULL,
    stats         MEDIUMTEXT NULL,                  -- JSON: row counts, per-step durations, slowest query
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync/CMS settings live in the existing `settings` key/value table:
--   sync.auto_enabled      '1'/'0'        (default on)
--   sync.interval_minutes  5..240         (default 15)
--   sync.timeout_seconds   overall run timeout (default 300)
--   sync.blackouts         JSON [{days:[1..7], start:'23:30', end:'02:00'}, …]
--   sync.last_success_at   'Y-m-d H:i:s' of the newest good snapshot
--   cms.kill_switch        '1' blocks ALL CMS access platform-wide
INSERT INTO settings (`key`, `value`) VALUES
    ('sync.auto_enabled', '1'),
    ('sync.interval_minutes', '15'),
    ('sync.timeout_seconds', '300')
ON DUPLICATE KEY UPDATE `key` = `key`;

INSERT INTO schema_migrations (version) VALUES ('010_sync_engine')
ON DUPLICATE KEY UPDATE version = version;
