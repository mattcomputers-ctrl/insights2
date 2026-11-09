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
60. **Local audit table `cms_write_audit`** holds one row per request. The
    row is written before the request is sent, with status `sending`: who,
    the CMS user, the operation, the run and step, dry-run or live, what the
    user entered (`input_json`) and the exact request. The same row is then
    updated with the outcome: status, CMS log id, result code and text. If
    the row cannot be written the request is not sent. A row that stays at
    `sending` is a request whose outcome was never recorded and must be
    looked up in the CMS log. An attempt refused before any request is sent
    (no permission, stale preview, a refusal while planning) writes no row.
    Viewable at Admin → CMS → Writes.
61. **Multi-request sequences stop at the first failure** and report which
    requests committed. This holds for any failure at any step: CMS
    rejecting the request, a refusal or an error while preparing the next
    request (CMS not readable, backup window starting), the call breaking
    off, or the write log not being writable. Once a request has been handed
    to CMS the result is never reported as "nothing was sent". The
    confirmation is used up before the first request is sent, so it cannot
    be confirmed twice, even if the page dies part way through. Sequences
    that CMS itself makes resumable (the waybill's to-do rows) are resumed
    the same way; nothing is ever "rolled back" by Insights.
62. **Golden tests.** For every template there is a test that rebuilds real logged
    requests from their own values and compares the XML exactly. Fixtures in
    `tests/fixtures/cms-requests/` are sanitised: people → `pic\user1`, workstations →
    `WS1`, company names → `Company N`, codes → `COMPANYN`, addresses, phones, emails
    and freight accounts → placeholders.
63. **QC result entry**: only rows the user changed are sent; one sample
    container per save (as the client does); a first result is stamped with
    the user and the moment of confirm; a correction of a row that already
    has a result sends only the changed columns and no tester or time
    (decision 194); numbers are normalised the way the client writes them;
    pass/fail-only tests send no result value. Pass/fail is the tester's
    call: Insights suggests it from the item's limits and warns when the
    choice disagrees with them, but does not overrule.
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
74. **Every non-quote save runs the credit check**, as nearly all logged
    saves do. The exception is an order on credit hold: its edits and its
    complete are sent without the check, as the client sends them (decision
    196). New orders are sent with over-commit allowed, a constant in every
    logged create.
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
85. **Edit limits for POs**: only open POs; quantity cannot be changed on a
    line with a receipt or a closed line; a line with a receipt cannot be
    removed; the price of an existing line is not editable; a promised date
    can be set or moved but not cleared (decision 197). Those stay in the
    client.
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
    tests**, written in test-name order (decision 199). An item with none is
    released with its lot approved, the shape the logs show for such items.
98. **Close** is offered for a completed ticket and for one never released
    (which cancels it). A ticket on the floor is closed in the client.
    Cancelling closes a repack line last, with its commitment zeroed
    (decision 200).
99. **Packaging tickets are added here only before release.** Up to 12 on a new
    ticket. The quantity is a count of the packaged item or pounds of bulk; the
    lines may not draw more than the batch, and bulk left unassigned is a warning.
100. **Customer on a ticket** is typed as the customer number and can be set or
    changed, not cleared.
101. **Recipes**: Insights publishes a bulk recipe drafted in the client, in
    the four-step form (decision 201), and retires or reactivates a recipe.
    Drafting and editing recipes and the recipe-replacement job stay in the
    client.
102. **Scheduling hands over to the new-ticket form, not to the confirm page**
    (as Need to Order does for POs): the run's item, pounds, day and packaging
    split are filled in with release ticked, and the user goes on from there.
103. **Ticket and recipe actions** show only to holders of the matching write
    permission and only when the ticket's state allows them.
104. **Shipping ships what is assembled.** Assembling an order into containers
    stays in the client for now (it belongs with the inventory moves, flow 7).
    The containers and their weights are read from CMS's shipping-label view;
    the weight can be overtyped on the form.
105. **One waybill is one ship-to, up to 6 orders.** Ship via, freight
    service, freight account, PO number and delivery confirmation are copied
    from the first order, as the client does. An order with a delivery
    confirmation and a freight account or freight service is refused
    (decision 206). Tracking numbers and freight cost per container are not
    entered here.
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
115. **The user types the quantity made and the number of packages, and may
    pick the package taken from stock.** The bulk drawn and the components
    used follow the packaging ticket's own proportions; they are not typed.
    The package is NA (item 1, no stock) unless one is picked; CMS holds no
    default for it (decisions 209 and 210).
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
    client**, both for packing and for undoing. It is a longer request (it
    also completes the bulk-use line and deletes the container it made, Log
    864910): 2,380 of 25,308 logged packouts with Log > 400000, and 322 of
    the 330 that take a real package. Fixture 864910 is kept to show the
    shape.
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
127. **A saved count's items are fixed in Insights.** Quantities,
    description and date can change; to add or remove an item, delete the
    count and start again. The client's shapes are logged but not built:
    adding sends the head step (`UpdateVersion`, or `Update` when the
    description changes too) and then `Insert InventoryCountDetail` with
    keys `InventoryCountDetail="@@identity"`, no subs, and data
    `InventoryCount="<count id>" Item Location QtyEntered Qty` (8 requests,
    e.g. logs 483274, 483288, 483301, 471684); removing sends the head step
    and then `Delete InventoryCountDetail` by id (8 requests, e.g. logs
    476123, 722604). One request (log 559762) does both, and where kinds of
    line step are mixed the order is head step, Deletes, Updates, Inserts.
    Leaving them in the client is a scope choice: both are rare (15 of 1,655
    count saves).
128. **A changed description or date with changed lines is sent as one
    request**: the head `Update` with its `Version`, then the lines. A
    changed description with changed quantities in one request is logged:
    log 386765 (a golden fixture) is exactly that. 4 requests in all have a
    head `Update` with a description followed by line steps; the other three
    (logs 229099, 471684, 559762) also remove or add lines, which Insights
    does not build. A changed date alone is logged once (log 560112, golden
    fixture). A changed date together with lines, or together with a
    description, is in no logged request; that combination is flagged in the
    sandbox checklist.
129. **"Mark labelled" is offered only for containers that hold stock.** CMS
    has thousands of emptied containers still flagged; they are noise.
    Insights does not print container labels, so this is a manual tick.
130. **Recalculating planned dates is its own permission**
    (`write.inv.recalc_plan`); it changes nothing a user typed but touches
    every open order's dates.
131. **"Repack" is not built.** No request under that name was found in the
    log; moving stock between pack sizes goes through a packaging ticket
    (flow 6). Left as a question for Matt.
132. **Assembling is one page in Shipping** (`/shipping/assemble`) with one
    permission, `write.ship.assemble`, for picking into a container and for
    taking stock back out. It is separate from the permission to ship.
133. **Picks come from the stock location and go into a container under the
    receiving location, package type 1**: the only shape in the log (2,886
    requests). The three ids are constants beside the templates.
134. **Quantities are in the item's stock unit.** A line new to the
    container is sent without a `CheckVersion`. In 43,403 of 43,616 such
    logged moves `Quantity` and `QtyEntered` are the same text; in 198 (188
    requests, last Log 863695) `Quantity` is the client's own unrounded
    remainder (`83.699999999999989`) and `QtyEntered` its short print
    (`83.7`); 15 (13 requests) have `Quantity` 0. Insights sends the typed
    number in both; how the client works out the unrounded remainder is not
    established. A line already in the container is sent as a change: a
    `CheckVersion` of the stock location, then a move whose `Quantity` is
    the new total less what the container holds (worked out in floating
    point on the stored quantity, so it can read `-0.69999999999998863`) and
    whose `QtyEntered` is the new total (31 of 31 top-ups, 21 of 21 partial
    take-outs). Taking a whole line out moves minus what is held, with what
    was held as `QtyEntered` (163 of 164; the exception is Log 386134 step
    4). Changed lines go before new ones in one request (Logs 573424,
    833998). Insights adds the typed amount to what the container holds, or
    takes it off, and sends the total. A line held in the container as more
    than one content row is changed in CMS; no logged pick was split that
    way (0 of 43,777 steps), so this is a guard and not a known case.
    Picking in another unit, and choosing the lot, stay in the client; CMS
    chooses the lot as it does for the client.
135. **Insights refuses a pick above what is left on the line** (ordered
    minus shipped minus already in this order's containers) **or above the
    stock on hand** in the stock location. No shipped line in CMS exceeds its
    ordered quantity, so over-picking is not offered. Stock reserved to other
    orders is not subtracted; CMS decides that. Flagged in the checklist.
136. **A container can only be added to, or emptied, through the order it was
    assembled for**, and only while that order is ready to ship. Its
    `Version` is checked, and for a take-out the stock location's too, as the
    client does.
137. **An emptied container is left in CMS**, as the client leaves it. Nothing
    in the log deletes one.
138. **"Take out everything" sends what the container holds at the review**;
    the confirm reads it again and asks for a new review if it changed.
139. **Aliases are added and changed on the item page** (`/items/view`), with
    one permission, `write.item.alias`. The page already lists them.
140. **Alias codes are sent in upper case.** No code in CMS (22,123 items and
    aliases) has a lower-case letter. A code must be new across items and
    aliases; CMS has no duplicates.
141. **A blank description on a new alias becomes the item's description.**
    Every logged alias insert carries a description.
142. **An alias can only stand for a stock item** (contexts PP and SUNDRY):
    all 7,706 aliases in CMS do. Given another alias as the target, Insights
    uses the item behind it; there are no aliases of aliases in CMS.
143. **An alias change sends only the columns that differ**, as the client
    does (225 description-only, 98 re-point-only, 25 code-only requests).
144. **Deleting an alias stays in the client.** Three deletes in the log
    since 600000; a wrong alias can be renamed or moved here. The fixture is
    kept to document the shape.
145. **Three item changes are built; the rest of the item screen stays in the
    client.** Minimum stock, preferred supplier and creating a raw material
    or packaging item each have an exact logged shape. A product that is
    made here is saved with its pack sizes, tests and pack recipes in one
    request, and each pack carries a specific gravity whose last digits could
    not be reproduced, so those are not built.
146. **Minimum stock and preferred supplier are two separate changes**, each
    with its own button, because that is how they appear in the log. No
    request that changes both was found to copy.
147. **The minimum stock is kept on the stock settings row of our own site**
    (`ItemEntity`, Entity 4, Context ST). An item without that row gets one
    (as log 858032); an item with it has the row updated (as log 865429).
148. **A minimum stock of 99 is allowed but warned about**: the item page
    shows such items as discontinued.
149. **Only bought items (context SUNDRY) can have a preferred supplier.** No
    pack size in CMS has one. The supplier must be active. Clearing the
    supplier is not built: no logged request clears it.
150. **New items come in two kinds with fixed settings**, the ones nearly
    every such item in CMS has: raw material (lb, GL group Raw Material,
    group Raw Material, shipped in its container) and packaging (ea, GL
    group Packaging, group Packaging Material). Resale items are split
    between two sets of settings in CMS, so they stay in the client.
151. **A new item is sent with Version 1 on the insert.** The client sent 1
    in 507 of the last 517 creates and other numbers in the rest; the row
    starts at 1 either way.
152. **A new item has no supplier, minimum stock or cost.** The client sets
    those in later saves too (logs 864641 then 864642).
153. **`ConcentrationPercent` is not sent on a new item.** One of the last 517
    creates carried it.
154. **Three customer changes are built: address, carrier and freight
    account, and a new ship-to under an existing bill-to account.** Each has
    an exact logged shape. New bill-to accounts and new suppliers carry
    terms, credit limits and currencies that are decided case by case, so
    they stay in the client, as do memos, second addresses and sales reps.
155. **The customer pages live under Sales Orders** (`/sales-orders/customers`)
    and are shown only to users who hold a customer write permission. There
    was no customer page before; order entry already reads the same data.
156. **A new ship-to needs a name, first address line, city, state, zip code
    and carrier.** Every ship-to created in the logged period had a carrier
    and nearly all had a state. Phone, email, second line and freight
    account are optional and left out of the request when blank, as the
    client does.
157. **A new ship-to is created without a sales rep, country, contact or
    memo.** The client sent a sales rep in 6 of 126 creates and a country in
    1; positions of contact and country in the request were not seen.
158. **Customer codes are sent in upper case** and refused when any entity
    has the code: customers, suppliers and carriers share one code space.
159. **An address change sends only the fields that differ**, compared after
    tidying spaces, so stray spaces in CMS alone are not a change. The
    second line, email and phone can be emptied (sent as an empty value, as
    the client does); name, first line, city, state and zip code cannot.
    The third address line and the country are shown but not changed.
160. **The state is checked against the CMS state table** before the request
    is built, because CMS refuses an unknown one.
161. **A change to a bill-to account is sent as the Bill To screen sends it,
    a change to a ship-to as the Ship To screen does.** The steps are the
    same; only the program name in the client's log differs.
162. **Two price list changes are built: change a line (price, supplier's
    code) and add an item at one price.** These are the two everyday shapes
    in the log (99 single price changes, 113 single added lines). A new
    dated version of a list copies every line and is a deliberate act, so
    it stays in the client, as do the first list of a supplier, deleted
    lines, comments and prices by quantity.
163. **Changes go to the price list in effect today**: the latest one dated
    today or earlier, which is the one purchase order entry reads. When a
    later-dated list exists the page and the review say so; it is not
    touched.
164. **Price lists live under Purchase Orders** (`/purchase-orders/price-lists`)
    behind one permission, `write.price.edit`. There was no price list page
    before.
165. **The price is sent as typed** ("37.80" stays "37.80"), as the client
    does, with at most four decimals because CMS stores four.
166. **A line with prices by quantity keeps its prices**: only its supplier
    code can be changed here. Changing the first price alone could leave
    the breaks out of order.
167. **The supplier's code of a line cannot be emptied here.** The client
    was not seen doing it, so the shape is unknown.
168. **A new line takes the unit of the item, US dollars and a minimum order
    of 1** unless another minimum is typed. Every recent line has the
    item's own unit and every line is in US dollars; a supplier paid in
    another currency is refused. The description defaults to the item's.
169. **A new line is refused when the list already has the item in the same
    package and size** (or loose, when added loose). CMS enforces the same
    rule; refusing first gives a plain message.
170. **Price and code changed together are sent as `Price1` then
    `EntityItemCode`**, the order the two have on an insert. The client was
    only seen changing one column at a time.
171. **One supplier bill operation is built: a new bill against received
    lines** (`bill.create`, permission `write.bill.create`). It is 1541 of
    the 1573 bill requests since log 600000. Changing a bill (28) and
    deleting one (4) stay in the client.
172. **Bills live under Purchase Orders** (`/purchase-orders/bills`): the
    received lines waiting for a bill by supplier, then one supplier's
    lines with the invoice form. There was no such page before.
173. **The user types the invoice total and it must equal the ticked lines
    plus their extra costs.** The client computes the header amount; asking
    for the total as well catches a wrong line or a missed freight charge
    before anything is sent. A difference is refused with the amount.
174. **The header amount is each line rounded to cents plus its extra
    cost.** This reproduces all 1249 bills checked; rounding the sum
    instead fits only 1223.
175. **A line is sent with no values unless an extra cost is typed.** Then
    it carries `AddCost`, preceded by `Amount` rounded to cents when the
    received value has part cents, as the client does (no billed line with
    an extra cost keeps part cents; 351 without one do). An extra cost of
    0 counts as none.
176. **Payment terms come from the purchase order of the first ticked
    line** (643 of 644 recent bill lines match the order; 632 match the
    supplier), falling back to the supplier. Mixed terms are pointed out.
177. **An invoice dated more than 21 days back is refused here.** The
    client records a signed acknowledgement with the user's full name for
    those; they are about 2% of bills and stay in the client. A future
    invoice date is refused.
178. **Only US dollar suppliers, ordinary stock receipts and lines not
    marked pending.** Every bill and every waiting line today is like
    that. Memo and tax on a bill (10 requests) stay in the client.
179. **An inactive supplier can still be billed**, with a note: old
    receipts outlive the supplier.
180. **One invoice correction is built: the freight charge** (`invoice.freight`,
    permission `write.invoice.freight`). Of the corrections logged, 433 of
    591 changed the freight and 3 the PO number; the rest changed something
    else. Freight is the one with a clear rule.
181. **The form is on the invoice page under Shipping**
    (`/shipping/invoice/{trans}`), shown to holders of the write permission
    and hidden when printing. The page needs `shipping.invoice` as before.
182. **Only plain invoices**: US dollars at rate 1, no tax group, every line
    an ordinary shipped order line, one note, one shipment record, a PO
    number held (blank is fine). That is about 2,800 of the 2,892 current
    invoices; taxed ones (85) and those with no PO number value (7) stay in
    the client, which recalculates tax there.
183. **Everything but the freight is carried over from what CMS holds**:
    invoice number, invoice date, document date, sales rep, bill-to, PO
    number, lines, note. The corrected invoice keeps the original dates,
    as all 591 logged corrections do.
184. **The reversal carries the old freight negated with four decimals**
    (`-338.2600`) and the new invoice carries the typed amount without
    spare zeros (`510.69`), as the client writes them.
185. **Taking freight off (0) is refused.** No logged request shows whether
    the client then omits the charge or sends 0. The same amount again is
    refused too, and anything above 50,000.
186. **An invoice already reversed, or itself a reversal, shows no form.**
    The current invoice is the one no reversal points at.
187. **An invoice more than 31 days old gets a note, not a refusal.**
    Nothing in the data marks an invoice as closed to correction; whether
    the books can take it is a question for accounting.
188. **Returns to a supplier stay in the client.** Three returns and one
    undo in the whole log are too few to derive rules from.
189. **Order Readiness has the one-click buttons too.** On the orders list
    a row that is fully covered by stock shows Release from credit hold…
    when the order is on credit hold and Complete sale… when it is
    released, to holders of `write.sales.release_order` and
    `write.sales.complete_sale`. They post to the sales order actions, so
    the review page and every check are the same; the list is a snapshot,
    and the review reads CMS afresh. After confirming, the user lands back
    on the Ready to ship tab. Complete order (closing without shipping)
    stays on the sales order page only.
190. **The audit row is written before the send.** Each request gets its
    `cms_write_audit` row first, with status `sending`, and the same row is
    updated with the outcome. No row, no send. If the outcome cannot be
    recorded after a send, the row stays at `sending` as evidence, the step
    is reported as saved in CMS (with its log id) but with an incomplete
    write log, and the rest of the sequence is not run. A dry run takes the
    same path and still ends as one row per request with status `dry-run`.
    Migration 022 adds `input_json`, `cms_user` and `finished_at`.
191. **A run always ends in a report.** Once the runner starts, nothing is
    thrown: any error in the already-done check, in building the next
    request, in the transport or in the write log becomes a step in the
    result, with the saved steps and their log ids listed. A step counts as
    sent once it was handed to CMS; a dry run, and a request the write
    connection could not even start, count as not sent. Errors before the
    first request is handed to CMS may be reported as nothing sent; after
    that they never are.
192. **A confirmation is used up before anything is sent.** Confirm takes
    the pending write out of the session and saves the session before the
    run starts, so a page that dies part way (time limit, memory, a stopped
    worker) leaves nothing that could be confirmed again. The token is put
    back only when it is certain nothing was sent: a stale preview, or CMS
    not readable while re-checking. A second confirm of a used token is told
    the page expired or was already used, and to check the record before
    starting again. Confirm also runs with a 600 second limit and carries on
    if the user closes the tab.
193. **Finding the log row after a failed call uses the CMS user and never
    guesses.** When the call errors without handing back its log id, the row
    is looked up by application, action, workstation, transaction time and
    `LoggedInUser`, above the high-water mark taken before the call.
    `LoggedInUser` equals the request's User on every row that has one
    (6,357 of 6,357 above log 860000; all 1,455 failed rows above log
    600000). All Insights users share one workstation name, so the user is
    the only thing that tells two people apart. In the client's own log
    8,545 groups of rows share application, action, workstation and second
    (always one user per group: 8,349 with a user, 196 with none), so
    same-second repeats are normal and more than one match is never guessed
    at. Exactly one match is taken as this request; none or more than one is
    reported as unknown, to be checked in the CMS log.
194. **QC corrections keep the original tester and test time.** When a test
    row already has a result in CMS (its `TestedTime` is set), saving a
    change sends only the columns that changed: `Result` if the value
    changed, `Passed` if pass/fail changed, and never `TestedBy` or
    `TestedTime`. A row with no result yet gets the full stamp (`Result`
    unless empty, `Passed`, `TestedBy`, `TestedTime` = moment of confirm),
    whatever its Version. Evidence: 32 successful saves touch a row at
    Version 2 or higher (33 rows); of the 31 corrected rows 19 send only
    `Passed`, 9 send `Result` and `Passed`, 3 send only `Result`, none sends
    a tester or time, and the live rows still carry the first save's time.
    The one re-stamp at Version 3 (Log 262510) follows a save that had
    cleared the row (Log 262509). Clearing a result back to untested is seen
    once only and is not offered in Insights: it is done in CMS. CMS has
    only pass/fail and numeric tests (Test: 18 BOOL, 18 NUM;
    LocationSampleTest: 29,668 BOOL, 44,653 NUM). The client also lets a
    result be typed on a pass/fail test and clears it with `Result=""` (Logs
    554075/554076, 273606; two of the three Result-only corrections);
    Insights does not offer this: it never changes the result text of a
    pass/fail test, so that is done in CMS. A test that is neither pass/fail
    nor numeric is refused. The flow reproduces Logs 804783, 782795, 579957,
    334822 and 262510 exactly; 554076 is covered at template level only
    (from the same prior state the flow sends that log without its step 4).
195. **A sales order edit sends removed lines first.** One save sends the
    header step, then removed lines (`Delete OrdDetailPricing`, `Delete
    OrdDetail` per line), then changed lines, then new lines, then memo
    steps, then taxes and the credit check. Evidence: of 21,191 logged sales
    order edit requests, 35 contain both a line delete and a line update and
    all 35 have every delete before the first update; 10 of those also add
    lines, always after the updates; no request has a delete after an update
    or insert, an update after an insert, or a line step after a memo step
    (11 have memo after lines). Fixtures 856662 and 847291.
196. **An edit or a complete of a sales order on credit hold is sent without
    the credit check.** When the order is on credit hold (Status `CSH` or
    `CreditHold` set) both are sent with the tax step only, no
    `CheckOrderCredit`, the same as for a quote. An edited order stays on
    hold until Release from credit hold; a completed one becomes `CMP` and
    keeps its `CreditHold` mark. The review page says so in both cases.
    Evidence for edits: all 41 edits without `CheckOrderCredit` after Log
    835000 are on non-quote orders and are followed by a release as the next
    credit request on the same order (e.g. 856537 and 856662 then 856663;
    847292 then 847567; 862342 and 862343 then 862345); since Log 600000 it
    is 320 of 327, the other 7 being quote conversions (677466-677468,
    729163, 804159) and one held order that was completed without a release
    (661753, 662525). Edits made straight after a release carry the check
    (847291, 842624, 857165, 862341). Evidence for completes: of 2,035
    successful complete requests, 2,028 carry the credit check and 7 do not
    (190165, 428696, 439420, 560604, 562611, 637565, 662525); six of those
    seven orders are the only orders now `CMP` with `CreditHold` set. 428696
    and 439420 are the plain complete shape and are fixtures. The order's
    hold state at the time of a logged request cannot be read back directly,
    so the rule is inferred from request sequences and from the `CreditHold`
    flag left on the completed orders.
197. **Clearing a promised date on a purchase order line is not built.** The
    PO edit can set a promised date or move it to another date (the logged
    shape: `OrdDetail Update` with `DatePromised="yyyy-mm-ddT00:00:00"`,
    1,359 successful requests, fixture 852597). Blanking a date that exists
    is refused with a message to clear it in CMS, and the form marks the
    field as required when the line has a date. Evidence: across the whole
    log and every action, 0 requests contain `DatePromised=""` and 0 carry a
    1900-style promised date; of 8,810 successful
    `PurchaseOrderDataSet.SaveChanges` requests, all 4,028 that mention
    `DatePromised` carry a real date. The client does send an empty date
    attribute in one other place (`DateCompleted=""` on the `Ordr Update`
    when a PO is reopened, 40 requests), but that is a different field on a
    different table and does not prove the line-level case.
198. **Ticket creation copies recipe ingredient and instruction lines in the
    order the recipe stores them** (RecipeDetail id order), not in Line or
    ExecOrder order. Evidence: over all bulk tickets 0 of 135,256
    consecutive ingredient pairs and 0 of 40,874 instruction pairs are out
    of RecipeDetail order, while 9,160 ingredient pairs are out of Line
    order and 1,654 instruction pairs out of ExecOrder order (Ordr > 192000:
    333 of 4,434 and 53 of 1,382). AltID gives the same order in every
    recipe and is not used. Fixture 866471.
199. **Release writes specification tests in order of test name**, compared
    byte by byte, not in `ItemTest.Line` order (3,771 releases with tests in
    Log 780001-866473, no counter-example; fixture 865641). A release is
    refused in Insights when two test names first differ at a character
    outside A-Z, 0-9 and space, or are equal, because only that ordering is
    evidenced in the log.
200. **Cancelling a never-released ticket closes a repack line last, with
    its commitment zeroed.** The request closes the product line, then the
    ingredient lines by Line, then the repack ingredient line
    (`IsRepackaging=1`, no Line, no Parent) with `QtyCommitted="0"`.
    Evidence: 92 `CloseOrder` logs all time, 0 failed, 8 with
    `QtyCommitted="0"`, always on the repack line and always the last line
    step (fixtures 852174, 858233). Refused in Insights: more than one open
    repack line, an ordinary open ingredient line with no Line, duplicate
    Line numbers among open ingredient lines. The close template takes the
    repack lines as a separate list.
201. **Publish keeps the four-step shape.** The client's extra step that
    rewrites the batch line's `TotalWeight` (710 of 1,143 Batching Recipe
    publishes since Log 780000, 57 distinct values) is deliberately not
    sent, because its trigger and value cannot be reproduced from stored
    data. The batch line keeps the weight it was drafted with. The golden
    test asserts that fixture 866297 is not reproduced.
202. **A line already in the chosen container is never picked a second
    time.** Insights adds the typed quantity to what the container holds and
    sends the client's change shape (`CheckVersion` of the stock location,
    `Quantity` = new total minus held in double arithmetic, `QtyEntered` =
    new total; 31 of 31 logged top-ups). Changed lines are sent before lines
    new to the container (Logs 573424, 833998).
