# Build prompt: Precision Ink Insights — platform upgrade + Order Readiness & Production Planning tool

> Saved verbatim from Matt's build prompt, 2026-10-29. §14 answers are inline.
> Working notes and decisions live in `docs/DECISIONS.md`; current-state audit in
> `docs/CURRENT_STATE.md`.

---

You are working on **Precision Ink Insights**, the existing in-house analytics platform for Precision Ink Corporation (paste ink manufacturer, Elk Grove Village, IL). Insights already exists, already connects to the Mar-Kov CMS database read-only, and already has one tool, **Margin Watchdog** (dual-date-range margin comparison, company → Bill To → item drill-down).

This job has two parts:

- **Part A — Platform upgrades to Insights:** users and groups with assignable permissions, a full UI redesign that is much friendlier, a shared background CMS sync engine with a live on-screen sync indicator, admin controls for CMS access and sync, and a one-script installer/updater.
- **Part B — New tool: Order Readiness & Production Planning.** It reads CMS inventory, open orders and open batch tickets and tells people what can ship, what is waiting on a batch, what is short, and what production should make next.

Insights stays a **read-only companion to CMS**. Nothing in this work writes to CMS.

Work in the order given in §13. Where it says **"ask Matt"**, stop and ask before building that part. Where it says **"decide"**, make a sensible choice and record it in `docs/DECISIONS.md`. Commit in small, working increments. **Margin Watchdog must keep working at every commit.**

---

## 0. Start by understanding what's already here

Before changing anything:

1. Read the whole Insights repo: `CLAUDE.md`, `SCHEMA_NOTES.md`, `docs/`, the router, the auth code, the CMS connection layer, the Margin Watchdog code and SQL, the CSS/JS, and however it's deployed today.
2. Write `docs/CURRENT_STATE.md`: stack and versions, directory layout, how auth works now (users, roles, how permissions are checked), how CMS is queried, how Margin Watchdog is structured, how the app is installed/updated today, and anything fragile.
3. Read the **SDS System** project's CMS notes as well (ask Matt for its path/repo). It explored the CMS schema further than Insights did, especially items, recipes and inventory. Merge what's relevant into Insights' `SCHEMA_NOTES.md` rather than keeping two sources of truth.
4. **Stop and show Matt** the current-state summary and a short migration plan (especially for existing user accounts and any existing roles) before Part A begins.

Keep the existing stack unless there's a strong reason not to: PHP 8.x + PDO, MySQL/MariaDB for app data, PDO `sqlsrv` (ODBC Driver 18) for CMS, Alpine.js, server-rendered pages, no front-end build step. Record any change in `DECISIONS.md`.

---

# Part A — Platform upgrades

## 1. Turn Insights into a multi-tool platform

- Insights becomes a home for many tools. Margin Watchdog is tool #1, Order Readiness is #2, and more will follow (the long-term plan is for Precision Ink's in-house systems to grow into one ERP).
- Give every tool a **module** structure: its own folder (routes, controllers, views, SQL, tests), a manifest declaring its name, icon, description, the permissions it defines, its nav entries, and any data it needs from the sync engine (§3). Adding a tool should not require editing core files beyond registering the module.
- Move Margin Watchdog into this structure **without changing its behaviour or numbers**. Before moving it, capture a handful of real Margin Watchdog results (two date ranges, a few Bill Tos, a few items) as regression fixtures; after the move, the same inputs must give identical output.

## 2. Users, groups and permissions

Replace or extend the current auth (based on what §0 finds) with:

