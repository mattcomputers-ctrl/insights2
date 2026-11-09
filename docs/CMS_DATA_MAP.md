# CMS data map — Order Readiness & Production Planning

Verified against live CMS (10.10.10.11, read-only `sds_readonly`) on **2026-10-29**
per SPEC-order-readiness §7 / step 6. Every claim below came from a real query
(probe scripts in the session scratchpad; row counts as of that date).
Linked from `SCHEMA_NOTES.md` §6.

## 1. Order types and statuses (verified counts)

`Ordr.Context` → `SH` sales order · `MFBA` batch (bulk) ticket · `MFPP` packaging
ticket · `PO` purchase order.

| Context | Open statuses seen | Closed | Notes (Matt, §14 Q3) |
|---|---|---|---|
| SH   | `RTS` (25), `CSH` (0 today, possible) | `CMP` (16,512) | RTS = released to ship; CSH = open, credit hold |
| MFBA | `RLS` (99) | `CMP`, `CLS` (cancelled) | RLS = released to production |
| MFPP | `NST` (97) | `CMP`, `CLS` | NST = cut, **not released — flag it**; `BAT` = done in production, closing — **flag if >1 day** (none open today but occurs) |

Treat as open supply: MFBA/MFPP with status NOT IN (`CMP`,`CLS`). `NST` and `BAT`
get their flags; per §14 Q6 **no ticket counts as on-hand until it reaches
BAT/CMP** — open tickets are *future* coverage only.

## 2. Open sales orders and lines

- Header: `Ordr` (`Context='SH'`, `Status IN ('RTS','CSH')`): `DateOrdered`,
  `DateRequired`, `DontShipBefore`, `BillTo`/`ShipTo` → `Entities.Entity`
  (`EntityCode`, `EntityName`), `PoNumber`, `Reference`.
- Lines: `OrdDetail` with `Context='SH'`. Key semantics (verified on live rows):
  - **`IsOpen` (bit) marks an open line** — closed lines keep `Status=''`;
  - `QtyReqd` = ordered; **`QtyUsed` = shipped so far**; `QtyCommitted` =
    inventory CMS has set aside. **Remaining = QtyReqd − COALESCE(QtyUsed,0)**,
    only for `IsOpen=1` lines. (Example: line 558919 reqd 361, used 95,
    committed 266, open.)
  - `OrdDetail.Item` → inventory pack item; `OrdDetail.ItemName` → the alias the
    customer ordered under (both join `Item`). Show the alias code/description,
    like Margin Watchdog does.
  - Orders whose lines are all `IsOpen=0` have nothing left to cover and are
    excluded.
- **Units: every open SH line is `lb` and `SupUnit` is empty** (103/103 lines).
  No unit conversion is needed today; the sync still records the unit and the
  coverage engine refuses to mix units rather than guessing.

## 3. Batch tickets (supply)

- **`BatchingOrders` (view) covers MFBA only** — it returned zero MFPP rows
  despite 97 open MFPP orders, so packaging tickets must be read from
  `Ordr`/`OrdDetail` directly.
- Ticket shape (verified on 193335–193337):
  - MFBA order: `OrdDetail` `Context='PK'` line = the **bulk item produced** and
    its qty; `Context='UI'` lines = raw materials.
  - MFPP order: `Context='PK'` line = the **pack item produced** (e.g.
    `E6459-50`, 550 lb); `Context='UI'` lines = the bulk consumed + packaging
    materials.
- So: **pack supply = open MFPP PK lines; bulk supply = open MFBA PK lines.**
- `BatchSheetDemand` (view) links **MFBA → the MFPP orders cut from it**
  (98 rows, all SupplyCtx=MFBA → DemandCtx=MFPP; `CustomerCode` filled when the
  chain was cut for a customer). **There is NO CMS link from a ticket to a sales
  order** — allocation therefore runs purely on order priority (below), and the
  UI shows `CustomerCode` as a "cut for …" hint on the ticket.
- Overdue/stale is real: open MFBA RLS tickets exist with DateRequired a year
  back — the screens must show overdue flags, not hide them.

## 4. Inventory (what can ship right now)

`InventoryTotal` (view), one row per Item+Owner. Columns include `QtySOH`,
`QtyQ1`, `QtyHold`, `QtyUsable`, `QtyRestricted`, `QtyUnusable`, `QtyBooked`,
`QtyOnOrder`, `QtyOpenToSell`.

- Owner 4 is the stocking site (13,847 items); owners 100876/100877 are
  marginal (532 lb total). Sum across owners, matching the Scheduling module.
- Per §14 Q4 "in inventory right now = available to ship". We use
  **`QtyUsable`** (= SOH minus hold/restricted/unusable) as "available", and
  snapshot SOH + hold components so the UI can show why they differ.
  *Flagged for Matt's review (DECISIONS #28): on current data QtyUsable ≈ QtySOH
  almost everywhere, but hold/QC stock should not count as shippable.*
