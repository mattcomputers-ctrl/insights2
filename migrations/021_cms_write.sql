-- ============================================================
-- 021 — CMS write foundation (Insights 2).
--
--  * users.cms_user: the CMS identity (e.g. pic\jsmith) a write is sent
--    as. Empty = that user cannot write to CMS.
--  * cms_write_audit: one row per request the writer sent or dry-ran:
--    who, which operation, the exact request XML, the CMS Log id and
--    result that came back.
--  * settings: live sending is OFF by default.
-- ============================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS cms_user VARCHAR(100) NULL AFTER display_name;

CREATE TABLE IF NOT EXISTS cms_write_audit (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id       CHAR(16)      NOT NULL,
    user_id      INT UNSIGNED  NULL,
    operation    VARCHAR(80)   NOT NULL,
    stage_no     SMALLINT      NOT NULL DEFAULT 1,
    stage_name   VARCHAR(190)  NOT NULL DEFAULT '',
    mode         VARCHAR(10)   NOT NULL,
    cms_action   VARCHAR(120)  NULL,
    request_xml  MEDIUMTEXT    NULL,
    log_id       INT           NULL,
    result_code  INT           NULL,
    result_text  TEXT          NULL,
    status       VARCHAR(12)   NOT NULL,
    message      VARCHAR(1000) NOT NULL DEFAULT '',
    entity_type  VARCHAR(40)   NULL,
    entity_id    VARCHAR(60)   NULL,
    ip_address   VARCHAR(45)   NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cwa_run (run_id),
    KEY idx_cwa_user (user_id, created_at),
    KEY idx_cwa_entity (entity_type, entity_id),
    KEY idx_cwa_log (log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
    ('cms_write.live_enabled', '0'),
    ('cms_write.workstation', 'INSIGHTS'),
    ('cms_write.program_mode', 'insights')
ON DUPLICATE KEY UPDATE `key` = `key`;

INSERT INTO schema_migrations (version) VALUES ('021_cms_write')
ON DUPLICATE KEY UPDATE version = version;
