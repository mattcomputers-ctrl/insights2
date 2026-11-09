# CMS write pipeline — how Mar-Kov CMS actually writes (reverse-engineered from its own logs)

*2026-11-09. Read-only investigation with `sds_readonly`; no changes made. Source
material: the `Log*` tables in the CMS database, Matt's LogView/LogResultView
exports, and live sampling of request XML. This is the blueprint for the
eventual Insights write phase.*

## TL;DR

CMS is a **two-tier fat client**: every workstation talks directly to SQL
Server, and **every write in the entire system goes through one stored
procedure**:

```sql
ProcessXMLRequest(@Request nvarchar(max), @LogID int OUTPUT, @ReturnLogResult bit)
```

The client composes a declarative **XML transaction script** (a `<request>`
with ordered `<action>` steps), the proc executes it atomically, logs
everything, and returns the new `Log` id. All business logic, validation,
optimistic locking, audit and rollback live server-side in stored procedures
(encrypted `WITH ENCRYPTION`, so not readable — but their *behavior* is fully
observable through the logs).

**Implication:** a future write-capable Insights doesn't need to reimplement
CMS's business rules. It can speak the same protocol — compose the same XML
and call `ProcessXMLRequest` — and get validation, costing, audit logging,
atomicity and optimistic locking for free, indistinguishable from a CMS
client. 707,852 logged requests since 2014 form a complete corpus of worked
examples for every operation.

## The log tables