203. **On a take-out CMS is told what stays in.** Part out: `Quantity` is
    what stays minus what is held, `QtyEntered` is what stays (21 of 21).
    Whole line out: `Quantity` is minus what is held (the exact stored
    float), `QtyEntered` is what was held to 15 significant digits (163 of
    164; the exception is Log 386134 step 4).
204. **A top-up or take-out of an order line that sits in the container as
    more than one content row is refused**, with a message to do it in CMS.
    That includes "Take out everything" when any line is held that way. No
    logged pick was split over rows (0 of 43,777 steps), so this is a guard
    and not a known case. A line whose item differs from the one in the
    container is refused with the message "Order N has changed in CMS. Open
    it again" on the page.
205. **A pick of a line new to the container carries the typed number as
    both `Quantity` and `QtyEntered`** (43,403 of 43,616 logged moves). The
    client sometimes sends its own unrounded remainder as `Quantity` with
    the short print as `QtyEntered` (198 moves in 188 requests, last Log
    863695; e.g. `83.699999999999989` / `83.7`), and 15 moves have
    `Quantity` 0. The rule behind the unrounded value is not established, so
    Insights does not imitate it.
206. **Waybill header order is `ShipVia`, `FreightService`, `FreightOption`,
    `FreightAccount`, `DeliveryConfirmation`, `PoNumber`.**
    `DeliveryConfirmation` sits directly before `PoNumber` in 15 of 15
    logged waybills that have it (fixture 863747). It never occurs with
    `FreightAccount` or `FreightService` (0 of 15 waybills, 0 of 15 orders),
    so an order that has a delivery confirmation together with a freight
    account or freight service is refused and shipped in CMS.
