<?php
/**
 * App shell (SPEC §4): topbar + always-visible sync status bar + side nav.
 *
 * @var string $pageTitle
 * @var string $bodyContent  pre-rendered HTML
 * @var string|null $activeKey  module key currently active (null on Home / non-module pages)
 */
$appName = app_name();
$user    = $_SESSION['_user'] ?? null;
// Hidden, not disabled: the nav only ever lists what this user may open.
$modules = $user
    ? \PII\Services\PermissionService::visibleModules((int) $user['id'])
    : [];
$uri     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$active  = $activeKey ?? null;
$homeUrl = $user ? landing_path() : '/';

// Per-user presentation preferences
$colorsOn = true;
$theme    = 'light';
if ($user) {
    $prefs = \PII\Core\Database::getInstance()->fetchAll(
        "SELECT `key`, `value` FROM user_preferences WHERE user_id = ? AND `key` IN ('colors_enabled','theme')",
        [(int) $user['id']]
    );
    foreach ($prefs as $p) {
        if ($p['key'] === 'colors_enabled' && $p['value'] === '0') $colorsOn = false;
        if ($p['key'] === 'theme' && $p['value'] === 'dark')       $theme = 'dark';
    }
}
$bodyClasses = trim(($colorsOn ? '' : 'colors-off ') . ($theme === 'dark' ? 'theme-dark ' : '') . ($user ? '' : 'no-nav'));
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? $appName) ?> — <?= e($appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(asset_url('favicon.svg')) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="<?= e($bodyClasses) ?>">
    <header class="topbar">
        <?php if ($user): ?>
            <button type="button" id="nav-toggle" class="nav-toggle" aria-label="Toggle navigation">☰</button>
        <?php endif; ?>
        <a href="<?= e($homeUrl) ?>" class="brand">
            <span class="brand-mark"><?= insights_logo(34) ?></span>
            <span class="brand-name"><?= e($appName) ?></span>
        </a>
        <?php if ($user): ?>
        <div class="user-menu">
            <button type="button" class="user-menu-btn">
                <span class="user-name"><?= e($user['display_name'] ?: $user['username']) ?></span>
                <?php if (!empty($user['is_admin'])): ?>
                    <span class="user-badge user-badge-admin">Admin</span>
                <?php endif; ?>
                <span aria-hidden="true">▾</span>
            </button>
            <div class="user-menu-panel">
                <?php if (user_can_see_module('margin_watchdog')): ?>
                    <a href="/preferences">⚙ Preferences</a>
                <?php endif; ?>
                <a href="/password">🔑 Change password</a>
                <form method="POST" action="/theme" style="margin:0;">
                    <?= csrf_field() ?>
                    <button type="submit"><?= $theme === 'dark' ? '☀ Switch to light mode' : '🌙 Switch to dark mode' ?></button>
                </form>
                <div class="user-menu-divider"></div>
                <a href="/logout">↩ Log out</a>
            </div>
        </div>
        <?php endif; ?>
    </header>

    <?php if ($user): ?>
    <div id="sync-bar" class="sync-bar sync-bar-paused" role="status" aria-live="polite">
        <span class="sync-icon">⟳</span>
        <span class="sync-text">Checking data status…</span>
        <span class="sync-progress" style="display:none;"><span style="width:0%"></span></span>
        <button type="button" id="sync-try-btn" class="btn btn-sm" style="display:none;">Try again</button>
        <button type="button" id="sync-now-btn" class="btn btn-sm" style="display:none;">⟳ Sync now</button>
    </div>

    <div class="nav-backdrop"></div>
    <nav class="side-nav" aria-label="Main navigation">
        <a href="/" class="<?= $uri === '/' ? 'active' : '' ?>">
            <span class="nav-icon">⌂</span><span class="nav-label">Home</span>
        </a>
        <?php
        // Hierarchical menu (Matt, 2026-11-04): Sales and Purchasing
        // sections; QC under Batch Tickets (COA under QC); Recipes under
        // Items. A module someone may see always shows, parent or not.
        $byKey = [];
        foreach ($modules as $m) {
            $byKey[$m->key()] = $m;
        }
        $navLink = function (string $key, int $level = 0) use ($byKey, $uri, $active): void {
            $module = $byKey[$key] ?? null;
            if ($module === null) {
                return;   // hidden or not granted for this user
            }
            $base = $module->basePath();
            $isActive = ($active === $key) || str_starts_with($uri, $base);
            $cls = trim(($isActive ? 'active ' : '') . ($level === 1 ? 'nav-sub' : ($level >= 2 ? 'nav-sub2' : '')));
            ?>
            <a href="<?= e($base) ?>" class="<?= e($cls) ?>" title="<?= e($module->description()) ?>">
                <span class="nav-icon"><?= $module->icon() !== '' ? $module->icon() : '▪' ?></span>
                <span class="nav-label"><?= e($module->name()) ?></span>
            </a>
            <?php
        };
        $sectionVisible = fn(array $keys): bool => (bool) array_filter($keys, fn($k) => isset($byKey[$k]));
        ?>

        <?php if ($sectionVisible(['items', 'recipes', 'inventory'])): ?>
            <div class="nav-section nav-sec-toggle" data-sec="items" role="button" tabindex="0">Items<span class="nav-chev">▾</span></div>
            <div class="nav-group" data-sec="items">
            <?php
            $navLink('items');
            $navLink('recipes', isset($byKey['items']) ? 1 : 0);
            $navLink('inventory');
            ?>
            </div>
        <?php endif; ?>

        <?php if ($sectionVisible(['sales_orders', 'order_readiness', 'shipping', 'margin_watchdog'])): ?>
            <div class="nav-section nav-sec-toggle" data-sec="sales" role="button" tabindex="0">Sales<span class="nav-chev">▾</span></div>
            <div class="nav-group" data-sec="sales">
            <?php $navLink('sales_orders'); $navLink('order_readiness'); $navLink('shipping'); $navLink('margin_watchdog'); ?>
            </div>
        <?php endif; ?>

        <?php if ($sectionVisible(['purchase_orders', 'need_to_order'])): ?>
            <div class="nav-section nav-sec-toggle" data-sec="purchasing" role="button" tabindex="0">Purchasing<span class="nav-chev">▾</span></div>
            <div class="nav-group" data-sec="purchasing">
            <?php $navLink('purchase_orders'); $navLink('need_to_order'); ?>
            </div>
        <?php endif; ?>

        <?php if ($sectionVisible(['batch_tickets', 'qc', 'scheduling'])): ?>
            <div class="nav-section nav-sec-toggle" data-sec="production" role="button" tabindex="0">Production<span class="nav-chev">▾</span></div>
            <div class="nav-group" data-sec="production">
            <?php
            $navLink('batch_tickets');
            $navLink('qc', isset($byKey['batch_tickets']) ? 1 : 0);
            if (isset($byKey['qc']) && user_can('qc.coa')) {
                $lvl = isset($byKey['batch_tickets']) ? 2 : 1;
                ?>
                <a href="/qc/coa" class="<?= trim((str_starts_with($uri, '/qc/coa') ? 'active ' : '') . ($lvl === 2 ? 'nav-sub2' : 'nav-sub')) ?>"
                   title="Generate a Certificate of Analysis by lot number">
                    <span class="nav-icon">📜</span><span class="nav-label">COA</span>
                </a>
                <?php
            }
            $navLink('scheduling');
            ?>
            </div>
        <?php endif; ?>

        <?php
        // Safety net: a future module not placed above still gets a link.
        $placed = ['sales_orders', 'order_readiness', 'shipping', 'margin_watchdog', 'purchase_orders',
                   'need_to_order', 'batch_tickets', 'qc', 'scheduling', 'inventory', 'items', 'recipes'];
        foreach ($byKey as $k => $m) {
            if (!in_array($k, $placed, true)) {
                $navLink($k);
            }
        }
        ?>
        <?php if (user_can_see_module('margin_watchdog')): ?>
            <a href="/presets" class="<?= str_starts_with($uri, '/presets') ? 'active' : '' ?>">
                <span class="nav-icon">📌</span><span class="nav-label">Date presets</span>
            </a>
        <?php endif; ?>

        <?php
        $adminLinks = [];
        if (user_can('admin.users'))  { $adminLinks['/admin/users'] = ['👥', 'Users & groups']; }
        if (user_can('admin.cms'))    { $adminLinks['/admin/cms'] = ['🔌', 'CMS & Sync']; }
        if (user_can('admin.system')) { $adminLinks['/admin/settings'] = ['🛠', 'System settings']; }
        if (user_can('admin.network')) { $adminLinks['/admin/network'] = ['🌐', 'Network']; }
        if (user_can('admin.system')) { $adminLinks['/admin/audit-log'] = ['📜', 'Audit log']; }
        ?>
        <?php if ($adminLinks !== []): ?>
            <div class="nav-section nav-sec-toggle" data-sec="admin" role="button" tabindex="0">Administration<span class="nav-chev">▾</span></div>
            <div class="nav-group" data-sec="admin">
            <?php foreach ($adminLinks as $href => [$icon, $label]): ?>
                <a href="<?= e($href) ?>" class="<?= str_starts_with($uri, $href) ? 'active' : '' ?>">
                    <span class="nav-icon"><?= $icon ?></span><span class="nav-label"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <main class="content">
        <?= flash_messages() ?>
        <?php if (!empty($pageTitle)): ?>
            <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <?php endif; ?>
        <?= $bodyContent ?? '' ?>
    </main>

    <!-- In-page recipe window + item right-click menu (app.js) -->
    <div id="recipe-modal" class="modal-overlay" style="display:none;">
        <div class="modal-box">
            <div class="modal-head">
                <button type="button" class="btn btn-sm" id="recipe-back" style="display:none;">← Back</button>
                <span style="flex:1;"></span>
                <button type="button" class="btn btn-sm" id="recipe-close">✕ Close</button>
            </div>
            <div class="modal-body" id="recipe-body"></div>
        </div>
    </div>
    <?php
    // Cross-module links for the item right-click menu — permission-gated
    // server-side (hidden modules stay hidden, SPEC §2.1).
    $itemMenu = [];
    if (user_can('recipes.view') && user_can_see_module('recipes')) {
        $itemMenu[] = ['label' => '⚗ View active recipe', 'action' => 'recipe'];
        $itemMenu[] = ['label' => '🔍 Find in Recipes', 'href' => '/recipes?q={code}'];
    }
    if (user_can('inventory.view') && user_can_see_module('inventory')) {
        $itemMenu[] = ['label' => '🗃 Find in Inventory', 'href' => '/inventory?q={code}'];
    }
    if (user_can('items.view') && user_can_see_module('items')) {
        $itemMenu[] = ['label' => '🏷 Item details', 'href' => '/items/view?code={code}'];
    }
    if (user_can('orders.items_view') && user_can_see_module('order_readiness')) {
        $itemMenu[] = ['label' => '📦 Coverage by item', 'href' => '/orders/by-item?q={code}'];
    }
    if (user_can('purchasing.view') && user_can_see_module('need_to_order')) {
        $itemMenu[] = ['label' => '🛒 Need to Order', 'href' => '/need-to-order?q={code}'];
    }
    if (user_can('sales.view') && user_can_see_module('sales_orders')) {
        $itemMenu[] = ['label' => '🧾 Find in Sales Orders', 'href' => '/sales-orders?tab=all&q={code}'];
    }
    if (user_can('purchasing.view') && user_can_see_module('purchase_orders')) {
        $itemMenu[] = ['label' => '🚛 Find in Purchase Orders', 'href' => '/purchase-orders?tab=all&q={code}'];
    }
    if (user_can('orders.tickets_view') && user_can_see_module('batch_tickets')) {
        $itemMenu[] = ['label' => '🏭 Find in Batch Tickets', 'href' => '/batch-tickets?tab=all&q={code}'];
    }
    if (user_can('qc.view') && user_can_see_module('qc')) {
        $itemMenu[] = ['label' => '🧪 QC results', 'href' => '/qc?q={code}'];
    }

    // …and lot numbers get their own menu (COA, QC, the ticket that made it).
    $lotMenu = [];
    if (user_can('qc.coa') && user_can_see_module('qc')) {
        $lotMenu[] = ['label' => '📜 Generate COA', 'href' => '/qc/coa?lot={lot}'];
    }
    if (user_can('qc.view') && user_can_see_module('qc')) {
        $lotMenu[] = ['label' => '🧪 QC results for lot', 'href' => '/qc?q={lot}'];
    }
    if (user_can('orders.tickets_view') && user_can_see_module('batch_tickets')) {
        $lotMenu[] = ['label' => '🏭 Find in Batch Tickets', 'href' => '/batch-tickets?tab=all&q={lot}'];
    }
    if (user_can('inventory.view') && user_can_see_module('inventory')) {
        $lotMenu[] = ['label' => '🗃 Find in Inventory', 'href' => '/inventory?q={lot}'];
    }
    ?>
    <script>window.PII_ITEM_MENU = <?= json_encode($itemMenu, JSON_UNESCAPED_SLASHES) ?>;
window.PII_LOT_MENU = <?= json_encode($lotMenu, JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
