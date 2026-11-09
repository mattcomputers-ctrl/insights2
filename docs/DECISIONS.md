# DECISIONS.md — recorded decisions for Precision Ink Insights

Format: date, decision, why. Spec references are to `docs/SPEC-order-readiness.md`.

## 2026-10-29 — Answers from Matt (spec §14)

1. **CMS login:** keep Insights' current CMS login (`sds_readonly`, shared with the SDS
   system). No new CMS login is created. (§14 Q1)
2. **Role migration:** there is currently **one user** (Matt, Administrators). Admin gets
   everything always; Matt will reconfigure permissions after the build. So migration =
   carry the one account + its preferences/presets forward; seed the new groups fresh.
   (§14 Q2)
3. **Hidden vs granted:** when one of a user's groups hides a module another grants,
   **hidden wins** (admins excepted — they always see everything). (§14 Q2a)
4. **Sales order statuses:** `CMP` = completed (closed). `CSH` = open, credit hold.
   `RTS` = open, released to ship. (§14 Q3)
5. **Batch/packaging ticket statuses:** `RLS` = released. `CLS` = closed-not-completed
   (e.g. cancelled). `CMP` = completed. `NST` = cut but not released to production —
   **flag it**. `BAT` = production done, being closed — **flag if BAT > 1 day** (something
   is wrong). (§14 Q3)
6. **Available to ship:** whatever is in inventory right now counts as available — no CMS
   commitment/allocation layer. Order priority is a drag-and-drop list; a new order
   defaults into position by DateRequired and everything recalculates. (§14 Q4)
7. **Authoritative minimum stock:** `ItemEntity.MinimumStock` where `Context='ST'`
   (verified in earlier Insights work as the row the CMS ShortInventory view uses).
   Re-verify against Matt's examples E4456-50 and E3736-50 at step 6. `99` = discontinued.
   (§14 Q5)
8. **Bulk vs packaged:** inventory does not move until a ticket reaches `BAT` or `CMP` —
   so open tickets contribute *future* coverage; on-hand is only what's physically there.
   (§14 Q6)
9. **Standard batch sizes:** entered in Insights, not read from CMS. The Scheduling module's
   `sched_item_config.standard_batch_size` already stores per-bulk-item batch sizes — reuse
   rather than a second table. (§14 Q7)
10. **Negative projected stock, no minimum:** yes, on the production list, as a priority.
    (§14 Q8)
11. **Blackouts:** block manual sync for normal users (admins get Override with
    confirmation), and pause Margin Watchdog's live CMS queries too, with a friendly
    "paused for nightly backup" message. (§14 Q9)
12. **Theme:** light by default, per-user dark toggle. (§14 Q10)

## 2026-10-29 — Decisions made during §0 (mine, flagged for review)

13. **Margin Watchdog regression contract** = the fixtures in
    `tests/fixtures/margin-watchdog/`: pure-function equality of
    `MetricsCalculator::compute()` against frozen raw rows (delta 1e-9), plus sort and
    view-mode checks. Live re-capture is a manual, optional check only, because
    `ReplacementCost`/alias descriptions drift and late voids can change history.
14. **"SDS System CMS notes" merged from memory + SDS code, not SDS docs/.** The SDS repo's
    `docs/` are about SDS content generation, not CMS schema; its CMS knowledge lives in
    `src/Services/CMSImportService.php` and in working notes. The relevant schema facts
    (items, recipes, aliases, shipment chain) are now in `SCHEMA_NOTES.md` §6 — one source
    of truth, as §0.3 asks.
15. **"Has a recipe" / current active recipe** (for Order Readiness §7): an item has a
    recipe iff `Item.CostingRecipe IS NOT NULL`; the active recipe is
    `Recipe.Recipe = Item.CostingRecipe`. This is exactly how the SDS importer
    distinguishes finished goods from raw materials. Re-verify sample items at step 6.

## 2026-10-29 — Step-3 decisions (mine, flagged for review)

19. **Dashboard metric cards require `margin.view`.** The dashboard shows yesterday's
    revenue, packed-cost % and inventory value — margin-sensitive numbers the spec locks
    behind Margin Watchdog's permission. Users without it see the dashboard as a plain
    tab launcher (no cards, no CMS-error banner). Interim rule until step 5's Home page.
20. **Production group also gets Scheduling** (`scheduling.view` + `scheduling.manage`).
    The spec's §11 table predates the Scheduling tool; the production team obviously
    needs its own mill schedule. Margin Watchdog stays hidden from them.
21. **Exports are double-gated:** the core `export` permission AND the tool-specific one
    (`margin.export` for Margin Watchdog; `scheduling.manage` is not required to export a
    schedule — `export` alone gates that button since viewing the schedule already
    exposes the data).
22. **Preferences and saved date presets follow Margin Watchdog's visibility** — they are
    its per-user settings (colour thresholds, date ranges), so users who can't see the
    module don't see those pages or nav links either.
23. **Rate limiting:** 5 failed logins → 15-minute lockout on that account; the correct
    password is also refused during lockout; counter resets after lockout/success.
24. **Old `group_permissions` table is kept (unused)** for rollback; dropped in a later
    migration once the new model has lived a while in production.
