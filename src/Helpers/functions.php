<?php

/**
 * Global helper functions for Precision Ink Insights.
 * Intentionally not namespaced for convenient use in views/controllers.
 */

if (defined('PII_HELPERS_LOADED')) {
    return;
}
define('PII_HELPERS_LOADED', true);

/* ------------------------------------------------------------------
 *  HTTP helpers
 * ----------------------------------------------------------------*/

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function back(): never
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '/';
    redirect($referer);
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/* ------------------------------------------------------------------
 *  Output / escaping
 * ----------------------------------------------------------------*/

function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------
 *  View rendering
 * ----------------------------------------------------------------*/

function view(string $__template, array $__data = []): void
{
    // "module_key::template" renders from that module's own Views dir
    // (Module::viewsPath()); plain names render from the shared src/Views.
    if (str_contains($__template, '::')) {
        [$__moduleKey, $__template] = explode('::', $__template, 2);
        $__module = \PII\Core\App::modules()->get($__moduleKey);
        $__dir    = $__module?->viewsPath();
        if ($__dir === null) {
            throw new \RuntimeException("No views directory for module [{$__moduleKey}].");
        }
        $__file = rtrim($__dir, '/\\') . '/' . str_replace('.', '/', $__template) . '.php';
    } else {
        $__template = str_replace('.', '/', $__template);
        $__file = dirname(__DIR__) . '/Views/' . $__template . '.php';
    }

    if (!file_exists($__file)) {
        throw new \RuntimeException("View [{$__template}] not found at [{$__file}].");
    }

    extract($__data, EXTR_SKIP);
    include $__file;
}

/* ------------------------------------------------------------------
 *  Form helpers
 * ----------------------------------------------------------------*/

function old(string $key, string $default = ''): string
{
    if (isset($_POST[$key])) {
        return (string) $_POST[$key];
    }
    if (isset($_SESSION['_flash']['_old_input'][$key])) {
        return (string) $_SESSION['_flash']['_old_input'][$key];
    }
    return $default;
}

function csrf_field(): string
{
    return \PII\Core\CSRF::field();
}

/**
 * The Insights logo: a colorful eye (inline SVG so it's crisp at any size
 * and needs no extra request). $size is the rendered box in px.
 */
function insights_logo(int $size = 34): string
{
    $id = 'iris' . $size;   // unique gradient id per rendered size
    return <<<SVG
<svg width="{$size}" height="{$size}" viewBox="0 0 48 48" role="img" aria-label="Insights" style="display:block;">
  <defs>
    <linearGradient id="{$id}" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#2563eb"/>
      <stop offset="0.45" stop-color="#8b5cf6"/>
      <stop offset="0.75" stop-color="#ec4899"/>
      <stop offset="1" stop-color="#f59e0b"/>
    </linearGradient>
  </defs>
  <path d="M24 9 C37 9 45.5 24 45.5 24 C45.5 24 37 39 24 39 C11 39 2.5 24 2.5 24 C2.5 24 11 9 24 9 Z"
        fill="#ffffff" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round"/>
  <circle cx="24" cy="24" r="9.5" fill="url(#{$id})"/>
  <circle cx="24" cy="24" r="4.2" fill="#101828"/>
  <circle cx="27" cy="20.8" r="2" fill="#ffffff"/>
</svg>
SVG;
}

/**
 * Cache-busted asset URL: /js/app.js → /js/app.js?v=<mtime>. Browsers may
 * cache CSS/JS across deploys; the version flips automatically whenever
 * the file changes on disk (every update), so users never run stale JS.
 */
function asset_url(string $path): string
{
    static $cache = [];
    if (!isset($cache[$path])) {
        $file = \PII\Core\App::basePath() . '/public/' . ltrim($path, '/');
        $v = @filemtime($file) ?: 0;
        $cache[$path] = '/' . ltrim($path, '/') . ($v ? '?v=' . $v : '');
    }
    return $cache[$path];
}

function csrf_token(): string
{
    return \PII\Core\CSRF::token();
}

