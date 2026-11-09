-- ============================================================
-- Precision Ink Insights — Initial schema
-- Local MySQL database for auth, preferences, presets, audit.
-- The CMS (MSSQL) connection is read-only; we never write back to it.
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Users ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(64)  NOT NULL,
    email           VARCHAR(255) NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(128) NOT NULL DEFAULT '',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login      DATETIME     NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username),
    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Permission groups ────────────────────────────────────────
-- Even though Margin Watchdog (the only initial module) is open
-- to all logged-in users, the group/permission scaffolding is
-- present from day one so future tabs can be restricted.
CREATE TABLE IF NOT EXISTS permission_groups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_admin    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_group_members (
    user_id  INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, group_id),
    FOREIGN KEY (user_id)  REFERENCES users(id)             ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_permissions (
    group_id     INT UNSIGNED NOT NULL,
    page_key     VARCHAR(64)  NOT NULL,
    access_level ENUM('none','read','full') NOT NULL DEFAULT 'none',
    PRIMARY KEY (group_id, page_key),
    FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── App-wide settings (key/value) ────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(128) NOT NULL,
    `value` TEXT         NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── User preferences (per-user threshold/colour config) ─────
-- One row per (user, key). Stores JSON or scalar values.
CREATE TABLE IF NOT EXISTS user_preferences (
    user_id    INT UNSIGNED NOT NULL,
    `key`      VARCHAR(128) NOT NULL,
    `value`    TEXT         NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, `key`),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Saved date range presets (per user) ─────────────────────
CREATE TABLE IF NOT EXISTS date_range_presets (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(128) NOT NULL,
    baseline_start  DATE NOT NULL,
    baseline_end    DATE NOT NULL,
    comparison_start DATE NOT NULL,
    comparison_end   DATE NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Audit log (who pulled what report, login events) ────────
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    entity_type VARCHAR(64)  NOT NULL,
    entity_id   VARCHAR(128) NULL,
    action      VARCHAR(64)  NOT NULL,
    details     TEXT         NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_entity (entity_type, entity_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Seed default permission group: Administrators ───────────
INSERT INTO permission_groups (name, description, is_admin)
VALUES ('Administrators', 'Full access to all areas including user management', 1)
ON DUPLICATE KEY UPDATE is_admin = 1;

-- ─── Seed default permission group: Standard Users ───────────
-- Read access to all module tabs by default.
INSERT INTO permission_groups (name, description, is_admin)
VALUES ('Standard Users', 'Default group: read access to all dashboard tabs', 0)
ON DUPLICATE KEY UPDATE is_admin = 0;

-- Mark this migration applied
INSERT INTO schema_migrations (version) VALUES ('001_create_schema')
ON DUPLICATE KEY UPDATE version = version;
