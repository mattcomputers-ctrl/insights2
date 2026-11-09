# SCHEMA_NOTES.md — CMS data sources used by Precision Ink Insights

This document lists every CMS (MSSQL) table/column/view that **Precision Ink
Insights** queries, why, and the assumptions behind those queries. It exists
so future tabs can re-use the same join patterns and so the numbers can be
tied out against other CMS reports.

The CMS connection is **read-only** — this app never writes to CMS.

---

## 1. Primary data source: `CMS.dbo.ShipmentDetails` (view)

All Margin Watchdog queries hit this view. The view itself is a 12-table
join over `InvMovement`, `InvMovementDtl`, `ChangeSet`, `ChangeSetShipment`,
`Trans`, `Ordr`, `OrdDetail`, `OrdDetailPricing`, `Item` (×2), `Entities`,
`Entity`, `Waybill`, and `ItemCustom`, filtered to:

  - `InvMovement.Context  = 'SH'`           (shipments)
  - `InvMovementDtl.Context IN ('US','USH')` (shipment line detail)

### Columns we use

| View column   | What it is                                                      | Why we use it |
|---------------|------------------------------------------------------------------|---------------|
| `BillTo`      | `Entities.EntityCode` (via `Ordr.BillTo`)                        | Customer grouping key |
| `ItemName`    | Alias item code (`Item.ItemCode` via `OrdDetail.ItemName`)       | Customer-facing alias / line grouping |
| `OrderedUnit` | Per-line UoM (from `OrdDetailPricing`)                           | Item drill-down "Unit" column + mixed-UoM detection |
| `QtyShipped`  | `-InvMovementDtl.Qty` (positive for outbound; **negative = return/credit**) | Quantity sold per line; sign drives the returns filter |
| `UnitCost`    | `InvMovementDtl.Value / InvMovementDtl.Qty`                      | **Canonical packed raw cost per unit** at time of shipment |
| `UnitPrice`   | Currency-converted unit price                                    | Reference; we don't multiply this — see caveat #3 |
| `TotalAmount` | Calculated line revenue (`-Qty × Price × package factors`)       | **Canonical revenue** — used directly, never recomputed |
| `DateShipped` | `Waybill.DateShipped` if the waybill is `Status='CMP'`, otherwise falls back to `ChangeSet.ChangeDate` | The single date filter for both ranges |
| `ChangeSet`   | FK to `ChangeSet.ChangeSet`                                      | Used to join through to `Trans` for the void filter |

> Note: the view also exposes a `Description` column from the inventory item.
> We do **not** use it; instead we LEFT-JOIN `CMS.dbo.Item` on `ItemCode = sd.ItemName`
> to get the alias's own description (see caveat #2).

### Auxiliary joins

| Join                                                                 | Reason |
|----------------------------------------------------------------------|--------|
| `LEFT JOIN CMS.dbo.Entities e ON e.EntityCode = sd.BillTo`           | Look up `e.EntityName` for Bill To display. NOTE: the `Entities` **view** exposes `EntityName`; the `Entity` **base table** does not. |
| `LEFT JOIN CMS.dbo.Item alias ON alias.ItemCode = sd.ItemName`       | Get the alias's own `Description` (item-drill-down only) |
| `LEFT JOIN CMS.dbo.Item inv ON inv.Item = ISNULL(alias.ReplacedBy, alias.Item)` | Resolve to the inventory item to read `ReplacementCost` (item-drill-down only) — see caveat #5 |
| `LEFT JOIN CMS.dbo.ChangeSet cs ON cs.ChangeSet = sd.ChangeSet`      | Walk up to the invoice header |
| `LEFT JOIN CMS.dbo.[Trans] t ON t.[Trans] = cs.[Trans]`              | Reach `Trans.IsReversed` for the void filter |

### Filtering rules (applied in every Margin Watchdog query)

