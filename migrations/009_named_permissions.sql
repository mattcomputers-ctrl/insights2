-- ============================================================
-- 009 — Named permissions, module visibility, default modules
-- (SPEC-order-readiness §2, step 3)
--
-- Replaces the one-page_key-per-module read/full model with named
-- permissions granted per group (union across a user's groups).
-- The old group_permissions table is kept (unused) for rollback;
-- a later migration will drop it.
-- ============================================================

-- ─── Users: first-login password change, rate limiting, landing ───
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS failed_logins INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS lockout_until DATETIME NULL,
    ADD COLUMN IF NOT EXISTS landing_module VARCHAR(64) NULL;

-- ─── Groups: priority order (lower = wins) + default module ───
ALTER TABLE permission_groups
    ADD COLUMN IF NOT EXISTS priority INT NOT NULL DEFAULT 100,
    ADD COLUMN IF NOT EXISTS default_module VARCHAR(64) NULL;

-- ─── Named permission grants ───
CREATE TABLE IF NOT EXISTS group_permission_grants (
    group_id   INT UNSIGNED NOT NULL,
    permission VARCHAR(64)  NOT NULL,
    PRIMARY KEY (group_id, permission),
    FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Per-group module visibility ───
-- A row with is_hidden=1 hides that module's existence from the group.
-- "Hidden beats granted" across a user's groups; admins always see all.
CREATE TABLE IF NOT EXISTS group_module_visibility (
    group_id   INT UNSIGNED NOT NULL,
    module_key VARCHAR(64)  NOT NULL,
    is_hidden  TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (group_id, module_key),
    FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Migrate old page_key grants → named permissions ───
INSERT IGNORE INTO group_permission_grants (group_id, permission)
SELECT group_id, 'margin.view' FROM group_permissions
WHERE page_key = 'margin_watchdog' AND access_level IN ('read','full');

INSERT IGNORE INTO group_permission_grants (group_id, permission)
SELECT group_id, 'margin.export' FROM group_permissions
WHERE page_key = 'margin_watchdog' AND access_level = 'full';

INSERT IGNORE INTO group_permission_grants (group_id, permission)
SELECT group_id, 'scheduling.view' FROM group_permissions
WHERE page_key = 'scheduling' AND access_level IN ('read','full');

INSERT IGNORE INTO group_permission_grants (group_id, permission)
SELECT group_id, 'scheduling.manage' FROM group_permissions
WHERE page_key = 'scheduling' AND access_level = 'full';

-- Anyone who had full access to a tab could already export from it.
INSERT IGNORE INTO group_permission_grants (group_id, permission)
SELECT DISTINCT group_id, 'export' FROM group_permissions
WHERE access_level = 'full';

-- ─── Seed the spec groups (idempotent) ───
UPDATE permission_groups SET priority = 10 WHERE name = 'Administrators';
UPDATE permission_groups SET priority = 90 WHERE name = 'Standard Users';

INSERT INTO permission_groups (name, description, is_admin, priority, default_module) VALUES
    ('Executives', 'Margin Watchdog and all Order Readiness views', 0, 20, 'margin_watchdog'),
    ('Production', 'Order Readiness, production plan marking, Scheduling', 0, 30, 'order_readiness'),
    ('Shipping',   'Order Readiness orders, items and batch tickets', 0, 40, 'order_readiness'),
    ('Sales',      'Order Readiness orders and items', 0, 50, 'order_readiness'),
    ('Read only',  'Order list, read only', 0, 60, 'order_readiness')
ON DUPLICATE KEY UPDATE priority = VALUES(priority);

-- ─── Seed grants per spec §2/§11 ───
-- (order_* / production.* keys are stored now; they become live when the
--  Order Readiness module registers and declares them.)
INSERT IGNORE INTO group_permission_grants (group_id, permission)
SELECT pg.id, p.perm FROM permission_groups pg
JOIN (
    SELECT 'Executives' AS gname, 'margin.view' AS perm
    UNION ALL SELECT 'Executives', 'margin.export'
    UNION ALL SELECT 'Executives', 'scheduling.view'
    UNION ALL SELECT 'Executives', 'orders.view'
    UNION ALL SELECT 'Executives', 'orders.items_view'
    UNION ALL SELECT 'Executives', 'orders.tickets_view'
    UNION ALL SELECT 'Executives', 'production.view'
    UNION ALL SELECT 'Executives', 'sync.manual'
    UNION ALL SELECT 'Executives', 'export'
    UNION ALL SELECT 'Production', 'scheduling.view'
    UNION ALL SELECT 'Production', 'scheduling.manage'
    UNION ALL SELECT 'Production', 'orders.view'
    UNION ALL SELECT 'Production', 'orders.items_view'
    UNION ALL SELECT 'Production', 'orders.tickets_view'
    UNION ALL SELECT 'Production', 'production.view'
    UNION ALL SELECT 'Production', 'production.mark'
    UNION ALL SELECT 'Production', 'sync.manual'
    UNION ALL SELECT 'Production', 'export'
    UNION ALL SELECT 'Shipping', 'orders.view'
    UNION ALL SELECT 'Shipping', 'orders.items_view'
    UNION ALL SELECT 'Shipping', 'orders.tickets_view'
    UNION ALL SELECT 'Shipping', 'sync.manual'
    UNION ALL SELECT 'Shipping', 'export'
    UNION ALL SELECT 'Sales', 'orders.view'
    UNION ALL SELECT 'Sales', 'orders.items_view'
    UNION ALL SELECT 'Sales', 'export'
    UNION ALL SELECT 'Read only', 'orders.view'
) p ON p.gname = pg.name;

-- ─── Seed visibility: Margin Watchdog hidden from non-exec groups ───
INSERT IGNORE INTO group_module_visibility (group_id, module_key, is_hidden)
SELECT id, 'margin_watchdog', 1 FROM permission_groups
WHERE name IN ('Production', 'Shipping', 'Sales', 'Read only');

INSERT INTO schema_migrations (version) VALUES ('009_named_permissions')
ON DUPLICATE KEY UPDATE version = version;