207. **Resume requests are golden-tested against the only three real ones in
    the log.** The three `WaybillDataSet.ProcessOutstandingRequests` rows
    (Logs 299822-299824) are fixtures. The flow comparison ignores the
    envelope Owner, because those were logged under owner 100877.
208. **Only stock items and packaged products can be added as an ingredient
    on the floor** (`Item.Context` SUNDRY or PP). Every successful
    `NewIngredient` request in the log names one of the two and nothing
    else: 13,904 in all (12,582 SUNDRY, 1,322 PP). An alias code (Context
    NAME, 7,705 of them, each pointing at a real item through `ReplacedBy`)
    is refused with the code it stands for; a package (26), a prototype (36)
    and any other kind are refused, to be added in CMS.
209. **The package taken from stock at a packout is the user's choice, NA by
    default.** CMS stores no default: the product line, the item, the recipe
    line and the container all have no package type that explains it, and
    the same ticket line was packed with a pail and then with NA twelve
    seconds apart (Logs 797611, 797613). On packaging tickets NA is used
    22,920 times against 8 with a real package (Log > 400000). Insights
    sends NA unless the user picks a package item (`Item.Context` PACKAGE,
    not obsolete) on the packout form; the shape with a real package is the
    one of Log 814606. The package count stays a typed whole number: one
    package move per packout, always first, always whole, in 1,249 of 1,249
    requests (Log > 845000).