1. **`sd.QtyShipped > 0`** — excludes returns and credits, which CMS records
   as shipments with negative quantity. Per business owner: returns/credits
   are intentionally **not** netted into Margin Watchdog totals because
   they would distort the period-over-period comparison.
2. **Void filter via `Trans.ReversedTrans`** — excludes voided invoices.
   When CMS reverses an invoice it inserts a second `Trans` row with
   `ReversedTrans` pointing back to the original. Both rows of the pair
   share the same `TransDocument` and must be excluded:
   - `t.ReversedTrans IS NULL` rejects the reversal trans itself.
   - A LEFT JOIN to a derived table of distinct `ReversedTrans` values,
     plus `reversed_originals.ReversedTrans IS NULL`, rejects the original
     row that was pointed at.
   Both conditions also pass when there's no `Trans` row yet (the LEFT
   JOIN to `Trans` returns NULL), so shipments that haven't been invoiced
   yet still appear.

---

## 2. Caveats and known limitations

### Caveat #1 — Date semantics
`DateShipped` is the invoice / ship date. The view picks `Waybill.DateShipped`
if the waybill is completed (`Status='CMP'`), else `ChangeSet.ChangeDate`. We
treat this as the date on which `TotalAmount` becomes recognised revenue.

If your accounting prefers the invoice's `Trans` date specifically (rather
than the waybill date), update the date filter in
`src/Modules/MarginWatchdog/ShipmentService.php` to filter on the joined
`Trans.TransDate` column instead. Document the change here.

### Caveat #2 — Item description: alias's own, not inventory item's
The view's `Description` column resolves to the *inventory* `Item.Description`,
which can diverge from the alias's own description for relabeled /
private-label SKUs. We don't use the view column. Instead, the
item-drill-down query LEFT JOINs `CMS.dbo.Item alias ON alias.ItemCode =
sd.ItemName` and uses `alias.Description`. That's the description executives
see on order entry, packing slips, and customer-facing output.

### Caveat #3 — `TotalAmount` is the revenue source of truth
The spec is explicit: do **not** recompute revenue as `UnitPrice × QtyShipped`
unless reconciling. The view's `TotalAmount` already accounts for currency
conversion (`dbo.AltCurrencyToBaseCurrency()`) and package-vs-unit pricing
factors. We follow that rule.

For packed cost we **do** compute `UnitCost × QtyShipped` because the view
does not pre-aggregate cost. This is exactly what the line-item viewer in
CMS shows.

### Caveat #4 — Returns, credits, voids: confirmed handling
CMS records returns and credit memos as shipments with **negative quantity**
on the line. Voids are recorded by inserting a second `Trans` row whose
`ReversedTrans` column points back at the original; both rows in the pair
share the same `TransDocument`. Per the business owner the rules for
Margin Watchdog are:

  - **Returns and credits are intentionally excluded.** They distort period
    comparisons because a return in one period offsets revenue from a
    completely different period. The query filter is `sd.QtyShipped > 0`.
  - **Voided shipments are excluded.** Voids in CMS are predominantly used
    to undo pricing errors, so they aren't real activity worth analysing.
    Both halves of the `(original, reversal)` pair are filtered out:
    - the reversal row via `t.ReversedTrans IS NULL`
    - the original row via a LEFT JOIN to a derived table of `DISTINCT
      ReversedTrans` values, plus `reversed_originals.ReversedTrans IS NULL`

Verified directionality: `Trans.ReversedTrans` on row R contains the
`Trans` PK of the **original** that R is reversing. The original itself
has `ReversedTrans = NULL`. Confirmed against live data — the void filter
removes both halves and leaves un-invoiced shipments alone.

### Caveat #5 — Expected packed cost: lookup, not recalculation
The "Expected Packed Cost" column in the item drill-down comes from
`Item.ReplacementCost` on the **inventory item** (resolved via the alias's
`ReplacedBy` chain). CMS already pre-computes this value as
`bulk_replacement_cost + Σ (packaging ingredient qty × packaging
ReplacementCost)` and stores the result on the packaged inventory item, so
we don't walk the recipe tree at query time.

