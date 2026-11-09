-- ============================================================
-- 022 — CMS write audit: the row is written before the send.
--
--  * A cms_write_audit row is now inserted before each request is sent,
--    with status 'sending', and updated with the outcome afterwards.
--    A row left at 'sending' is a request whose outcome was never
--    recorded.
--  * input_json: what the user entered for the operation, as JSON.
--  * cms_user: the CMS user the request was sent as.
--  * finished_at: when the outcome was recorded (NULL until then, and
--    on rows written before this migration).
-- ============================================================

ALTER TABLE cms_write_audit ADD COLUMN IF NOT EXISTS input_json MEDIUMTEXT NULL AFTER request_xml;
ALTER TABLE cms_write_audit ADD COLUMN IF NOT EXISTS cms_user VARCHAR(100) NULL AFTER user_id;
ALTER TABLE cms_write_audit ADD COLUMN IF NOT EXISTS finished_at TIMESTAMP NULL DEFAULT NULL AFTER created_at;

INSERT INTO schema_migrations (version) VALUES ('022_cms_write_audit_intent')
ON DUPLICATE KEY UPDATE version = version;