- Local accounts: username, display name, email (optional), active flag, "must change password on first login". Argon2id hashing, session cookies, CSRF protection, login rate limiting. Keep the auth layer small enough to add Microsoft 365 sign-in later.
- **Groups.** Admin creates groups, assigns users to one or more groups, and ticks permissions per group. A user's permissions are the **union** of their groups'. Permissions are checked **server-side** on every request and every API call, not just hidden in the UI.
- **Permissions are declared by each module** (manifest) plus core ones, and the group editor shows them grouped by tool with plain-English descriptions ("See margin and cost data", not `mw.view`).
- Core permissions: `admin.users`, `admin.cms`, `admin.sync`, `admin.network`, `admin.system`, `sync.manual`, `sync.override_blackout`, `export`.
- Margin Watchdog permissions: `margin.view` (cost/margin data is sensitive — only executives by default), `margin.export`, plus whatever per-user settings it already has.
- Order Readiness permissions: see §11.
- Seed groups: `Administrators` (everything), `Executives` (Margin Watchdog + all Order Readiness views), `Production`, `Shipping`, `Sales`, `Read only` (see §11 for their Order Readiness permissions).

### 2.1 If you don't have permission, you don't see it

The rule everywhere: **anything a user doesn't have permission for is hidden, not disabled or greyed out.** No "you don't have access" walls in normal use.

- **Navigation and Home:** only tools, pages and admin sections the user can use appear in the nav, on the Home tiles, and in any search or quick-jump.
- **Inside pages:** buttons, menu items, tabs, columns, filters, dashboard tiles, links and export options the user can't use are not rendered. Examples: no **Sync now** button without `sync.manual`; no **Override blackout** without `sync.override_blackout`; no Export button without `export`; no Scheduled/Skip controls on the production plan without `production.mark`; an Overview tile is only shown if the user can open what it links to.
- **Data:** hidden fields are never sent to the browser. API and page responses are built per user; hiding with CSS or Alpine `x-show` is not enough. Exports contain only what the user could see on screen.
- **Direct URLs:** a URL for a page, action or module the user can't use returns a plain **404 Not Found** page ("This page doesn't exist or isn't available to you. Go to your home page."), so nothing reveals that the page exists. Log the attempt in `audit_log`.
- **Admin:** a user with only some admin permissions (say, `admin.users`) sees only those admin pages.
- Write a test that logs in as each seeded group and crawls every route, asserting that nav, rendered controls and responses match that group's permissions.

### 2.2 Module visibility per group

Admin can **hide the existence of an entire module from a group** (Admin → Groups → *group* → Modules):

- Each module has a per-group setting: **Visible** (normal, governed by permissions) or **Hidden**.
- A hidden module is completely invisible to that group: not in nav, Home, search, notifications, help text, the user's own profile/settings, or any "what's new" note; its URLs 404; its sync steps don't appear in the status bar text for those users (show a generic "Updating from CMS… step 3 of 7").
- **Hidden beats granted.** If any of a user's groups hides a module, the user doesn't see it, even if another group grants permissions in it. Administrators are the exception: they always see everything. Show this clearly in the admin UI: on each user's page, list their effective modules and permissions, and when a module is hidden say which group hides it. **[ANSWERED: hidden wins.]**
- Hiding a module doesn't delete the group's permission ticks inside it, so un-hiding restores them as they were.
- Seed: Margin Watchdog hidden from `Production`, `Shipping`, `Sales` and `Read only`.

### 2.3 Default module per group

- Admin sets a **default module** for each group (Admin → Groups → *group* → Default module). After login, and when clicking the Insights logo, the user lands on that module's main page instead of Home. Seed: `Executives` → Margin Watchdog; `Production` → Order Readiness › Production plan; `Shipping` → Order Readiness › Orders (Ready to ship tab); `Sales` and `Read only` → Order Readiness › Orders.
- A module can only be chosen as a group's default if it's visible to that group and the group has permission to view it; the picker only offers valid choices.
- **User in several groups:** admin sets a **group priority order** (drag to reorder on the Groups page); the highest-priority group with a default module that the user can actually see wins. If none applies, or the default later becomes hidden or unpermitted for that user, they land on Home with no error.
- If a user can see only one module, they land in it regardless.
- Optional per-user override of the landing page, set by the admin on the user's page (decide whether users can set it themselves; recommend admin-only for now).
- **Migrate existing users** without forcing everyone to be recreated: keep their passwords, map existing roles to the new groups (propose the mapping to Matt first), and keep Margin Watchdog's per-user settings (colour thresholds, saved date presets) attached to the same users.
- The bootstrap admin is in `Administrators` and can't be removed from it or deactivated except by another administrator.
- `audit_log`: every admin change, permission change, login (success/failure), and manual sync, with who/when/before/after.