Verified example: `E1055-50` (5 lb packaged ink), `Item.ReplacementCost =
3.6655`, which equals `(1.0 × E1055@3.4344) + (0.215 × 5LBPT@1.08)` from
its `RecipeDetail` lines.

Two consequences:

1. **Aliases store NULL replacement cost** — at probe time, all 7,197
   alias rows had `ReplacementCost IS NULL`, so the lookup MUST follow
   `alias.ReplacedBy` to the inventory item. The query uses
   `ISNULL(alias.ReplacedBy, alias.Item)` so the same join works for
   non-alias inventory items too.
2. **Some inventory items have no replacement cost on file** (e.g.
   shipping fees, credit-card fees, items without supplier prices). Those
   rows show "N/A" for the Expected Packed Cost column rather than $0.

If `Item.ReplacementCost` becomes stale relative to current input prices
in your environment, switch to walking the recipe at query time. The
fallback path is documented inline in
[ShipmentService.php](src/Modules/MarginWatchdog/ShipmentService.php).

### Caveat #5 — Mixed unit of measure on a single alias
A single alias can theoretically be sold in multiple units across different
shipments. We detect this with `COUNT(DISTINCT OrderedUnit)` per alias and
flag rows with more than one unit value as "mixed UoM" in the UI rather than
silently summing across incompatible units. Quantities ARE still summed for
display — the user is just warned.

---

## 3. Local MySQL schema (this app, not CMS)

Since migration 009 (named permissions): `group_permission_grants (group_id,
permission)` holds per-group named grants (a user's permissions are the union
across groups; admin groups bypass); `group_module_visibility` hides a module's
existence from a group (hidden beats granted, admins excepted);
`permission_groups.priority` + `.default_module` decide the post-login landing
page; `users` gained `must_change_password`, `failed_logins`, `lockout_until`,
`landing_module`. The old `group_permissions` (page_key/read/full) table is
retained unused for rollback. Permission keys are declared by module manifests
(`Module::permissions()`) plus the core set in `PermissionCatalog`.

Migrations live in `migrations/*.sql`. Tables this app owns:

| Table                  | Purpose |
|------------------------|---------|
| `users`                | Local auth — username, hashed password (Argon2id), display name |
| `permission_groups`    | Named groups (e.g. "Administrators", "Standard Users") |
| `user_group_members`   | Many-to-many of users ↔ groups |
| `group_permissions`    | (group, page_key) → access_level (`none`/`read`/`full`) |
| `settings`             | App-wide key/value settings |
| `user_preferences`     | Per-user prefs — colors_enabled, `mw.threshold.*` |
| `date_range_presets`   | Saved baseline+comparison ranges per user |
| `audit_log`            | Login events, report views, exports |

---

## 4. Scheduling module — CMS data sources

The Production Scheduling tab reads:

| Source | Used for |
|---|---|
| `InventoryTotal` (view, summed across owners per Item) | `QtySOH` on hand · `QtyBooked` open customer demand (OrdDetail contexts UI/SH/KD/UAI, IsOpen=1, non-quote) · `QtyOnOrder` incoming supply incl. **released production** (contexts PK/PKA/PO) · `QtyOpenToSell` = (SOH−unusable)+OnOrder−Booked |
| `ItemEntity.MinimumStock` where `Context='ST'` | Per-pack minimum stock level (verified: E1055-50 = 50) |
| `Item.Context='PP'` | Identifies pack items |
| Pack `CostingRecipe` → `RecipeDetail` `Context='UI'`, ingredient `Unit='lb'`, max QtyReqd | Pack → bulk item resolution (fallback: strip `-suffix` from code) |
| `ShipmentDetails` trailing 91 days (same returns/void filters as Margin Watchdog) | Popularity ranking (shipped lbs by bulk) for capacity-overflow priority |

