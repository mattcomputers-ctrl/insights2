# Current state of Precision Ink Insights

As of commit `0edeea0`, 2026-10-29. Written per SPEC-order-readiness §0 step 2, before any
Part A work begins. This is a description of what exists **today**, not what the spec wants.

## Stack

| Layer | Today | Spec target |
|---|---|---|
| Language | PHP ≥ 8.1, no framework, PSR-4 `PII\` → `src/` | same (PHP 8.x) |
| Web server | **Apache 2 + mod_php** (vhost from installer, `.htaccess` rewrite) | spec §5 says nginx + PHP-FPM |
| App database | MariaDB (local), PDO | same |
| CMS access | PDO: tries `sqlsrv` first (Windows dev), falls back to **`dblib` (FreeTDS)** — that's what production uses (`php-sybase` package) | spec wants ODBC Driver 18 + `pdo_sqlsrv` |
| Front-end | Server-rendered PHP views, one `public/css/app.css` (dark theme), one `public/js/app.js`, **no Alpine.js**, no build step | Alpine.js, light default + dark toggle |
| Composer deps | `phpoffice/phpspreadsheet ^2.0` only | — |
| OS | Ubuntu Server (installed via `install-ubuntu.sh`); server at `insights` host | fresh target is 24.04 |

There is **no PHPUnit** and no `tests/` harness yet — only the Margin Watchdog regression
fixtures under `tests/fixtures/margin-watchdog/` (captured 2026-10-29, see its README).

## Directory layout

```
config/config.example.php     → copied to config/config.php by installer (gitignored)
cron/snapshot-inventory.php   → nightly inventory snapshot (3:00 AM, www-data crontab)
installer/logrotate.conf      → /etc/logrotate.d/precision-ink-insights
migrations/001…008 + migrate.php (idempotent, tracked in schema_migrations)
public/index.php              → front controller (Apache DocumentRoot = public/)
src/Core/      App, Router, Session, CSRF, Database (MySQL), CMSDatabase (MSSQL),
               Module (abstract), ModuleRegistry
src/Controllers/  Auth, Dashboard, Admin, Preferences, Preset
src/Middleware/AuthMiddleware.php
src/Models/User.php
src/Services/  PermissionService, DashboardService
src/Modules/MarginWatchdog/   Module, Controller, ShipmentService, MetricsCalculator,
                              Thresholds, ExcelExporter
src/Modules/Scheduling/       Module, Controller, SchedulingDataService, ScheduleEngine,
                              ScheduleExporter