/**
 * App display name. Reads the `settings` table override first (set via
 * /admin/settings), falls back to config['app.name']. Cached per-request
 * so repeated calls in views don't hit the DB.
 */
function app_name(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $row = \PII\Core\Database::getInstance()->fetch(
            "SELECT `value` FROM settings WHERE `key` = 'app.name'"
        );
        if ($row && trim((string) ($row['value'] ?? '')) !== '') {
            return $cached = trim($row['value']);
        }
    } catch (\Throwable $e) {
        // DB unavailable (e.g. mid-install) — fall through to config
    }
    return $cached = (string) \PII\Core\App::config('app.name', 'Precision Ink Insights');
}

/* ------------------------------------------------------------------
 *  Flash messages
 * ----------------------------------------------------------------*/

function flash_messages(): string
{
    $types = ['success', 'error', 'warning', 'info'];
    $html  = '';

    foreach ($types as $type) {
        if (isset($_SESSION['_flash'][$type])) {
            $message = $_SESSION['_flash'][$type];
            $cls     = ($type === 'error') ? 'danger' : $type;
            // Success flashes render as brief toasts (app.js); problems stay inline.
            $toast   = $type === 'success' ? ' data-toast="1"' : '';
            $html   .= '<div class="alert alert-' . $cls . '"' . $toast . ' role="alert">';
            $html   .= e($message);
            $html   .= '<button type="button" class="alert-close" aria-label="Close">&times;</button>';
            $html   .= '</div>';
            unset($_SESSION['_flash'][$type]);
        }
    }

    unset($_SESSION['_flash']['_old_input']);
    return $html;
}

/* ------------------------------------------------------------------
 *  Authenticated user helpers
 * ----------------------------------------------------------------*/

function current_user(): ?array
{
    return $_SESSION['_user'] ?? null;
}

function current_user_id(): ?int
{
    return isset($_SESSION['_user']['id']) ? (int) $_SESSION['_user']['id'] : null;
}

function is_admin(): bool
{
    return !empty($_SESSION['_user']['is_admin']);
}

/* ------------------------------------------------------------------
 *  Permissions
 * ----------------------------------------------------------------*/

/** Current user holds ALL of the given permissions. False when logged out. */
function user_can(string ...$permissions): bool
{
    $userId = current_user_id();
    if ($userId === null) {
        return false;
    }
    return \PII\Services\PermissionService::canAll($userId, ...$permissions);
}

/** Current user may see this module (not hidden, has its view permission). */
function user_can_see_module(string $moduleKey): bool
{
    $userId = current_user_id();
    if ($userId === null) {
        return false;
    }
    $module = \PII\Core\App::modules()->get($moduleKey);
    return $module !== null
        && \PII\Services\PermissionService::canSeeModule($userId, $module);
}

/** Where the current user lands after login / clicking the logo. */
function landing_path(): string
{
    $userId = current_user_id();
    return $userId === null ? '/' : \PII\Services\PermissionService::landingPath($userId);
}

/**
 * Render the plain 404 page and stop. Used both for genuinely unknown
 * URLs and for anything the user lacks permission for — the response
 * never reveals that a page exists (SPEC-order-readiness §2.1).
 */
function render_not_found(): never
{
    http_response_code(404);
    $viewFile = dirname(__DIR__) . '/Views/errors/404.php';
    if (file_exists($viewFile)) {
        include $viewFile;
    } else {
        echo '<h1>404 — Not Found</h1>';
    }
    exit;
}

/**
 * 404 unless the current user holds ALL given permissions, logging the
 * denied attempt. Controllers use this for sub-page actions (exports,
 * settings writes) that the module-level middleware check doesn't cover.
 */