Need formula per pack: `need = max(0, MinimumStock − QtyOpenToSell)`;
**tier 1** when `QtyOpenToSell < 0` (open orders not covered even counting
released production — released batches count via `QtyOnOrder`, so they
correctly keep an item out of tier 1).

Local config (MySQL): `sched_mills` (equipment), `sched_item_config`
(bulk item → color/batch sizes), `sched_dry_grind_triggers` (raw-material
patterns, `%` wildcard), `settings['sched.color_order']`,
`settings['sched.dry_grind_passes']`.

**Milling status and passes are derived, not configured.** Two trigger
lists (`sched_dry_grind_triggers`, `sched_mill_triggers`, both
`%`-wildcard patterns) are matched against the **direct** recipe
ingredients only (`Context='UI'`, no recursion into sub-recipes):

  - dry-grind trigger match → milled, global dry pass count, dry-capable mills only
  - milling trigger match → milled, single pass
  - neither → blend of pre-ground materials, **excluded from the schedule
    entirely**, UNLESS a batch exceeds 50 lbs (big blends still need
    production planning; small ones are bench work)

Intermediates never propagate their own status — they were ground out
when the intermediate was made. The full engine algorithm is documented
at the top of
[ScheduleEngine.php](src/Modules/Scheduling/ScheduleEngine.php).

## 5. How to add a new module / tab

The module abstraction lives in `src/Core/Module.php`. A module is a
self-contained folder under `src/Modules/{Name}/`: code, `Views/` (rendered
with `view('{key}::{template}')` / `layout('{key}::{template}', …)`), and
`Tests/*Test.php` (picked up by `php tests/run.php`). To add a tab:

1. Create `src/Modules/{Name}/{Name}Module.php` extending `\PII\Core\Module`.
2. Implement `key()`, `name()`, `basePath()`, `registerRoutes()`; override the
   manifest methods as needed: `description()`, `icon()`, `permissions()`,
   `navItems()`, `syncJobs()`, and `viewsPath()` (return `__DIR__ . '/Views'`).
3. Register it in `src/Core/App.php` → `buildModuleRegistry()`.
4. Optionally grant the new permission key to existing groups in
   `/admin/groups`.

The tab nav, permission check, and route dispatch all pick up the new module
automatically. No global wiring to update.

## 6. CMS schema knowledge merged from the SDS System (2026-10-29)

The SDS System (separate repo, `/var/www/sds-system` in production) explored the CMS
schema further than Insights had, especially items, recipes and inventory. Its CMS access
lives in `src/Services/CMSImportService.php` there; the schema facts below are merged here
per SPEC-order-readiness §0.3 so this file is the single source of truth. Verified against
live CMS during SDS development; re-verify anything load-bearing before building on it.

### 6.1 Item ↔ recipe model (the "has a recipe" question)

```
Item (PK: Item int, code: ItemCode varchar(30))
  └─ Item.CostingRecipe (int, nullable) ──► Recipe (PK: Recipe int, RecipeNumber varchar(20))
        └─ RecipeDetail (FK Recipe), rows distinguished by Context:
             'BA'    batch header      'UI' ingredients (formula lines)
             'INSTR' instructions      'PK' packaging line   'UB' sub-packaging
```

- **An item "has a recipe" iff `Item.CostingRecipe IS NOT NULL`; the current active recipe
  is the one `CostingRecipe` points at.** This is exactly how the SDS importer splits
  finished goods (have a CostingRecipe) from raw materials (appear as ingredients, no
  CostingRecipe of their own). Order Readiness §8.3 should use the same rule.
- Formula lines: `RecipeDetail.Context='UI'`, `RecipeDetail.Item` → ingredient `Item.Item`,
  `QtyReqd` is the fraction (UI rows sum to 1.0), `Line` is the order.