25. **Grants for not-yet-registered modules are preserved**: the group editor only
    touches permissions the catalog knows, so the seeded Order Readiness grants
    (`orders.*`, `production.*`) and the `order_readiness` default-module values survive
    admin edits and light up when that module ships (step 7).
26. **Integration crawl harness** (`tests/integration/`): Docker MariaDB + php:8.3 serving
    the app with a CMS-less test config; logs in as each seeded group and checks every
    route's status, nav links, in-page controls, plain-404 bodies, no-error-dump pages,
    landing paths, forced password change, lockout, and mid-session deactivation.
    Dev-box only (it seeds/deletes crawl_* users); it refuses to run unless the DB name
    contains "test". 350 checks green as of this entry.

## 2026-10-29 — Step-6 data-map decisions (verified against live CMS; flagged for review)

27. **Packaging tickets come from `Ordr`/`OrdDetail` directly** — the
    `BatchingOrders` view covers MFBA only (0 of 97 open MFPP rows appear in it).
    MFPP PK line = pack item produced; MFBA PK line = bulk produced.
28. **"Available to ship" = `InventoryTotal.QtyUsable` summed across owners**
    (SOH minus hold/restricted/unusable; ≈ SOH on today's data). SOH and hold
    components are snapshotted and shown. ⚠ Review: Matt said "in inventory right
    now" — if held/QC stock SHOULD count, switch to QtySOH (one-line change).
29. **No CMS link from tickets to sales orders exists** (`BatchSheetDemand` is
    MFBA→MFPP only), so allocation runs purely on the drag-and-drop order
    priority; a ticket's `CustomerCode` shows as a "cut for …" hint. The spec's
    "linked ticket serves its order first" rule is therefore inert until/unless
    such a link appears in CMS.
30. **Remaining qty on a line = `QtyReqd − QtyUsed` on `IsOpen=1` lines**;
    CMS's own `QtyCommitted` is displayed but not used for allocation (Matt's
    §14 Q4: Insights allocates by its own priority list).
31. **Units:** all open order lines are `lb` today; the coverage engine requires
    matching units and reports (rather than converts) a mismatch.
32. **Order notes** (Matt, 2026-10-29): free-text notes can be attached to any
    order, keyed by CMS order number in the Insights DB (`or_order_notes`), kept
    forever — searchable later even after the order completes, incl. a notes
    search page. Notes are visible to everyone who can see orders; adding needs
    `orders.view`.

## 2026-10-29 — Step-9 installer decisions

33. **Install path stays `/var/www/precision-ink-insights`** (not the spec's
    `/opt/insights`): the production server, its vhost, cron and muscle memory
    already live there. "Migrating into the new layout" = adopting the existing
    checkout in place.
34. **`update.sh` is now a shim that execs `install.sh`** — re-running
    install.sh IS the update (spec §5), with backup-first, migrations, the full
    test suite and automatic rollback to the previous commit on any failure
    (verified in a container: deliberate test break → rolled back, app kept
    serving). `reset --hard origin/<branch>` is kept over `pull --ff-only`
    because chmod-induced mode drift on the server used to block pulls.
35. **pdo_sqlsrv is attempted (MS ODBC 18 + PECL) but failure falls back to
    pdo_dblib with a loud warning** instead of aborting (spec said stop; Matt
    chose the fallback in the step-1 review). `INSIGHTS_SKIP_SQLSRV=1` skips
    the attempt.
36. **Privileged helper** `/usr/local/sbin/insights-helper` with a strict verb
    whitelist (update / backup / restart-services / restart-sync-worker /
    set-cms-credentials via stdin JSON / set-network with validated args); the
    only sudoers grant www-data gets. Backups exclude the or_* snapshot tables
    (the next sync rebuilds them).
37. **Sync cadence note:** the live-CMS test run completed in 2.7 s for the
    full Order Readiness job — the 15-minute default interval is trivially
    gentle on the CMS server.
38. **Order priority reordering requires `production.mark`** (Production and
    Administrators) — changing priority changes who gets stock, which is a
    production-floor call.

## 2026-10-29 — Post-deploy corrections (Matt)

39. **Ticket numbers:** every user-facing ticket reference is the MAIN batch
    order number (the MFBA, e.g. 193802) — never the MFPP packaging order's
    internal row (193803), which nobody recognises. CMS has no separate
    human ticket number (Reference/ManfLot/Lot are all empty on open
    tickets; verified live). Internal ids are kept in allocations for joins.
40. **Production plan counts ALL open tickets:** MFBA output credits the
    bulk item, MFPP output credits the pack and debits the bulk it consumes.
    Tickets CMS hasn't put a quantity on yet (live rows exist) credit
    nothing but still flag the item with "⏳ ticket N open" on the plan so
    nobody cuts a duplicate batch.