function require_permission(string ...$permissions): void
{
    if (user_can(...$permissions)) {
        return;
    }
    try {
        \PII\Core\Database::getInstance()->insert('audit_log', [
            'user_id'     => current_user_id(),
            'entity_type' => 'access',
            'entity_id'   => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
            'action'      => 'denied',
            'details'     => json_encode(['missing' => array_values($permissions)]),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        // auditing must never turn a denial into a 500
    }
    render_not_found();
}

/**
 * A per-page "Sync now" button (same action as the status-bar one; wired
 * in app.js). Renders nothing without the sync.manual permission —
 * hidden, not disabled.
 */
function sync_now_button(): string
{
    if (!user_can('sync.manual')) {
        return '';
    }
    return '<button type="button" class="btn btn-sm sync-now-page" title="Refresh data from CMS now">⟳ Sync now</button>';
}

/**
 * An item code with the cross-module right-click menu (Matt, 2026-11-04):
 * right-click → view active recipe (in-page window), find in Inventory,
 * coverage by item, Need to Order — entries permission-gated in the
 * layout's menu config; the markup itself is inert without it.
 */
/**
 * Escape LIKE wildcards in user input for CMS queries. The escape char is
 * '!' (pair with ESCAPE '!'), NEVER backslash: PDO's emulated-prepare
 * parser under pdo_dblib treats \' as an escaped quote and then miscounts
 * the ? placeholders ("Invalid parameter number").
 */
function cms_like_escape(string $s): string
{
    return str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $s);
}

function item_ctx(string $code, ?string $label = null, bool $strong = true): string
{
    if ($code === '') {
        return '';
    }
    $text = $label !== null && $label !== '' ? $label : $code;
    $inner = $strong ? '<strong>' . e($text) . '</strong>' : e($text);
    return '<span class="item-ctx" data-item="' . e($code) . '" title="Right-click for item options">' . $inner . '</span>';
}

/** Lot numbers get their own right-click menu (QC, COA, batch ticket). */
function lot_ctx(string|int|null $lot): string
{
    $lot = trim((string) $lot);
    if ($lot === '') {
        return '';
    }
    return '<span class="lot-ctx tabular" data-lot="' . e($lot) . '" title="Right-click for lot options">' . e($lot) . '</span>';
}

/* ------------------------------------------------------------------
 *  Number / money formatting
 * ----------------------------------------------------------------*/

function fmt_money(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    return '$' . number_format((float) $n, $decimals, '.', ',');
}

function fmt_number(float|int|null $n, int $decimals = 0): string
{
    if ($n === null) return '—';
    return number_format((float) $n, $decimals, '.', ',');
}

function fmt_pct(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    return number_format((float) $n, $decimals, '.', ',') . '%';
}

/**
 * Percentage-point delta — the literal subtraction of two percentages
 * (33% − 30% = +3, NOT a relative percent change). Displayed with a "%"
 * suffix to match exec preference; context (e.g. "X% of Y → 33% → 30%")
 * makes it unambiguous that this is a point delta, not a percent change.
 */
function fmt_pp(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    $sign = $n > 0 ? '+' : '';
    return $sign . number_format((float) $n, $decimals, '.', ',') . '%';
}

function fmt_signed_money(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    $sign = $n > 0 ? '+' : ($n < 0 ? '-' : '');
    return $sign . '$' . number_format(abs((float) $n), $decimals, '.', ',');
}

function fmt_signed_pct(float|int|null $n, int $decimals = 2): string
{
    if ($n === null) return '—';
    $sign = $n > 0 ? '+' : '';
    return $sign . number_format((float) $n, $decimals, '.', ',') . '%';
}

function fmt_date(?string $date, string $format = 'm/d/Y'): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts !== false ? date($format, $ts) : '';
}

/**
 * Calculate percentage change from baseline to comparison.
 * Returns null when baseline is zero (cannot compute %).
 */
function pct_change(float|int|null $baseline, float|int|null $comparison): ?float
{
    $b = (float) ($baseline ?? 0);
    $c = (float) ($comparison ?? 0);
    if ($b == 0.0) return null;
    return (($c - $b) / abs($b)) * 100.0;
}

/**
 * Validate a YYYY-MM-DD date string.
 */
function valid_date(string $s): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    $parts = explode('-', $s);
    return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
}