210. **What a packout uses is sent in item order.** The component moves and
    the bulk draw go in ascending Item id, each followed by its
    quantity-used step: 1,124 of 1,124 packaging-ticket packouts with Log >
    845000. Ticket line order fails 202 of them and components-then-bulk
    fails 217. No logged packout has the same item on two lines, so a
    packaging ticket built that way is refused and packed in CMS.
211. **A new packaging item is always created stock tracked**
    (`Treatment="WHS"`), like a raw material, and the review says so. In the
    client the treatment is chosen per item: 678 of 685 raw material creates
    sent WHS, but the 91 packaging creates are split 49 WHS to 42 NONE, with
    both values sent by the same user minutes apart (logs 153646 and 153647;
    740418/740419 NONE then 740445/740446 WHS). Packaging items of the kind
    Insights creates (Context SUNDRY) in CMS today are 43 WHS and 40 NONE; a
    further 26 with Context PACKAGE are all NONE. Insights does not offer
    the choice: the review warns that the item is created with its stock
    tracked and that an untracked packaging item is created in CMS. The
    packaging create is golden-tested against log 501743 (one of 5 packaging
    creates with no supplier, no concentration, no stock settings row and
    WHS; three of them were compared and all match the template byte for
    byte apart from the Version on the insert, decision 151).
212. **Count edits are golden-tested for four head shapes**: lines only
    (`UpdateVersion` head), description only, date only (log 560112, the
    single logged date change) and description with changed quantities (log
    386765, the one logged request that is exactly this; three more have it
    together with removed or added lines). A date change combined with lines
    or with a description is in no logged request and is still sent as one
    request by Insights; it stays on the sandbox checklist.