41. **Materials check on open tickets (Matt, 2026-10-29):** every open
    ticket's UI (material) lines are snapshotted (summed per item — CMS
    lists some materials twice per ticket) and allocated against
    `QtyUsable` in due-date order via `TicketMaterialsEngine`, so two
    batches never count the same pound of pigment. An MFPP's bulk line
    draws on its linked MFBA's output first, then on-hand bulk; an MFPP
    whose MFBA is itself short is flagged (`mfba_short`). This inventory
    pool is independent of the sales-order CoverageEngine pool — material
    (SUNDRY) items are never on SH orders, so there is no double count.
    A pack's BULK line is never a shortage while its batch is open — the
    batch exists to make that bulk, so "pack short because the bulk isn't
    made yet" is noise (Matt, 2026-10-29). Flags live on the master batch
    number: raw materials flag the MFBA, containers flag the pack row
    (shown under the same batch number), and all counts (tickets subtitle,
    overview tile) count distinct master batches. Orphan packs whose batch
    is gone still check bulk against on-hand stock.
    An NST pack behind an open batch is NOT checked at all and claims no
    stock — NST is packaging's normal waiting state; it only starts when
    the batch goes into BAT (Matt, 2026-10-29). Its status pill is neutral
    ("Waits for the batch"), its Materials cell reads "— with the batch",
    and it re-enters the check once released or once its batch is gone.
    mfba_short still carries through it so orders warn about the batch.
    On open tickets `QtyUsed` is always 0/NULL (verified live): materials
    are issued at completion, so remaining = `QtyReqd`. Surfaced on the
    Batch tickets page (Materials column + export), the orders list
    ("batch short materials" pill), the order drill-down (per-line
    warning), and an overview tile.
42. **MFPP UI lines include CONTAINERS, not just bulk** (found during the
    live materials run, 2026-10-29): pails/lids/drums are also Context
    `SUNDRY`, unit 'ea', no recipe. The ticket's `bulk_code` is therefore
    the item its linked MFBA MAKES (fallback: the UI item that has a
    recipe), never an arbitrary SUNDRY line. This also fixed a latent bug
    where the production plan could debit a pail count instead of bulk
    pounds. Containers stay in the materials check on purpose — running
    out of pails stops packaging just as surely as running out of ink.
43. **Batch tickets page: ONE line per master batch** (Matt, 2026-10-29).
    Packaging orders never get rows of their own — they are listed inside
    the batch line (item, qty, compact status; the internal packaging
    order number only in the tooltip). Orphan packaging (batch already
    done/closed) keeps the batch number with a "batch done" tag. The
    Excel export follows the same one-row-per-batch shape, packaging
    joined into one cell. Covering orders aggregate across the group.

44. **Need to Order module (Matt, 2026-11-02):** the buy list for creating
    POs. Demands = open-ticket material shortages (TicketMaterialsEngine)
    + sales-order coverage shortfalls (the batches still to be cut;
    discontinued and unit-mismatch lines excluded). NeedToOrderEngine
    explodes them MRP-style: net against leftover stock (after ticket
    claims) then net open-ticket output; an item with an ACTIVE recipe is
    MADE, never bought, and must never appear on the list (Matt,
    2026-11-02) — its remaining need rounds up to the standard batch size
    and explodes into its recipe ingredients (fractions per lb, max depth
    6 with a surface-the-remainder safety net, self-reference guarded);
    only recipe-less items are buy leaves, and never-bought recipe-less
    items surface with no vendor. Root references (batch N /
    order N) ride through the explosion so the buyer sees why. TWO vendor
    columns (Matt, 2026-11-02): the PREFERRED vendor (Item.Supplier →
    Entities, set on the item in CMS) and the vendor LAST PURCHASED from
    (most recent PO + its TheirCode; vendor count shown) — groupable by
    either, unset/never-purchased group last. Priority: open-ticket needs
    first, then first-needed date. Permission purchasing.view, no grants
    seeded. The sync job consumes OrderReadiness's same-run hand-off via
    SyncContext::share, so NeedToOrder registers right after it in App.
    SECOND LENS (Matt, 2026-11-02): "Minimum stock" — recipe-less items
    with a CMS minimum whose PROJECTED stock (usable on hand − everything
    open tickets and still-to-cut batches will consume, from a gross
    engine pass where purchased leaves net against nothing while
    intermediates still net their real stock/ticket output) lands below
    the minimum; order qty = minimum − projected, i.e. topped back UP to
    minimum. Recipe items stay the production plan's job. Also fixed:
    ticket output the CoverageEngine already promised to order lines is
    subtracted from the shared ticket-supply credit (no double counting).
    OPEN POs COUNT AS INCOMING (Matt, 2026-11-02): on both lenses a row
    only appears when a gap remains after everything on open POs arrives,
    and only for the remainder (qty − on_po); fully covered items drop
    off the list. The On open POs column stays for context.
    Fee pseudo-items (GLGroup 'Services': wire fee, shipping charge) ride
    on tickets/POs but are never something to buy — excluded from both
    lenses.
    CONTAINERS COUNT EVERYWHERE (Matt, 2026-11-02): NST packs are skipped
    by the materials check, but their containers are still fed to the
    to-complete lens as demands (netted against the stock those packs
    never claimed); the min lens already projected them via the full
    ticket requirements, and new batches carry containers per lb through
    the pack recipe.

45. **Item & Recipe Lookup module (Matt, 2026-11-04):** search by item
    number/description, alias code/description, or recipe number; each
    item lists every recipe that PRODUCES it (matched via the recipe's PK
    line, never name-stem guessing), active costing recipe first. A
    recipe opens in an in-page window as a 100% formula with the
    procedure steps interleaved in ExecOrder (verified live: that is the
    batch-sheet order), ingredient items made in-house click through to
    THEIR recipe (back button, print). Queries CMS LIVE like Margin
    Watchdog — recipes are ~190k detail rows that change rarely, so they
    are not snapshotted; the tool pauses during blackout/kill switch.
    Permission recipes.view, no grants seeded. Recipe views audit-logged.