- Verified example: E1202 → CostingRecipe 8203 → RecipeNumber E1202.03, 5 UI rows summing
  to 100%.
- Not formula-related: `ItemComponent` (chemical CAS composition), `Component`,
  `Bill`/`BillDetail` (AP costing). `BatchingRecipeLibrary` exists for batching variants —
  scope for Order Readiness step 6 verification.

### 6.2 Aliases (Item Names)

Aliases are ordinary `Item` rows with `ReplacedBy` set to the parent inventory item's
`Item` PK (`ReplacedBy IS NULL` ⇒ real inventory item). The `ItemNames` view pre-resolves
the self-join (`ReplacedByCode` = parent's ItemCode; ~7,100 alias rows). Margin Watchdog
already relies on this (§2 Caveat #2): the alias's own `Description` is shown, and
inventory-item lookups use `ISNULL(alias.ReplacedBy, alias.Item)`.

### 6.3 What's underneath the `ShipmentDetails` view

The view (§1) joins ~12 tables, filtered `InvMovement.Context='SH'`,
`InvMovementDtl.Context IN ('US','USH')`:

```
InvMovement ─ InvMovementDtl ─ OrdDetail ─ Ordr ─ Entities (ShipTo/BillTo/Salesman)
      │              │             └ OrdDetailPricing
      └ ChangeSet ─ Trans (invoice)            └ Item (alias via OrdDetail.ItemName)
            └ ChangeSetShipment ─ Waybill (DateShipped when Status='CMP')
```

Useful when the view is not enough: `OrdDetail.QtyReqd`/`Price` are the order line;
`InvMovementDtl.Qty` is negative for shipments; `DateShipped` falls back to
`ChangeSet.ChangeDate` for uncompleted waybills. For Order Readiness, open-order reads
should come from `Ordr`/`OrdDetail` directly (`Ordr.Context='SH'`), not ShipmentDetails
(which only shows what already shipped).

### 6.4 Order / ticket contexts and statuses (for Order Readiness — verify at step 6)

- `Ordr.Context`: `SH` sales order, `MFBA` batch manufacturing ticket, `MFPP` packaging
  ticket, `PO` purchase order.
- Sales order statuses (per Matt): `CMP` completed; `CSH` open, credit hold; `RTS` open,
  released to ship.
- Ticket statuses (per Matt): `RLS` released; `NST` cut, not released (flag); `BAT`
  production complete, closing (flag if >1 day); `CMP` completed; `CLS` closed-not-completed
  (cancelled — not open supply).
- `BatchSheetDemand` links batch tickets to the demand (sales) orders they were cut for.
- Inventory does not move until a ticket reaches `BAT`/`CMP` (§14 Q6) — open `RLS` tickets
  are future supply only.

### 6.5 Minimum stock

`ItemEntity.MinimumStock` with `ItemEntity.Context='ST'` is the authoritative row (the row
CMS's ShortInventory view uses; already relied on by Scheduling, §4). `ItemEntity` has
multiple rows per item (~29,900 rows / ~22,400 items) — never join it without the Context
filter. Values: real minimum (~390 items), **99 = discontinued** (~35 items), NULL = no
minimum (Scheduling treats as make-to-order; Order Readiness: not suggested unless
projected negative).

### 6.6 Other merged pointers

- **Price versions** (not yet used by Insights): `PriceVersion` header + `PriceDetail`
  lines; active price resolved by latest `EffectiveDate` ≤ today; convenience views
  `PriceDetailExt`, `PurchasePriceDetails`.
- `Entity`/`Entities`: `Entities` is the convenience view with `EntityCode`, `EntityName`
  and role flags (IsBillTo, IsSupplier, …).
- `GetInventoryAtDate(@Date, @Owner)` TVF: historical inventory, **~46 s per call** — never
  from a web request (nightly snapshot only). Live inventory comes from the fast
  `InventoryTotal` view instead.