## 3. Shared CMS sync engine

Both tools need CMS data; Order Readiness needs it continuously. Build one sync engine for the whole platform.

- Sync runs as a **background worker** (systemd service), never inside a web request. "Sync now" from the web inserts a request; the worker picks it up within a couple of seconds.
- Modules register **sync jobs** (what to read from CMS, into which snapshot tables, and what to compute afterwards). Order Readiness registers its job (§9). Margin Watchdog can keep querying CMS live for its date-range reports if that's how it works today and it's fast enough — **decide** after §0, and don't force it into snapshots unless that clearly helps.
- **Snapshots, swapped atomically.** Each run writes to staging tables, validates them (row counts not suspiciously zero, required columns present), then swaps them in at once. Pages never see half a sync; while a sync runs everyone keeps seeing the last complete one.
- **One sync at a time** (DB lock). A second request while one runs doesn't start another; the person is told who started the current one and how long ago.
- Each run reports its current step to `sync_runs` as it goes (step name, step n of N, elapsed), with per-step timeouts and an overall timeout (decide, default 5 min). On failure the old snapshot stays, the failure reason is stored in plain English, and the next scheduled run tries again.
- Be gentle with the CMS server (its performance has been a concern): a handful of set-based queries per run, filtered to what's needed, no row-by-row loops. Log every query's duration in `sync_runs`. Target a full run under 60 s.
- No page in Order Readiness queries CMS directly; only the worker does.
- Keep 90 days of `sync_runs`.

### 3.1 Admin controls (Admin → CMS & Sync)