46. **Item right-click menu (Matt, 2026-11-04):** item codes across the
    modules (Inventory, Need to Order, by-item, tickets, production plan,
    short materials, order drill-down, Recipes) carry a context menu:
    View active recipe (opens the in-page recipe window from ANY page via
    /recipes/active?code=, alias-aware), Find in Recipes / Inventory,
    Coverage by item, Need to Order. Entries are permission-gated
    server-side in the layout (hidden modules stay hidden); the recipe
    window + handlers moved from the Recipes view into the layout/app.js.
    A purchased item answers with a friendly "no active recipe" note.
47. **Read-only operational viewers (Matt, 2026-11-04):** five live-CMS
    modules on the road to Insights becoming the operations front-end
    (write access comes later, piece by piece): Sales Orders
    (sales.view) - every order with lines, prices, shipments and invoice
    numbers via the ShipmentDetails view; Purchase Orders
    (purchasing.view) - lines from the PurchaseOrderDetail view with
    TheirCode and receipts via ChangeSetReceipt -> ChangeSet (date) ->
    Sublot/Lot; Batch Tickets (orders.tickets_view) - full history with
    the batch sheet (UI+INSTR OrdDetail rows in ExecOrder, expected vs
    consumed), produced lots (Lot.OrdDetail -> PK line) and their QC.
    Packs live INSIDE their bulk ticket's page (Matt, 2026-11-04): opening
    a pack redirects to its bulk; a Packaging section shows expected vs
    packed pounds per pack plus its containers, and a Yield section shows
    planned vs actual for the bulk and each pack. The durable bulk<->pack
    link is OrdDetailCommit (pack bulk-line commits against the bulk PK
    line; BatchSheetDemand only covers OPEN chains). Actuals: pack PK
    QtyUsed = pounds packed; bulk actual = positive PCKAGE/CMNGL movement
    qty on the produced lot's sublots (what left the batch vessel).
    REPACK tickets (Matt, 2026-11-04): a ticket with no recipe is a
    repack - its UI line pulls the item ITSELF from stock
    (OrdDetail.IsRepackaging = 1, planned qty in PK RepackagingYield
    since PK QtyReqd is NULL). Shown with a REPACK pill, 'Repacks X
    into Y' header, and a 'Pulled from stock' section instead of the
    batch sheet;
    QC Results (qc.view) - the result chain is LocationSampleTest ->
    Location (SMP sample container) -> Inventory -> Sublot -> Lot ->
    Lot.OrdDetail -> ticket, with specs from ItemTest; Items (items.view)
    - the item 360 (master data, aliases, recipes, QC specs, stock by
    container/lot, recent sales/purchases, where-used; StandardCost only
    with margin.view; detail addressed by ?code= since codes contain
    spaces). All follow the Margin Watchdog live pattern (friendly pause
    when CMS is unavailable), no grants seeded. Portability rules learned
    on the dblib fallback: LIKE escapes use '!' (never backslash - PDO's
    emulated-prepare parser miscounts placeholders after a
    backslash-quote), and wide text columns (TestResult/Memo UDTs over
    nvarchar(max)) are CONVERTed to VARCHAR in the SELECT or FreeTDS
    returns them empty.

48. **Service items, richer menus, COA (Matt, 2026-11-04):**
    (a) GLGroup 'Services' items (wire fee, shipping charge) carry
    or_items.is_service: CoverageEngine marks their order lines ready
    ("Service charge - not an inventoried item") and the materials check
    skips them entirely - they can never trip as short anywhere.
    (b) The item right-click menu gained Item details, Find in Sales
    Orders / Purchase Orders / Batch Tickets and QC results; LOT numbers
    got their own menu (lot_ctx(): Generate COA, QC results for lot, Find
    in Batch Tickets / Inventory) wired through QC, Batch Tickets, Items
    and PO receipt views.
    (c) COA generation (/qc/coa, permission qc.coa): by lot number, with
    the alias to print selected by the user (the ALIAS's own description
    prints), PASS/FAIL-only or detailed mode (specs + results + dates),
    admin-configured logo (upload to public/uploads, settings key
    coa.logo) and disclaimer (coa.disclaimer) at the bottom, printable,
    audit-logged. LOT FAMILY RULE (Matt): QC results live on the batch's
    MASTER lot (which survives only in the QC sample jar after packing);
    pack lots are labels. The COA resolves ANY family lot - a customer's
    pack lot included - to the master's results via OrdDetailCommit, and
    the ticket page shows one master lot (packs' lots are a column on
    their pack rows).

49. **Shipping module + collapsible nav (Matt, 2026-11-04):** Shipping
    (/shipping, shipping.view, lives in the Sales nav section) browses
    every waybill live from CMS: Waybill.ShipVia -> Entities is the
    carrier; WaybillTrackingNumber = one row per ASSEMBLY (an ASM
    Location, the carton/pallet) with tracking number, freight cost and
    actual weight; assembly CONTENTS come from the shipment movement
    lines (ChangeSetShipment -> InvMovement 'SH' -> negative
    InvMovementDtl whose Location is the assembly); orders/invoices via
    ShipmentDetails. Nav sections are collapsible (chevron, state in
    localStorage per browser; the section holding the active page always
    opens; the icon rail ignores collapsed state so every link stays
    reachable). Fixed: .nav-section needed flex:0 0 auto or the flex
    column squashed headers to 0 height once the nav overflowed.