src/Views/     per-area PHP templates; layouts/main.php is the shell
src/Helpers/   functions.php (global helpers incl. redirect()), layout.php
storage/       cache, exports, logs (www-data writable)
install-ubuntu.sh, update.sh  → see “Install / update today”
```

## Module system today

`Core\Module` is an abstract class: `key()`, `name()`, `basePath()`, `permissionKey()`
(defaults to key), `icon()`, `registerRoutes(Router)`. Modules are **hard-registered** in
`App::buildModuleRegistry()` (Margin Watchdog, Scheduling). There is **no manifest**: no
declared permissions list, no nav entries beyond one tab per module, no sync-job
declaration, no per-module folder for views (views live under the shared `src/Views/`).
Core routes (auth, dashboard, preferences, presets, admin) are wired directly in
`App::run()`, not through modules.

## Auth and permissions today

- **Local accounts** in `users` (username, optional email, `password_hash`, display name,
  active flag, last_login). Hashing is already **Argon2id** (correction to an earlier
  draft of this doc that said bcrypt). No "must change password on first login", no login
  rate limiting. Sessions are PHP-native with a custom `Session` wrapper; `CSRF` tokens
  on POSTs. *(Both gaps closed in step 3 — see git history.)*
- **Groups**: `permission_groups` (with an `is_admin` flag), `user_group_members`,
  `group_permissions(group_id, page_key, access_level ENUM none/read/full)`.
  `PermissionService::level()` takes the **max** level across a user's groups; admin-group
  members bypass everything.
- **Enforcement**: `AuthMiddleware` redirects anonymous users to `/login`, gates `/admin/*`
  on the session's `is_admin` flag, and checks `PermissionService::canRead()` for the module
  owning the path. Denial renders a **403 page** — the spec's "hidden, not disabled / 404"
  rule (§2.1) does not exist yet. Nav shows tabs per permission but in-page controls are
  not permission-gated.
- **Permission vocabulary**: one `page_key` per module (`margin_watchdog`, `scheduling`)
  with read/full levels — not the named-permission model (`margin.view`, `export`, …) in
  spec §2. No per-group module *visibility*, no default module per group, no group priority.
- Seeded groups: `Administrators` (is_admin) and `Standard Users`. **Production has exactly
  one user account (Matt, in Administrators)** — migration risk is minimal (§14 Q2).
- `audit_log` exists and records logins and admin changes.
- Per-user data tied to `users.id`: `user_preferences` (Margin Watchdog colour thresholds)
  and `date_range_presets` (saved baseline/comparison ranges). These must survive any auth
  rework.
- `settings` is a key/value table (system name, snapshot schedule, scheduling knobs…).

## CMS access today

- `Core\CMSDatabase`: singleton PDO, read-only by convention (no enforcement beyond the
  SQL we write; the `sds_readonly` SQL login is read-only server-side).
- Driver: `sqlsrv` if present else `dblib`. **Production = dblib/FreeTDS**, which brings two
  traps documented in SCHEMA_NOTES: non-ISO date strings (must `CONVERT(VARCHAR(10), …, 23)`)
  and a default query timeout that kills long queries ("DBPROCESS is dead", 20047) — fixed by
  `PDO::DBLIB_ATTR_QUERY_TIMEOUT = 300`.
- Credentials live in `config/config.php` (`cms_db` section) on the server, gitignored.
  Host 10.10.10.11, database `CMS`, login `sds_readonly` (shared with the SDS system;
  §14 Q1: keep it).
- **Pages query CMS live** during request handling: Margin Watchdog runs its three queries
  per report; Scheduling reads orders/inventory/recipes when generating; the Dashboard reads
  yesterday's shipments live. The only "sync engine" precedent is the **nightly inventory
  snapshot** (`cron/snapshot-inventory.php` → `inventory_snapshots` table) because
  `GetInventoryAtDate()` takes ~46 s per call. There is no background worker, no sync status
  UI, no blackout windows, no kill switch.

## Margin Watchdog structure

- `MarginWatchdogController` (routes under `/margin-watchdog`): main page, bill-to AJAX
  fragment, item drill-down fragment, Excel export.
- `ShipmentService` builds the three SQL queries off `CMS.dbo.ShipmentDetails`:
  company summary, per-Bill-To, per-item-for-a-Bill-To. All share the filter contract
  (SCHEMA_NOTES §1): `QtyShipped > 0` (returns/credits ignored), voids excluded two ways via
  `Trans.ReversedTrans` (the reversing invoice AND the reversed original), alias's own
  `Description`, `Item.ReplacementCost` via `ISNULL(alias.ReplacedBy, alias.Item)` for
  expected packed cost.
- `MetricsCalculator::compute()` is a pure function: qty-weighted averages, diffs, pct
  changes (null on zero baseline), `in_both_periods`, expected-cost metrics.
- `Thresholds` resolves per-user → system-default colour thresholds.
- `ExcelExporter` (PhpSpreadsheet 2.x API — `setCellValue([col,row], …)`).
- Quick presets (6mo/QTD/YTD) + manual ranges + per-user saved presets.
- **Regression fixtures are captured** (`tests/fixtures/margin-watchdog/`): two summary
  pairs, 180 Bill-To rows, three item drill-downs, each with verbatim raw SQL rows and
  oracle-computed expected metrics. The move into the new module structure must keep these
  passing (pure-function equality, 1e-9).

## Scheduling module (tool #1.5 — exists, keep working)

Production mill scheduling: `sched_item_config` (per-bulk-item standard batch size,
dry-grind override, not-milled flag), dry-grind raw-material triggers with `%` patterns,
mill list with dry/std lbs-per-hr rates and dry-capable flags, second trigger list gating
what is milled at all (>50 lb batches always schedule), make-to-order for items without
minimum stock, drag-drop day assignment, Excel export. Reads CMS live. Note §14 Q7: its
`sched_item_config.standard_batch_size` is the natural home/precedent for Order Readiness
batch-size rounding.

## Install / update today

- `install-ubuntu.sh` (run as root on Ubuntu): prompts for config; installs **Apache**,
  MariaDB, PHP + extensions + `php-sybase` (dblib); clones to
  `/var/www/precision-ink-insights`; writes `config/config.php`; creates DB + runs
  migrations + admin user; Apache vhost; cron (nightly snapshot) + logrotate; optional
  30-day snapshot backfill. **No systemd services of our own, no nightly app-DB backup, no
  helper/sudoers layer, no network configuration.**
- `update.sh` (run as root): self-relocates to a temp copy (its own inode gets swapped by
  the reset), `git fetch` + `reset --hard origin/main` as www-data, composer only when
  composer files changed, migrations always, permissions, re-asserts logrotate + cron with
  read-back verification. Maintenance steps run even when already at HEAD. No test gate,
  no rollback.
- Update procedure Matt uses: `sudo bash /var/www/precision-ink-insights/update.sh`.

## Fragile bits / known traps (hard-won — do not re-learn these)

1. **dblib date strings** — any date equality/grouping must `CONVERT(VARCHAR(10), col, 23)`;
   `CAST(… AS DATE)` comes back as "May 6 2026…" and silently matches nothing.
2. **dblib query timeout** — long CMS queries need `DBLIB_ATTR_QUERY_TIMEOUT` (set to 300).
3. **`GetInventoryAtDate()` ≈ 46 s per call** — never call from a web request; snapshot it.
4. **`bulk` is a reserved T-SQL word** — never use it as a table alias.
5. **Cron installs**: em-dashes in crontab comments and piped installs fail silently —
   write a temp file, `crontab -u www-data <file>`, then **read back and verify**.
6. **logrotate**: no inline comments after directives; group-writable dirs need `su`.
7. **update.sh self-swap** — any change to update.sh itself only takes effect via the
   self-relocation trick; don't remove it.
8. **Errors must always be logged** — `error_reporting(E_ALL)` + `log_errors=1`
   unconditionally; only `display_errors` is debug-gated. (A 500 was once undebuggable
   because non-debug mode set `error_reporting(0)`.)
9. **PhpSpreadsheet 2.x** removed `setCellValueByColumnAndRow()`.
10. **CMS server performance is a known concern** — few set-based queries, no loops.
11. **No local PHP runtime on the dev (Windows) box** — SQL is verifiable against live CMS
    from here (PowerShell/sqlcmd), but PHP/JS changes are only proven on the server.
    This has repeatedly produced "shipped but silently broken" bugs; verify on-server.

## Gaps vs the new spec (what Part A must add)

- No permission vocabulary beyond one key/module; no hidden-not-disabled, no 404-on-denied
  (it's a 403 today), no per-group module visibility, no default module/group priority, no
  route-crawl test.
- bcrypt not Argon2id; no first-login password change; no rate limiting.
- No module manifests; module views/SQL not co-located; core routes outside modules.
- No sync engine, worker, status bar, blackout windows, CMS kill switch, or admin CMS page
  (CMS creds are only editable by SSH today).
- Dark-only UI; no shared component set; no Home page (dashboard is the landing page).
- Installer is Apache/dblib and interactive-only; spec wants nginx/`pdo_sqlsrv`,
  curl-pipe install, `insights-update` + privileged helper, backups, network config,
  in-place upgrade of this very server.
- No PHPUnit harness (fixtures exist but no runner).