- We deliberately ignore `QtyCommitted`/`QtyOpenToSell` for coverage — Insights
  does its own fair allocation (§8.1) instead of CMS's commitments (Matt's Q4
  answer), while `OrdDetail.QtyCommitted` is still shown as information.

## 5. Minimum stock / discontinued

`ItemEntity` with **`Context='ST'`** is the authoritative row (multiple rows per
item; the `MF` row carries no minimum). Verified on Matt's examples: E4456-50
and E3736-50 both have ST rows with `MinimumStock = 99`. Distribution today:
389 real minimums, **35 × 99 = discontinued**, 7,529 null.

## 6. Recipes ("can we make it?")

`Item.CostingRecipe IS NOT NULL` ⇔ the item has a current recipe
(13,361 items). Pack items carry their own packaging recipe whose UI line is
the bulk — the Scheduling module's `packToBulkMap()` already resolves
pack → bulk this way; Order Readiness reuses it. "Last made" = latest
`DateCompleted` of a completed ticket producing that item (MFPP for packs,
`BatchingOrders` MFBA for bulks).

## 7. Order priority (drag-and-drop)

No CMS field holds Matt's priority; it lives in Insights
(`or_order_priority`): one row per open SH order, position integer. A new order
defaults into position by `DateRequired` (then `DateOrdered`, then order
number); every recalculation (§8.1) allocates strictly in that order.
`Ordr.BasePriority` exists in CMS but is unused for SH orders today (all equal)
— ignored.

## 8. Sync-job queries (what the engine reads, per run)

Eight set-based queries, each filtered to open/current rows only:

1. Items & minimums: `Item` ⟕ `ItemEntity (ST)` for items that are on an open
   order, an open ticket, or have a minimum.
2. Inventory: `InventoryTotal` summed by item (component columns kept).
3. Open sales orders: `Ordr SH RTS/CSH` + `Entities`.
4. Open order lines: `OrdDetail SH IsOpen=1` + alias join.
5. Open tickets: `Ordr MFBA/MFPP NOT IN (CMP,CLS)` + PK line (+ UI bulk line
   for MFPP) + `BatchingOrders` metadata for MFBA.
6. Ticket chains: `BatchSheetDemand` (MFBA→MFPP, CustomerCode hint).
7. Ticket materials: `OrdDetail UI` on open MFBA/MFPP tickets, summed per
   (ticket, item) — the same material can appear twice on one ticket. All
   open-ticket UI items are Context `SUNDRY`; `QtyUsed` is always 0/NULL
   until the ticket completes (verified live 2026-10-29), so the remaining
   requirement is simply `QtyReqd`. MFBA UI lines = raw materials. MFPP UI
   lines = the bulk it packages PLUS its containers (pails/lids/drums —
   also `SUNDRY`, unit 'ea', no recipe). The bulk is identified as the item
   the linked MFBA makes (fallback: the UI item with a CostingRecipe).
8. Last-made dates for suggestion items.

The read-only viewers (Sales Orders, Purchase Orders, Batch Tickets, QC,
Items) query CMS live. Key chains verified live 2026-11-04:
- QC result → batch: `LocationSampleTest` (Result/Passed/TestedTime/
  TestedBy) → `Location` (the SMP sample container) → `Inventory` →
  `Sublot` → `Lot` → `Lot.OrdDetail` → the ticket's PK line; specs per
  item+test in `ItemTest` (Min/Max/Target, OnReceipt/OnProduction/OnRetest).
- PO receipts: `ChangeSetReceipt` (OrdDetail, Item, PSQty, Sublot) →
  `ChangeSet.ChangeDate` (when) and `Sublot`→`Lot` (lot + SupLot).
- Ticket execution: material issues are `InvMovementDtl` Context 'US'
  (negative qty) against the UI line; `OrdDetail.QtyUsed` is maintained as
  lines are issued. Produced lots: `Lot.OrdDetail` → the PK line, lot
  number = `Lot.Lot` (date-coded), `ManfDate` set on production.
- Stock by container: `Inventory` (Sublot, Location, Item, Qty, Status) →
  `Location.LocationCode` + `Sublot`→`Lot` (ReceivedDate/ManfDate/Supplier).
- Driver portability: LIKE escapes use '!' (backslash breaks pdo_dblib's
  emulated-prepare placeholder counting) and TestResult/Memo UDT columns
  (nvarchar(max)-based) must be CONVERTed to VARCHAR or FreeTDS returns
  them empty.

The Need to Order job adds: recipe explosion level by level
(`RecipeDetail` UI rows via `Item.CostingRecipe` — `QtyReqd` is the
fraction per 1 unit of the parent, UI fractions sum to 1.0 on bulk,
pack recipes carry bulk @1.0/lb + containers per lb), purchase history
(`PurchaseOrderDetail` by ItemCode: ANY history = we buy it; most recent
PO's vendor + `TheirCode` shown), and open-PO quantities per material.

All each a few hundred rows; well under the 60 s target.