50. **Mill schedule v2 (Matt, 2026-11-04):** the schedule now describes
    reality instead of re-deriving need from InventoryTotal.QtyOpenToSell
    (which could not see open bulk tickets, so it re-planned batches
    already cut AND pretended their mill hours did not exist). Demand now
    comes from the Order Readiness snapshot in four tiers: 0 = open MFBA
    tickets RLS/NST (committed; always schedule, exact ticket quantity,
    no milling gate; missing config = one aggregated warning + worst-case
    color; unquantified tickets assume one standard batch; sequenced
    runnable-first, then due date — a materials-short ticket is flagged
    and placed after runnable ones), 1 = coverage shortfalls on no-minimum
    items (exact lbs, MTO), 2 = production-plan suggestions negative/below
    (already credit stock + open tickets - demand, honor marks and
    99-discontinued), 3 = near-minimum as capacity fill. CMS is now only
    consulted best-effort (pack-bulk map, popularity, dry derivation) —
    generation works from the snapshot even during blackouts. Mill
    mechanics unchanged: color ladder, washup tiers, dry-grind capability
    and rates with dry-first partitioning, carryover, max batch, milling
    gate for suggested work, MTO exact chunking. Warning walls are
    summarized (one line per category, first 8 + count). Engine unit
    tests added (14 checks).