| Table | Rows | Role |
|---|---|---|
| `Log` | 707,852 | One row per request: full `Request` XML, `ResultCode` (0 = success), `ResultText` XML (the error, on failure), user, workstation, app/program/action, start/end time, `Owner` |
| `LogResult` | 26.5M | Per step: the **output keys** each action produced (`TableName`, `FieldName`, `FieldValue`) — e.g. the new identity of an inserted row |
| `LogRequest` | 2.2M | **Server-side cascade actions**: `<action>` elements the procs executed *as a consequence* of a client step, tagged with the originating `Log` + `Step` (e.g. MarkOrderComplete → the derived `Ordr`/`OrdDetail`/`Location` updates) |
| `LogCompleted` | 701,486 | Commit marker, written last. A `Log` row **without** one = failed/rolled back. Verified: ResultCode 0 → present, ResultCode 50000 → absent |
| `LogTransDate` | — | Log → transaction date index |
| `LogChange` | 0 | Unused here |
| `LogView` / `LogResultView` | views | What the in-app Log Viewer (and Matt's exports) show; `LogResultView.Description` resolves keys to labels (ChangeSet 139046 → "COUNT") |

Traceability is bidirectional: **`InvMovement` carries `Log` and `Step`
columns**, so every inventory movement knows exactly which request step
created it, and every logged step's effects can be found in the data.

## Request XML grammar

```xml
<request Application="Chemical Management System" AppVersion="7.15.0.680"
         Program="Shipping Order" Action="ShippingOrderDataSet.SaveChanges"
         Workstation="CS-PC" Owner="4" User="pic\kackermohr"
         TransDate="2026-11-09T09:02:33">
  <action Object="Ordr" Step="1" Operation="Insert">
    <keys Ordr="@@identity" Version=""/>
    <data Context="SH" Owner="4" DateOrdered="..." Status="RTS" .../>
  </action>
  <action Object="OrdDetail" Step="2" Operation="Insert">
    <keys OrdDetail="@@identity"/>
    <subs FromStep="1" FromTable="Ordr" FromKey="Ordr" Section="data"/>
    <data Context="SH" Item="113784" QtyReqd="72" Price="20.01" .../>
  </action>
  ...
</request>
```

- **`<request>` attributes**: identity/audit envelope. `Owner="4"` =
  PRECISION INK (the owning company), `User` = Windows domain account,
  `TransDate` = business timestamp.
- **`<action>`**: one step. `Object` = table name (for CRUD) or stored proc
  name (for Execute). `Step` numbers order the pipeline and are the handles
  for substitution.
- **`<keys>`**: primary key (and `Version` where applicable).
  `@@identity` means "generate and capture the new identity" — the captured
  value lands in `LogResult` and becomes available to later steps.
- **`<data>`**: column → value payload.
- **`<subs>`**: the pipe between steps. *"Take the value that `FromStep`
  produced (result table `FromTable`, field `FromKey`) and inject it into
  this action's `Section` (`keys` or `data`), optionally renamed via
  `ToName`."* E.g. step 3 creates a ChangeSet; step 4's
  `<subs FromStep="3" FromTable="ChangeSet" FromKey="ChangeSet" Section="data"/>`
  hands that new id to MoveInventory.

### Operation vocabulary (complete — from shredding recent 3,000 requests)

| Operation | What it does |
|---|---|
| `Insert` | Insert row into `Object`; `@@identity` keys are captured into LogResult |
| `Update` | Update row by `<keys>` with `<data>`; with `Version` in keys, also an optimistic-lock check+bump |
| `UpdateVersion` | Bump the row's `Version` **only** — an explicit optimistic lock ("I'm editing this aggregate") |
| `CheckVersion` | Assert the row's `Version` is unchanged — no write |
| `Delete` | Delete by keys |
| `Execute` | Run business stored procedure `Object` with `<data>` as parameters |
| `Log` | Journal-only entry (events/signatures) |

Optimistic concurrency: core tables (`Ordr`, `OrdDetail`, `Item`,
`InventoryCount`, `Location`, `SampleSet`, `LocationSampleTest`, `Recipe`, …)
carry an int `Version` column. The client reads a row at Version *n* and
sends *n* back; a mismatch fails the whole request.

### Error handling

Business validation RAISERRORs inside the procs; the request **rolls back
atomically**, `ResultCode` = error number (e.g. 50000), `ResultText` =
`<Result Level="16" State="1" Procedure="ProcessXMLRequest" Line="171"
Message="Quantity of the item '2UW0178' is negative"/>`, and no
`LogCompleted` row is written.

## Worked examples (all verified live)

### Inventory count post (Log 866081 — Matt's example)

1. `InventoryCount` **UpdateVersion** (lock the count header)
2. `InventoryCountDetail` **Update** — `QtyAdjust="-5"`
3. `CreateChangeSet` **Execute** — Context `COUNT` → **ChangeSet 139046** (captured)
4. `MoveInventory` **Execute** — subs in the ChangeSet; `SrcLocation=10669 Quantity=5 Item=123115`
5. `InventoryCount` **Update** — `Posted="true"` (subs ChangeSet in)

Effects found in data: `InvMovement 686158` (Context COUNT, ChangeSet 139046,
**Log 866081 Step 4**) → `InvMovementDtl` Qty **-5.0**, Value **-47.7166**,
costed from `InventoryCost 81495`.

### Sales order creation (Log 866073)

`Ordr` Insert (`@@identity`) → `OrdDetail` Insert per line (subs Ordr) →
`OrdDetailPricing` Insert per line (subs OrdDetail) → `MemoRef` Insert
(generic `TableName="Ordr"` + `TableID` via subs `ToName` — the internal-memo
pattern) → `AddressRef` Insert (ship-to address snapshot) →
`UpdateOrderTaxes` Execute → `CheckOrderCredit` Execute.
Results: Ordr 194773, OrdDetails 566264/566265, pricings 54385/54386.

### Purchase receipt (Log 865467)

`Ordr` UpdateVersion → `CreateChangeSet` Execute (Context `PO`, Shipment) →
`ChangeSetReceipt` Insert (keyed by OrdDetail, subs ChangeSet) →
`CreateInventoryCost` Execute (Item, Qty, UnitCost) → `MoveInventory` Execute
(subs ChangeSet **and** InventoryCost; TrgLocation, TrgOrdDetail) →
`UpdateOrdDetailQtyUsed` Execute → `CreateUpdateBillDetail` Execute →
`NotifyPurchaseReceiptCreated` Execute.

### Batch ticket release (Log 866093)

`Ordr` Update (Status `RLS`, DateReleased, Version check) →
`UpdateOrderQtyCommitted` → `ModifyMFOrderProcedureDetails` →
`NotifyMFOrder` (NotificationCode "MFO Released Notification").

### QC result entry (Log 866090)

`SampleSet` UpdateVersion → `Location` **CheckVersion** (sample location must
be unchanged) → `LocationSampleTest` Update per test
(`Result`, `Passed`, `TestedBy`, `TestedTime`).

## Action catalog (what the system actually does)

Top client actions by volume: `WaybillDataSet.SaveChanges` (74k),
`ManufacturingOrderDataSet.SaveChanges` (63k) / `.ReleaseOrder` (60k incl.
express), `ShippingOrderDataSet.SaveChanges` (38k),
`BatchExecutionDataSet.*` (Packaging / PackoutRequested /
BatchExecutionExpress / MarkOrderComplete / ExecutePackagingPhase /
InProcessTesting — the whole ticket-execution flow),
`TestsDataSet.SaveChanges` (27k), `ReleaseDataSet.Approve` (27k sublot
dispositions), `ItemUpdateDataSet.SaveChanges` (15k),
`PurchaseReceiptDataSet.SaveChanges` (9.5k),
`PurchaseOrderDataSet.SaveChanges` (8.8k), `InvoiceDataSet.SaveChanges`,
`PriceVersionUpdateDataSet.SaveChanges`, `EntityUpdateDataSet.SaveChanges`,
`InventoryCountDataSet.Post`, …

Most-used Execute objects (business procs): `MoveInventory`,
`UpdateManufacturingOrdDetailExecStatus/QtyUsed/PreweighStatus/QtyMade/PackoutStatus`,
`UpdateManufacturingOrderStatus`, `CreateChangeSet`, `CreateInventoryCost`,
`CreateLot`/`CreateLotComponent`/`CreateLotIngredient`, `CreateRelease`,
`CreateSamplingRequest`, `CreateMFReceipt`, `CreateContainer`,
`CreateLocationRecord`, `ExecutePackagingPhase`, `PackoutRequested`,
`MarkManufacturingOrderComplete`, `CreateAssembly`,
`CreateShipmentsByOrder`, `UpdateOrderTaxes`, `CheckOrderCredit`,
`PublishRecipe`, `ReverseChangeSet`, `ReverseReceipt`, …

## What this means for the Insights write phase

1. **Don't write to tables; speak the protocol.** Compose the same request
   XML and call `ProcessXMLRequest`. Everything we were worried about
   hand-rolling — costing, lot genealogy, QtyCommitted math, credit checks,
   notifications, audit — is inside the procs and comes for free. Writes are
   atomic and fully logged like any CMS client's.
2. **The corpus is the spec.** For any operation we want to support, pull a
   handful of real examples from `Log` (filter by `Action`), diff them, and
   template the XML. Validation behavior is discoverable from failed logs.
3. **Minimum requirements:** a SQL login with EXECUTE on
   `ProcessXMLRequest` (the write phase's scoped login — *not* `sds_readonly`);
   correct `Owner`; current `Version` values read before writing; honest
   `Application/Program/User/Workstation` attribution (e.g.
   `Application="Precision Ink Insights"`) so the Log Viewer shows exactly
   who did what.
4. **Safety rails already exist server-side** (negative-stock errors,
   version conflicts, credit checks). Our additional rails: template-only
   XML (no free-form), per-operation allowlist, dry-run against a restored
   copy first, and the `@LogID` output + `LogResult` give us immediate
   verification of what happened.
5. **Seats are decoupled from the write path** — see the dedicated section
   below. The API data path does not acquire or require a seat, so the
   writer design does not need seat scheduling.
6. **Open questions for later:** whether any flows bypass
   ProcessXMLRequest (none observed); exact `Version` semantics on insert
   (`Version=""`).

## Seat model — verified decoupled from the write path (2026-11-09)

### The seat machinery is four isolated objects

| Object | Role |
|---|---|
| `LicenseSession` (table) | one row per live seat: Area, Workstation, User, LoginTime, ExpiryTime |
| `LicenseSessions` (view) | read-over of the table |
| `TerminateLicenseSession` (proc) | frees a seat — what `Shell.Logout` calls |
| `Shell.Login` (action) | the **only** thing that inserts a seat; where the "pool full?" refusal fires |

A seat is acquired by `Shell.Login` (itself a one-step ProcessXMLRequest
call), released by `Shell.Logout` or by ExpiryTime lapsing (~56 min after
the last renewal). When the pool is full the **login** is refused and the
client journals `Login.LicenseUnavailable`.

### The write engine never touches the seat table

Verified against `sys.sql_expression_dependencies`:

- Objects that reference `LicenseSession` at all: only `LicenseSessions`
  (the view) and `TerminateLicenseSession` (logout). Nothing else.
- `ProcessXMLRequest`, `MoveInventory`, `CreateChangeSet`,
  `ExecuteBatchPhase` reference `LicenseSession` / any license object
  **zero** times.
- In 12 years of logs the only licensing-related actions are `Shell.Login`,
  `Shell.Logout`, `Login.LicenseUnavailable`. There is **no heartbeat
  action** — seat renewal happens entirely on the client/login side, never
  as a side effect of a data request.
- Corroborating behavior: a data write (`…SaveChanges`, `.Post`) carries no
  Login step, and `ProcessXMLRequest` was observed executing requests for a
  user with no seat (the LicenseUnavailable journaling itself).

**Conclusion:** issuing reads/writes through `ProcessXMLRequest` without a
`Shell.Login` never creates a `LicenseSession` row. Seats are not a
technical constraint on the API path; the writer needs no seat scheduling,
off-hours windows, or retry-on-LicenseUnavailable logic.

### Seat-demand profile (reference, if a design ever chooses to run through `Login`)

Kept for the case where the integration deliberately logs in to appear as a
normal client. Human load on the shared 5-seat pool (last 90 days,
server-local):

- **Active:** weekdays ~7am–5pm, ramp at 8am, **peak 11am–3pm (busiest 2pm)**.
- **Free:** before 7am, after 6pm, and all weekend (Sat/Sun ~13–28 requests
  all day) — all 5 seats idle.
- **Actual saturation** (`Login.LicenseUnavailable`) only ever lands 11am–3pm,
  peaking at 2pm; intermittent — 102 refusals across 22 days in the last
  12 months.
- A request's DB work averages ~460 ms (max ~46 s for a heavy batch
  execution), so a login-bracketed write burst would hold a seat for seconds.

So a seat-holding design would schedule bulk work off-hours (zero
contention) and burst interactive writes through one short-lived seat — but
per the decoupling finding above, that is not required for the API path.

## Open follow-ups

- Diff multiple examples per flow to find optional vs required attributes
  (e.g. Ordr insert with/without `IsQuote`, `Tax1Number`).
- Map `IDGenerator` (counter table) usage vs `@@identity` — lot numbers and
  order codes may come from it inside the procs.
- `LogSignature` / `LogEvent` (e-signature + event journaling) if we ever
  touch dispositions.