- **CMS database access:** host (default `10.10.10.11`), database (default `CMS`), read-only username and password, **Test connection** with a clear pass/fail and the reason, and a visible "SQL Server driver not installed" state if `pdo_sqlsrv` is missing. Credentials live in `/etc/<app>/config.env` (or wherever Insights keeps secrets today — keep it out of the database and the repo), written via the privileged helper (§5). **[ANSWERED: keep Insights' current CMS login.]**
- **Enable/disable a CMS-wide kill switch** that stops all CMS access (both tools), for when CMS is being worked on.
- **Auto sync on/off.**
- **Auto sync frequency** in minutes (allowed 5–240, default 15).
- **Blackout windows** for offsite database backups: start time, end time (may cross midnight, e.g. 23:30–02:00), days of week; allow more than one. During a blackout:
  - no automatic sync starts;
  - a sync in progress when a window opens stops at the next step boundary and keeps the old snapshot;
  - manual "Sync now" is refused for normal users with a clear message, and admins (`sync.override_blackout`) get an **Override and sync anyway** button with a confirmation. **[ANSWERED: yes.]**
  - **[ANSWERED: Margin Watchdog's live CMS queries are also paused during blackouts]** (friendly "Paused for nightly backup until 2:00 AM" message).
- **Sync history:** last 100 runs with trigger (auto/manual + who), status, duration, row counts, slowest query, error.

## 4. Full UI redesign — friendly, obvious, calm

Redesign **all** of Insights, including Margin Watchdog and the admin pages. The audience now includes shop-floor, shipping and sales staff, some of whom are impatient, short-tempered, or not comfortable with computers. The UI must never leave anyone wondering what's happening or what to do. Treat this section as requirements, not polish.

Load the `frontend-design` skill if it's available before designing.

**Overall feel**
- Clean, spacious, calm. Large readable type (16 px minimum body, bigger for key numbers), generous click targets (44 px minimum), high contrast. **Light theme by default** with a dark-mode toggle per user (Insights is dark today; keep dark as an option so executives who like it keep it). **[ANSWERED: yes — light default, dark toggle.]**
- A simple **app shell**: top bar with the Precision Ink / Insights name, the **sync status bar** (§4.1), the user menu, and a left nav (collapsible to icons; becomes a bottom/hamburger menu on phones) listing only the tools the user can see (§2.1–2.2). A **Home** page shows tiles for those tools only, each with a one-line description. Users land on their group's default module (§2.3).
- Design every screen so it still looks complete and intentional when controls are hidden for a given user: no empty toolbars, orphaned dividers or gaps where a button would have been.
- Plain-English labels everywhere. No jargon or CMS codes without an explanation next to them (show `RTS` only as "Ready to ship (RTS)" if at all).
- Status always shown with **colour + icon + word**, never colour alone.
- One main job per screen; the main action is the biggest button. Sensible defaults so the common case is zero clicks.
- Every screen shows the "as of" time of the data it's showing.
- Responsive: shop-floor PC, laptop, tablet, phone.

**Feedback — nothing may ever look frozen**
- Any click that takes longer than ~200 ms shows a spinner, skeleton or progress state immediately.
- Buttons that start work disable themselves and change their label ("Exporting…") until done.
- Errors are plain English, say what happened and what to do next, and never show stack traces. Example: "Couldn't reach the CMS server. Showing data from 9:30 AM. Try again in a minute, or tell IT if it keeps happening."
- Success is confirmed with a brief, non-blocking toast.
- Pages that update themselves (after a sync) keep the user's scroll position, filters, sort and open rows.
- Exports show "Preparing your file…" until the download starts.

**Consistency** — build a small shared component set (buttons, status pills, tiles, data table with sort/filter/search/pagination/export, empty states, error states, toasts, modals, form fields with inline validation) in one CSS file + a few Alpine components, and use it everywhere, including Margin Watchdog. Document it on a hidden `/admin/ui-kit` page.

**Margin Watchdog redesign** — same features, same numbers (regression fixtures from §1 must still pass), friendlier presentation: clear Baseline vs Comparison pickers with presets front and centre, big summary cards, drill-down as expanding rows or breadcrumbs, existing colour indicators and thresholds kept.

### 4.1 The sync status bar — always visible, impossible to miss

Fixed at the top of every page, every screen size. Exactly one state at a time:

| State | Look | Text example |
|---|---|---|
| Up to date | Green | "Data from CMS as of 9:42 AM (3 minutes ago)." — relative time ticks live |
| Syncing | Blue, animated **determinate** progress bar | "Updating from CMS… Step 3 of 7: Reading open orders · 18 s" — updates every second |
| Paused for backup | Grey | "Updates paused for the nightly backup until 2:00 AM. Data from 11:24 PM." |
| Auto sync off | Grey | "Automatic updates are off. Data from 8:05 AM." |
| Problem | Red | "Last update failed at 9:45 AM: couldn't reach CMS. Showing data from 9:30 AM." + **Try again** |
| Data is old | Amber | Shown whenever the newest good snapshot is older than 3× the sync interval |

- The status comes from a tiny endpoint (`/api/sync/status`, polled every 2 s while syncing, every 15 s otherwise; cheap, no CMS access).
- **Sync now** lives in the bar (for users with `sync.manual`). Clicking it switches the bar to Syncing **instantly** (before the server answers) and disables the button. If a sync is already running it says so ("Already updating — started by Jane 40 s ago") instead of erroring.
- When a sync finishes, the bar goes green and a toast says what changed ("Updated. 3 orders changed status."). Pages refresh their data in place.

## 5. Installer and updater — `install.sh`

If Insights already has an installer, upgrade it to this spec; if not, add one. Same design as Matt's Chroma system. One script at the repo root, run as root on a **fresh Ubuntu Server 24.04** with network access. Re-running it is the **update**.

```
curl -fsSL https://raw.githubusercontent.com/<owner>/<insights-repo>/main/install.sh | sudo bash
```
and once installed, `sudo insights-update` (wrapper in `/usr/local/sbin`) or the same curl line.

**Existing server:** the current Insights server must be upgradable in place. The installer detects an existing pre-installer deployment, backs it up (code + database), migrates it into the new layout, keeps its config and users, and says exactly what it did. Test this path against a copy of the current production setup before Matt runs it.

**First run (fresh server):**
1. Check Ubuntu version; refuse anything other than 24.04 (or newer LTS) with a clear message.
2. `apt` install git, nginx, mariadb-server, PHP-FPM + extensions (mysql, mbstring, xml, curl, zip, intl, bcmath), composer, unzip, **Microsoft ODBC Driver 18 and `pdo_sqlsrv` via PECL** (required — if it fails, stop with a clear message and how to retry).
3. Clone the repo to `/opt/insights` (or pull). `main` unless `INSIGHTS_BRANCH` is set.
4. Create the system user, MariaDB database and user with a generated password, `/etc/insights/config.env` (mode 600), app key.
5. **Prompt** (defaults shown; every value overridable by environment variable for unattended installs):
   - Admin **username** and **password** (typed twice, hidden, min 10 characters).
   - **CMS connection:** host, database, read-only username and password; test it and show the result; allow "skip, set it in Admin later".
   - **Network:** list interfaces, choose one, static **IP/CIDR**, **gateway**, **DNS**; or "keep DHCP / skip". Write `/etc/netplan/60-insights.yaml`, validate with `netplan generate`, and apply it **last**, after everything else succeeded, with a 10-second warning that an SSH session on the old address will drop, then print the URL at the new address.
6. `composer install --no-dev`, migrations, seed groups/permissions/settings, create the admin.
7. nginx site, PHP-FPM pool, file permissions, the **sync worker systemd service**, enable and start everything, UFW allow 80 (and 22) if UFW is active.
8. Install `/usr/local/sbin/insights-update` and `/usr/local/sbin/insights-helper` plus a sudoers drop-in letting **only** the app user run those two with fixed arguments, no password. The helper exposes exactly: `update`, `set-network <iface> <ip/cidr> <gw> <dns…>`, `set-cms-credentials` (reads JSON on stdin, validates, writes config), `backup`, `restart-services`, `restart-sync-worker`. No shell passthrough.
9. Nightly `mysqldump` of the app database to `/var/backups/insights/` via systemd timer, keep 30 days (snapshot tables may be excluded; the next sync rebuilds them).
10. Print a summary: URL, admin username, where config and logs are, how to update.

**Re-run / update:**
1. Detect the existing install; skip all prompts.
2. `git fetch`; if nothing new, say so and exit 0 (unless `--force`).
3. Back up first.
4. `git pull --ff-only` (refuse and explain if the tree is dirty), `composer install --no-dev`, new migrations, PHPUnit suite including the Margin Watchdog regression fixtures. If tests fail, roll back to the previous commit, restore services, and say so loudly.
5. Restart the sync worker, reload PHP-FPM/nginx. Print old → new version.
6. Flags: `--check`, `--force`, `--branch <name>`, `--unattended`.

Also `bin/uninstall` (keeps the database unless `--purge`).

**Admin → System** shows version (commit + date), "Check for updates" / "Update now" (via the helper, with a live log), backup now / download last backup. **Admin → Network** changes IP/subnet/gateway/DNS via the helper, warning that the browser must reconnect at the new address.

`README.md` lists the exact server commands in order — fresh install and in-place upgrade of the current server — written for someone who has never installed a web app.

---

# Part B — New tool: Order Readiness & Production Planning

## 6. What it does

1. **What can ship right now?** Open sales orders whose lines are fully covered by available inventory.
2. **What will ship once a batch is finished?** Lines that inventory can't fully cover but **open batch tickets** will — and which tickets.
3. **What is stuck?** Lines that neither inventory nor open batch tickets cover — including **discontinued** items, which must be flagged, never hidden.
4. **What should we make next?** Items with a recipe that are near or below their minimum stock level.

## 7. CMS data needed

Write a section of `SCHEMA_NOTES.md` (or `docs/CMS_DATA_MAP.md`, linked from it) covering exactly what this tool needs, with table/view, key columns, join paths, units and status codes, each verified with a real query:

- open sales orders and lines (customer, ship-to, required/ship date, item, qty ordered, qty shipped, unit)
- inventory per item: available vs committed/allocated, on hold, in QC/quarantine, non-shippable locations
- open batch tickets (batching and packaging orders): item produced, quantity, status, scheduled/required dates
- which items **have a recipe** (current/published) — the SDS System already resolves "current active recipe for an item"; reuse that logic
- each item's **minimum stock level**
- unit-of-measure conversions

**Starting observations from a quick read-only look at CMS (verify every one):**

- `Ordr.Context` separates order types. Seen: `SH` (shipping/sales), `MFBA` (batch manufacturing), `MFPP` (packaging), `PO` (purchase).
- `Ordr.Status`: `CMP` and `CLS` are done. Open ones seen right now: `SH` `RTS` and `CSH`; `MFBA` `RLS`; `MFPP` `NST`. Find every status code in use and what it means. **[ANSWERED — see §14 Q3.]**
- Order lines appear to be in `OrdDetail`. Views such as `BatchingOrder` / `BatchingOrders` carry Product, Qty, Status, DateRequired, PlanStartDate, DateScheduled, Lot. `BatchSheetDemand` appears to link batch tickets to the demand (sales) orders they were made for — use that link when present.
- Minimum stock is `ItemEntity.MinimumStock`. `ItemEntity` has **multiple rows per item** (~29,900 rows for ~22,400 items): ~390 have a real minimum, **35 are set to 99**, the rest are null. Work out what the "entity" dimension is and which row is authoritative for an item. **[ANSWERED — see §14 Q5; prior Insights work confirmed `Context='ST'` is the authoritative row.]**
- Recipes: `Recipe`, `RecipeDetail`, `BatchingRecipeLibrary`.

## 8. Business rules

### 8.1 Order coverage

For each **open sales order line** with remaining qty (`ordered − shipped`, in a common unit):

| Status | Meaning | Look |
|---|---|---|
| **Ready to ship** | Available inventory covers all of it | Green ✓ |
| **Waiting on batch** | Inventory + open batch ticket(s) cover it. Show which tickets, how much each contributes, their status and dates | Blue ⏳ |
| **Short** | Inventory + open tickets don't cover it. Show the shortfall | Red ⚠ |
| **Short — discontinued** | Short, and the item's minimum stock is **99** | Red ⚠ + **DISCONTINUED** badge |

An **order** rolls up from its lines: Ready only if every line is Ready; otherwise it shows its worst line status and a count ("2 of 5 lines short").

**Allocation is fair and deterministic.** Allocate available inventory first, then open ticket output, to order lines in priority order: **earliest required/ship date**, then order date, then order number. A unit of stock or ticket output never counts toward two lines. A ticket linked to a specific order (`BatchSheetDemand` or equivalent) serves that order first; only its leftover joins the general pool. Implement as a pure function with thorough PHPUnit tests: competing orders, partial coverage, linked tickets, unit conversion, zero/negative inventory, discontinued items.

**[ANSWERED — §14 Q4: priority is drag-and-drop re-orderable; new orders default-insert by date required, everything recalculates.]**

Explain every line in plain words on screen, e.g. *"Needs 400 lb. 250 lb on hand set aside for this order. 150 lb coming from batch ticket 88123 (released, due Nov 3). Covered."*

**[ANSWERED — §14 Q4: inventory on hand right now = available to ship. §14 Q6: inventory doesn't move until a ticket reaches BAT or CMP.]**

### 8.2 Discontinued items (minimum stock = 99)

- `MinimumStock = 99` means **discontinued**. A discontinued item is **never** suggested for production.
- If a discontinued item can't cover an open order, that line is **Short — discontinued**. These also get their own dashboard tile and list: "Discontinued items with open orders".
- **Un-discontinuing:** keep `item_min_stock_history` (item, old value, new value, first seen at). When a sync sees an item move **off 99** to any other value, it is active again from that sync: eligible for production suggestions, with a **"Reactivated"** badge for a period (decide, default 14 days). Moving **onto** 99 shows a **"Newly discontinued"** badge the same way.
- Null minimum = no minimum set: never on the production list by itself, but still part of order coverage.

### 8.3 Production suggestions

Items that **have a recipe**, are **not discontinued**, and whose projected stock is near or below minimum:

- `projected = available on hand + open ticket output − open order demand not yet shipped` (stocking unit). Show each term.
- **Below minimum** (red): projected < minimum. **Near minimum** (amber): projected < minimum × (1 + threshold); threshold is an admin setting, default 20%.
- Items with **negative** projected stock go to the top. **[ANSWERED — §14 Q8: items with negative projected stock and no minimum DO belong on the list, as a priority.]**
- Columns: item, description, minimum, on hand, on open tickets, open demand, projected, shortfall, suggested qty (shortfall, rounded up to a standard batch size — **[ANSWERED — §14 Q7: batch sizes entered in Insights]**), last made, recipe number.
- Users with permission can mark a suggestion **Scheduled** or **Skip until…** with a note (app database only, visible to everyone). Marks clear automatically when a matching batch ticket appears in CMS or the item recovers above minimum.

## 9. Sync job and data (registered with the §3 engine)

Steps reported to the status bar: *Connecting to CMS → Reading items & minimums → Reading inventory → Reading open orders → Reading batch tickets → Checking order coverage → Building production list.*

App-database tables (module-prefixed, e.g. `or_`): snapshot tables for orders, order lines, inventory, batch tickets, items (code, description, unit, has_recipe, recipe_number, minimum_stock, is_discontinued), ticket–demand links, unit conversions; plus computed `or_coverage_results` (line → status, allocations, shortfall, reasoning text) and `or_production_suggestions`; plus `or_item_min_stock_history` and `or_suggestion_marks`.

Admin setting for this tool (Admin → Order Readiness): near-minimum threshold, badge duration, overdue highlighting rules.

## 10. Screens

Built from the shared components in §4.

- **Overview** — big tiles that click through: *Ready to ship*, *Waiting on batches*, *Short*, *Discontinued with open orders*, *Production: below minimum*, *near minimum*. Nothing else.
- **Orders** — one list with tabs per status (default "Ready to ship"); search by order #, customer, item. Columns: order #, customer, ship-to, required date, lines, status, short reason. Overdue required dates highlighted. Click an order to expand its lines inline with the plain-English reasoning and the batch tickets involved.
- **By item** — coverage per item: total open demand, available, on open tickets, which orders get what ("why isn't my order covered — who got the stock?").
- **Batch tickets** — open tickets with item, qty, status, dates and which order lines each is covering.
- **Production plan** — §8.3 with Scheduled/Skip marks; print-friendly layout for the production meeting.
- Every list: sort, filter, print view, export to XLSX and CSV (with `export`).

## 11. Order Readiness permissions and seeded groups

Permissions: `orders.view`, `orders.items_view`, `orders.tickets_view`, `production.view`, `production.mark`, `orders.admin` (tool settings).

| Group | Gets |
|---|---|
| Administrators | everything |
| Executives | all Order Readiness views + Margin Watchdog, `sync.manual`, `export` |
| Production | all Order Readiness views, `production.mark`, `sync.manual`, `export` |
| Shipping | orders, items, tickets views, `sync.manual`, `export` |
| Sales | orders and items views, `export` (no Margin Watchdog) |
| Read only | orders view |

## 12. Non-functional

- Order Readiness pages load in under a second from snapshot tables.
- Logs in `/var/log/insights/`, rotated.
- No outbound network calls at runtime except CMS and the updater's git fetch.
- Keep `CLAUDE.md`, `SCHEMA_NOTES.md` and `DECISIONS.md` current as you go.

## 13. Order of work

1. §0: read the repo and the SDS System notes, write `CURRENT_STATE.md`, capture Margin Watchdog regression fixtures. **Stop and review with Matt.**
2. Module structure; move Margin Watchdog into it; fixtures still pass. Commit.
3. Users, groups, permissions, hide-what-you-can't-use (§2.1), per-group module visibility (§2.2), default module and group priority (§2.3), migration of existing users, audit log, admin screens, and the per-group route-crawl test. Commit.
4. Sync engine, admin CMS & Sync page (connection, kill switch, auto sync, frequency, blackout windows, history). Commit.
5. UI redesign: shell, components, status bar, Home, admin pages, then Margin Watchdog. Fixtures still pass. Commit.
6. Order Readiness data map (§7). **Stop and review with Matt** (status codes, available inventory, MinimumStock row, bulk vs packaged).
7. Order Readiness sync job, coverage engine + tests, production suggestions + min-stock history. Validate against a handful of real orders Matt picks. Commit.
8. Order Readiness screens. Commit.
9. Installer/updater: fresh-install path on a clean Ubuntu 24.04 VM, re-run/update path, and in-place upgrade of a copy of the current Insights server. Commit.
10. README, final usability pass: click through every screen as an impatient user on a phone and a shop-floor PC and make sure nothing ever looks frozen or unexplained.

## 14. Questions for Matt (answers recorded 2026-10-29; also in `docs/DECISIONS.md`)

1. Where is the SDS System project? Keep Insights' current CMS login or create a dedicated read-only one? **ANSWER: Keep Insights' current CMS login.**
2. How should existing Insights roles map to the new groups? **ANSWER: Admin gets everything always. There is currently only one user so I'll reconfigure permissions after we're done.**
2a. When a user is in two groups and one hides a module the other shows, should hidden win (recommended) or visible win? **ANSWER: Hidden should win.**
3. Which CMS order statuses count as open for sales orders and for batch/packaging tickets? **ANSWER: Sales orders that are CMP are completed, CSH is open but credit hold, RTS is open and released to ship. Batch/packaging tickets that are RLS are released, CLS is closed but not completed (like a cancelled ticket), CMP is completed, NST is a ticket that is cut but not released to production (should be flagged), BAT is a ticket that is completed in production and is in the process of being closed (if a ticket is BAT for more than a day it should be flagged as something is wrong).**
4. What inventory counts as available to ship? Respect existing CMS commitments? **ANSWER: Look at what is in inventory right now. If it's in inventory right now consider it as available to ship. Orders will need to be able to have priorities set via drag and drop up/down a list. By default a new order will drop in order by the date required field and then everything around it will need to recalculate.**
5. Which `ItemEntity` row holds an item's authoritative minimum? **ANSWER: All I see in the UI is Minimum Stock. It is set as 99 for E4456-50 and E3736-50 (not exhaustive). Use that to go find the answer.** *(Prior Insights work already verified `ItemEntity.MinimumStock` with `Context='ST'` as the row the CMS ShortInventory view uses; re-verify against E4456-50 / E3736-50 at step 6.)*
6. Packaged vs bulk: how do bulk stock and batching tickets count toward orders for packaged items? **ANSWER: The inventory doesn't move until the ticket is further along in BAT or is CMP.**
7. Standard batch sizes for rounding suggestions — in CMS, or entered in Insights? **ANSWER: Entered in Insights.** *(Note: the Scheduling module already stores per-bulk-item standard batch sizes in `sched_item_config` — reuse.)*
8. Should items with negative projected stock but no minimum appear on the production plan? **ANSWER: Yes — as a priority.**
9. Blackouts: block manual sync too (admin override)? Pause Margin Watchdog's live queries too? **ANSWER: Yes.**
10. Light theme by default with a dark toggle, or keep dark as the default? **ANSWER: Yes (light default, dark toggle).**