51. **Batch size tiers, defaults, and alias-prefix colors (Matt, 2026-11-04):**
    - Up to **five standard batch sizes** per item (`sched_item_config
      batch_size_1..5`, migration 020; largest is always stored as
      `batch_size_1`, so existing chunk-at-largest logic is unchanged).
    - Configuring an item **prefills from its real batch history**: the
      five most common planned PK quantities on its past MFBA tickets
      (rounded to whole lbs), via `/scheduling/settings/item-defaults`.
      A starting point only — every field stays editable.
    - **Global default batch sizes** (`settings sched.default_batch_sizes`,
      CSV): items with NO config no longer skip suggested work — they
      schedule with the defaults and an aggregated "configure me" warning.
      Clearing the defaults restores the old require-config behavior.
    - **Color guessed from alias prefixes** when an item is unconfigured:
      majority vote over the leading letter of the bulk's and its packs'
      alias codes (letter followed by a digit votes; UV…/E… codes don't).
      Map: B=blue, R=red, P=violet, G=green, Y=yellow, O=orange,
      W=opaque white, K/X=black, N=brown (Matt gave B/R/P; the rest
      verified against live aliases, e.g. 2UK0189 black, 2UY yellow).
      Guessed colors apply to unconfigured tickets too (better washup
      ordering than ladder-end), and are marked "?" in warnings.
      **Description words fill the gaps** (Matt, 2026-11-05): when no
      alias votes, the bulk's own description is scanned for color words
      in priority order (OPAQUE WHITE before WHITE, WARM RED before RED),
      whole words only. Ink-term mappings: CYAN→blue, MAGENTA→red
      (rubine family), GRAY→black, VARNISH/CLEAR/EXTENDER→extender.
      Alias prefix always wins when both exist.
    - **Double-click a scheduled run to change its batch size** (the
      item's standard sizes are offered; any positive lbs accepted).
      The run is tagged `manual_override` + an "edited" pill, its pack
      breakdown re-scales pro-rata, its carryover cards are dropped
      (hours no longer meaningful), and the export reflects the edit
      since export posts the edited JSON.

## Formerly-open items, now decided

- **Margin Watchdog stays on live CMS queries** (date-range reports over years of
  history don't snapshot well; its queries run in seconds). It pauses with a friendly
  message during blackouts and under the kill switch, per §14 Q9.
- Sync overall timeout: settings key `sync.timeout_seconds`, default 300 (spec's 5 min),
  checked at step boundaries.
- Reactivated/newly-discontinued badge duration: `or.badge_days`, default 14.
- Per-user landing-page override: admin-only (set on the user's admin page).
## 2026-10-29 — Decisions from the step-1 review (Matt)

16. **Keep Apache + mod_php; do NOT migrate to nginx + PHP-FPM.** The webserver has never
    been a problem, traffic is tiny, and the sync worker is a CLI systemd service either
    way — swapping the webserver on the production box is risk with no payoff. Spec §5's
    installer is adapted accordingly (Apache vhost instead of nginx site; everything else
    per spec).
17. **Swap the CMS driver dblib → Microsoft ODBC 18 + `pdo_sqlsrv`** (that's where the real
    pain was: date-format trap, query-timeout kills). Keep the dblib code path as a
    fallback, and verify via the new Admin → CMS "Test connection" before anything depends
    on it.
18. **Local test execution via Docker** (`php:cli` container) on Matt's Windows box — the
    box has no PHP but has Docker, so the Margin Watchdog fixture regression runs locally
    before every push instead of only on the server.

## 2026-11-09 — Insights 2: writing to CMS (decided while Matt was away)

52. **Insights writes only by calling CMS's own `ProcessXMLRequest`**, with the same
    request documents the CMS client sends. No table is ever written directly. The
    request shapes were learned from the CMS request log only.
53. **Typed templates only.** Every request Insights can send is built by a named PHP
    template (`src/Cms/Write/Ops/…`) from typed values. There is no "send this XML"
    path, and an operation that is not in `OperationRegistry` cannot be sent.
54. **Three transports.** `dry-run` (default) builds and validates everything and
    sends nothing; `fake` is for tests; `live` calls CMS. Live needs BOTH an untracked
    `config/cms.write.local.php` holding a separate write login AND the admin setting
    `cms_write.live_enabled`. The everyday read connection is never used to write.
    The kill switch and backup blackout stop writes exactly as they stop reads.
55. **Every write is propose → preview → confirm.** The user sees the exact requests
    before anything is sent. On confirm the plan is rebuilt from fresh CMS reads; if it
    differs from what was previewed (someone else changed the record) the write is
    refused and the user is sent back to look again.
56. **One permission per operation**, named `write.<module>.<verb>` and labelled
    "Write to CMS: …". Off for everyone until an admin grants it. The module's page
    permission is still needed to reach the screen.
57. **Each Insights user is mapped to a CMS user** (`users.cms_user`, set on the user's
    admin page). Requests carry that name so CMS history shows the real person. A user
    with no mapping cannot write.
58. **Workstation is `INSIGHTS`** (setting `cms_write.workstation`), so Insights-made
    changes are easy to pick out in the CMS log.
59. **Program name**: by default Insights sends its own (`Insights QC`, …) so the log
    shows where a request came from. Setting `cms_write.program_mode = cms` sends the
    CMS client's program names instead, in case the server turns out to care. To be
    settled in the sandbox (see `docs/SANDBOX_CHECKLIST.md`).
60. **Local audit table `cms_write_audit`** records who, what operation, the input,
    every request sent, the CMS log id and result of each, and whether the run was
    dry-run or live. Viewable at Admin → CMS → Writes.
61. **Multi-request sequences stop at the first failure** and report which requests
    committed. Sequences that CMS itself makes resumable (the waybill's to-do rows)
    are resumed the same way; nothing is ever "rolled back" by Insights.
62. **Golden tests.** For every template there is a test that rebuilds real logged
    requests from their own values and compares the XML exactly. Fixtures in
    `tests/fixtures/cms-requests/` are sanitised: people → `pic\user1`, workstations →
    `WS1`, company names → `Company N`, codes → `COMPANYN`, addresses, phones, emails
    and freight accounts → placeholders.
63. **QC result entry**: only rows the user changed are sent; one sample container per
    save (as the client does); test time is the moment of confirm; numbers are
    normalised the way the client writes them; pass/fail-only tests send no result
    value. Pass/fail is the tester's call: Insights suggests it from the item's limits
    and warns when the choice disagrees with them, but does not overrule.
64. **QC approve/reject**: only for a sample set still marked as being tested.
    Approving with untested or failed tests is allowed (CMS allows it) but the preview
    carries a clear warning. Approve is refused when the ticket has no in-process-test
    line to complete, since the client never sends that shape.
65. **QC entry screens** (`/qc/queue`, `/qc/sample/{id}`) are visible to anyone with
    at least one QC write permission and hidden (404) from everyone else.
66. **No CMS login/session call.** The client logs `Shell.Login`/`Logout` around its
    work; Insights does not, because those only record client sessions. If the sandbox
    shows the server needs one, it becomes a foundation change, not a per-flow one.
67. **New sales orders take their change number from CMS at send time**
    (`GenerateID 'ChangeNumber'`), never from Insights. Previews and practice mode
    show `{next ChangeNumber}`. The bounds passed (12750, 2147483647) are inferred
    from the numbers in use, not seen in the log: to confirm in the sandbox. Needs
    `EXECUTE` on `dbo.GenerateID`.
68. **Edits never issue a new change number.** The client sometimes sends one with
    a required-date change and sometimes does not, for the same order; no rule can
    be read from the database. Open question for Matt.
69. **A new order copies its defaults the way the client does**: terms, currency and
    tax settings from the bill-to; salesman, incoterms, carrier and freight settings
    from the ship-to, falling back to the bill-to; the ship-to's address as the
    order's own address copy; the bill-to's standing internal memo unless changed.
70. **Item codes on order lines** may be the customer's name for the item or the
    inventory code. An inventory code sold under exactly one name uses that name;
    under several names it is refused with the list, so the user picks.
71. **Price list**: a blank price uses the customer's current price-list row when it
    has a single price break. Rows with quantity breaks, or no row, need a typed
    price. A typed price is a manual price.
72. **Duplicate PO number** for the same bill-to is refused until the user ticks
    "Duplicate PO number is fine"; the request then carries the same event the
    client logs after its own warning.
73. **Required date** is sent as the chosen date plus the time of entry (create) or
    the order's existing time of day (edit), matching the logged requests. A past
    date is refused on create and warned about on edit.
74. **Every non-quote save runs the credit check**, as nearly all logged saves do
    (a few early-version edits omit it; no rule found). New orders are sent with
    over-commit allowed, a constant in every logged create.
75. **Insights does not auto-release a new order.** The client usually follows a
    create with a release request; whether that is the user or the program is not
    visible in the log. Release is its own operation with its own permission.
76. **Edit limits**: quantity cannot be changed on a part-shipped or closed line, a
    shipped line cannot be removed, zero quantity means "remove the line". Those
    cases stay in the client.
77. **Complete sale** sends only the ship-and-invoice request; the client's
    preceding save carries unsaved edits, which Insights never has. It is offered
    only for open orders with an open line and warns that it cannot be undone.
78. **Not built for sales orders** (templates exist, no flow or screen): reopen a
    completed order, change the ship-to address on an order, copy an order, turn a
    quote into an order.
79. **Sales entry screens**: `/sales-orders/new` and `/sales-orders/{order}/edit`
    are hidden (404) without the matching write permission; action buttons on the
    order page show per permission and order status. A refused new order returns
    to the form with what was typed.
80. **Numbers on purchasing requests** are written the way the client writes
    doubles (shortest form that reads back the same: `441`, `44.09`, `7.88`);
    price-list break prices keep their four decimals as stored.
81. **PO quantities are typed in the supplier's ordering units**: whole packages
    where the price list sells the item by the package (`SupQty` packages,
    `QtyReqd` = packages times package size), otherwise the item's own unit.
82. **Price from the price list**: a blank price takes the highest quantity break
    the quantity reaches; below the first minimum the first price is used with a
    warning. Refused, to be done in the client: price per package, a price-list
    unit that differs from the stock unit, another currency than the supplier's,
    quantity breaks on a packaged row, an item listed more than once (unless the
    supplier's own code picks the row).
83. **An item off the supplier's price list** can be ordered only with a typed
    price, and the preview warns about it.
84. **New-PO defaults come from the supplier**: terms, currency, delivery terms,
    usual carrier, address and standing PO memo. The form shows the memo and
    sends whatever is left in the box; an emptied box sends no memo.
85. **Edit limits for POs**: only open POs; quantity cannot be changed on a line
    with a receipt or a closed line; a line with a receipt cannot be removed; the
    price of an existing line is not editable. Those stay in the client.
86. **Complete PO** is sent as a save that sets the status and closes each open
    line, which is how the logged completions look.
87. **Receiving is one line per receipt**, into the receiving location the logs
    use (10669), one container, quantity in the stock unit, packing slip number
    required (20 characters). Cost entries follow the item's treatment as the
    logged receipts do. US-dollar POs only. Over-receipt beyond the allowance and
    a repeated packing slip number warn rather than refuse.
88. **Insights does not close the line or complete the PO after a receipt**; the
    logs suggest CMS does that itself. To be confirmed on the sandbox copy.
89. **Reverse receipt**: single-line receipts, not yet on a supplier bill, on an
    open or completed PO. Anything else stays in the client. There is no
    "correct a receipt" in Insights: reverse it and receive again.
90. **Need to Order hands over to the new-PO form, not to the confirm page.**
    Package rounding, minimums and prices need a look first, so the vendor
    group's "Start a PO" fills the form (need in stock units turned into the
    supplier's ordering units, rounded up to whole packages, with the sum shown
    beside each line) and the user goes on from there. The supplier is matched by
    exact name; if that is not unique the supplier search opens instead.
91. **Purchasing entry screens** are hidden (404) without the matching write
    permission. Not built for purchasing: reopen a completed PO, the no-bill
    flag, receipt correction, multi-line receipts in one save.
92. **Tickets are cut only from a plain bulk recipe**: the item's one published
    bulk recipe, stocked in pounds, one batch and one product, no special
    lines. Hand-built tickets, repacks, rescaling an existing ticket and
    changing or removing a packaging ticket stay in the client.
93. **Ticket quantities** are scaled from the recipe and written to 15
    significant digits as the client does. The batch volume is worked out from
    the ingredients' specific gravities; it matches the logged tickets to about
    ten decimals, and a missing gravity counts as 1 with a warning.
94. **Ticket dates** are the chosen day with the current time of day, as the
    client stamps them. Packaging tickets copy the bulk ticket's dates.
95. **Overcommit defaults to on** for a new ticket, since the logged release
    failures were all cured by switching it on. Insights does not check stock
    before a release; CMS refuses and its message is shown.
96. **A new ticket is a sequence of requests** (ticket, one per packaging
    ticket, release), as the client sends them. Between requests Insights reads
    the ticket back from CMS for its number, version and line ids. If a later
    request fails, the earlier ones stay saved and the user is told which.
97. **Release builds the in-process-test lines from the item's specification
    tests.** An item with none is released with its lot approved, the shape the
    logs show for such items.
98. **Close** is offered for a completed ticket and for one never released
    (which cancels it). A ticket on the floor is closed in the client.
99. **Packaging tickets are added here only before release.** Up to 12 on a new
    ticket. The quantity is a count of the packaged item or pounds of bulk; the
    lines may not draw more than the batch, and bulk left unassigned is a warning.
100. **Customer on a ticket** is typed as the customer number and can be set or
    changed, not cleared.
101. **Recipes**: Insights publishes a bulk recipe drafted in the client and
    retires or reactivates a recipe. Drafting and editing recipes and the
    recipe-replacement job stay in the client.
102. **Scheduling hands over to the new-ticket form, not to the confirm page**
    (as Need to Order does for POs): the run's item, pounds, day and packaging
    split are filled in with release ticked, and the user goes on from there.
103. **Ticket and recipe actions** show only to holders of the matching write
    permission and only when the ticket's state allows them.
104. **Shipping ships what is assembled.** Assembling an order into containers
    stays in the client for now (it belongs with the inventory moves, flow 7).
    The containers and their weights are read from CMS's shipping-label view;
    the weight can be overtyped on the form.
105. **One waybill is one ship-to, up to 6 orders.** Ship via, freight service,
    freight account, PO number and delivery confirmation are copied from the
    first order, as the client does. Tracking numbers and freight cost per
    container are not entered here.
106. **The date shipped is the moment of confirming**, not the moment the form
    was opened (the client uses the latter).
107. **A waybill is several requests**, as the client sends them. After the
    first, Insights reads the to-do rows back from CMS for their ids and the
    waybill's version, and skips a row CMS already shows as done. A waybill
    that stopped part way is listed on `/shipping/new` and on its own page with
    a Finish button; finishing uses the create permission.
108. **Reject is offered only for a completed waybill**, with its own
    permission. An unfinished waybill is finished, not rejected, here.
109. **"Mark labels printed"** records the label address per container the way
    the client's label printer does, but with Insights' normal request header
    (which names the user). Insights does not print the label itself yet.
110. **Kilograms are pounds × 0.45359237.** For a container holding several
    lines the client adds up kilograms line by line, so its last digits can
    differ from ours; the tests compare to 9 decimals.
111. **Packing slip and invoice are read-only print pages** built from CMS's
    own report views, one per order on a waybill. The packing slip comes with
    `shipping.view`; the invoice shows prices and has its own permission,
    `shipping.invoice`. Both use the logo set for the certificate of analysis.
    Lots are not listed on either yet.
112. **Batch execution lives on the batch ticket page**, in an "On the floor"
    card, not in a separate floor screen. Seven operations, six permissions
    (`write.mfx.add_ingredient`, `make_batch`, `send_to_qc`, `packout`,
    `complete`, `undo`; both undos share one).
113. **Buttons follow the ticket's state in CMS**, read when the page loads and
    again when the request is planned: add ingredient and make before the
    make; test, pack out, complete and undo after it. A ticket the flows do
    not handle (not released, finished, a repack, hand-built) shows no card.
114. **A packout is planned as stages chosen from CMS state**: open the
    packaging phase when the bulk product line is not started, release the
    packaging ticket when it is not released, ask for the packout, open the
    production record on the first packout, then pack. A packout is "later"
    when the packaging ticket already has a packout that was not undone; it
    then reuses the container and the cost record.
115. **The user types the quantity made and the number of packages.** The bulk
    drawn and the components used follow the packaging ticket's own
    proportions; they are not typed.
116. **`UnitCostMK` is an estimate** from replacement costs (ingredients for
    the make; the batch cost plus components for a packout), to 6 decimals.
    The client's figure differs in the later digits; which value CMS keeps
    after its own costing is a sandbox question.
117. **Start and end stamps equal the transaction time**; Insights does not
    time the work.
118. **No client warning events are sent** for execution, including the signed
    "maximum quantity exceeded" one on add ingredient. Insights shows its own
    warnings on the review page instead.
119. **The order of the status steps in a make differs from the client's**;
    the set is the same. The golden tests compare them as a set.
120. **Lots of ingredients are not chosen.** The make sends what the client's
    express make sends and leaves lot selection to CMS.
121. **Batches packed directly, without a packaging ticket, stay in the
    client**, both for packing and for undoing.
122. **Undo is refused on a completed or closed ticket**, and undoing the make
    is refused while a packout stands. The client is looser; Insights asks
    for the steps to be undone in order.
123. **Inventory counts live in the Inventory module** (`/inventory/counts`),
    with their own permissions: `write.inv.count` saves, changes and deletes;
    `write.inv.count_post` posts. Posting is the step that changes stock.
124. **A count line is an item code and a quantity in the stock location.**
    Every count in CMS but two lines is at the stock location, with no lot, so
    Insights offers nothing else. Counting a single container or lot stays in
    the client.
125. **The posted adjustment is counted minus on hand, read at the moment of
    the review and again at the confirm**, and written with every digit as the
    client writes it. If stock moved in between, the confirm asks for a new
    review.
126. **"Save and post" is two requests**, as in the client; the post takes the
    ids from the save's reply. If the save lands and the post does not, the
    count is left saved and can be posted from its page.
127. **A saved count's items are fixed in Insights.** Quantities, description
    and date can change; to add or remove an item, delete the count and start
    again. The client's shape for adding a line to a saved count was not in
    the log.
128. **A changed description or date with changed lines is sent as one
    request**: the head `Update` with its `Version`, then the lines. Only the
    two halves were seen separately; flagged in the sandbox checklist.
129. **"Mark labelled" is offered only for containers that hold stock.** CMS
    has thousands of emptied containers still flagged; they are noise.
    Insights does not print container labels, so this is a manual tick.
130. **Recalculating planned dates is its own permission**
    (`write.inv.recalc_plan`); it changes nothing a user typed but touches
    every open order's dates.
131. **"Repack" is not built.** No request under that name was found in the
    log; moving stock between pack sizes goes through a packaging ticket
    (flow 6). Left as a question for Matt.
