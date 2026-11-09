# Purchasing and accounts payable: CMS write requests

Research date 2026-11-09. Source: `CMS.dbo.Log` (+ `LogResult`, `LogRequest`), read-only SELECTs.
Fixtures: `tests/fixtures/cms-requests/<Action>/<Log>.xml` (list and sanitisation in section 8).

Conventions used below

- "Request" = one `ProcessXMLRequest` call = one `Log` row. `<action>` children always appear in the order `<keys>`, `<subs>`, `<data>`; any of the three may be absent. `<event>` children, when present, come before all `<action>`s.
- Attribute lists are in the exact order the client emits them. `[x]` = optional attribute (omitted entirely when not applicable; never sent empty unless shown as `=""`).
- Dates: `yyyy-MM-ddTHH:mm:ss` local time, no zone. Date-only fields are sent as `yyyy-MM-ddT00:00:00`.
- Numbers: .NET double round-trip formatting, no trailing zeros (`7.88`, `1350`), binary float artefacts are sent verbatim (`440.90000000000003`, `-44.090000000000032`). Decimal(…,4) master-data values copied from a table keep 4 dp (`Price1="7.8800"`).
- Booleans: lowercase `true` / `false`.
- Header: `<request Application="Chemical Management System" AppVersion="7.15.0.680" Program="…" Action="…" Workstation="…" Owner="4" User="pic\<login>" TransDate="<client now>">`. Owner 4 = PRECISION INK.

## 0. Volumes and the one-request rule

Whole-table counts (failed = has an error in LogResult/LogCompleted):

| Action | Program | Count | Failed |
|---|---|---|---|
| PurchaseReceiptDataSet.SaveChanges | Purchase Receipt | 9477 | 3 |
| PurchaseOrderDataSet.SaveChanges | Purchase Order | 8811 (745 since Log 766353) | 0 |
| BillUpdateDataSet.SaveChanges | Bill Entry (Inventory) | 4813 | 16 |
| PurchaseReceiptDataSet.ReverseReceipt | Purchase Receipt | 20 | 2 |
| ReturnToSupplierDataSet.SaveChanges | Return To Supplier | 17 | 0 |
| PurchaseOrderDataSet.MarkComplete | Purchase Order | 8 (last = Log 659996) | 0 |
| ReturnToSupplierDataSet.ReverseReturn | Return To Supplier | 3 | 0 |

**Every user "save" in all of these is exactly ONE request.** Verified by looking at neighbouring Log rows (same workstation, +/- 5 s) for many examples of each action (e.g. PO 864775/864786/863603/863605; receipts 866356-866358, 862511, 801462; bills 863067/863069/862429; returns 821709/778884/778886): no companion requests. Rows that are close together are separate user saves (e.g. PO 832451 and 832452, 5 s apart; receipts 866356/866357/866358 are three separate saves, one per PO line).

LogRequest (server cascade) rows: none for PO saves or bills; one for receipts/reversals (OrdDetail update); several for ReverseReturn (section 6).

---

## 1. PurchaseOrderDataSet.SaveChanges

### 1.1 Create PO

Plain English: buyer picks a supplier, adds one or more lines (item, supplier quantity, optional promised date; price defaults from the supplier price list), optionally edits the PO memo, saves. PO number = `Ordr.Ordr` identity.

Sequence (one request; steps numbered consecutively):

1. `Ordr Insert`
2. per line: `OrdDetail Insert`, then `OrdDetailPricing Insert`
3. `AddressRef Insert` (Reference `SupplierAddress`)
4. optional `MemoRef Insert` (Reference `POMemo`)
5. `AddressRef Insert` (Reference `ShipToAddress`)

Recent distribution: 1 line/no memo 198, 1 line/with memo 82, the rest multi-line. Examples: 864775 (1 line, no memo), 863603 (1 line, memo, packaged pricing), 858290 (tier price), 846034 (manual price).

**Step `Ordr Insert`**

- keys: `Ordr="@@identity" Version=""`
- data: `Context="PO" Owner="4" Entity Currency [Terms] [Incoterms] [ShipVia] PlacedBy CancelBackorder="false" DateOrdered DateReleased`

| Attr | Source |
|---|---|
| Entity | supplier `Entity.Entity` |
| Currency | `Entity.Currency` (USD) |
| Terms / Incoterms / ShipVia | defaults `Entity.Terms`, `Entity.Incoterms`, `Entity.ShipVia`; each omitted when NULL/blank. Verified: Entity 100298 = Net 30 / Dest / NULL; 101312 = all NULL; 101455 = NET 30 / Dest / 100753. Recent mix: all three 348, Terms+Incoterms 47, Terms only 46, Terms+ShipVia 9, none 1. User can override in the form. |
| PlacedBy | same string as request `User` |
| DateOrdered, DateReleased | identical; client clock when the form was opened/new PO started (a few seconds to minutes before `TransDate`) |

Result: `Ordr.Version` = 1, `Status` NULL. Historical variants: rare `NoBill="false"`; create-and-complete in one request under action `MarkComplete` adds `DateCompleted` and `Status="CMP"` at the end of data.

**Step `OrdDetail Insert`**

- keys: `OrdDetail="@@identity"`
- subs (new PO): `FromStep="1" FromTable="Ordr" FromKey="Ordr" Section="data"`
- data: `[Ordr] Context="PO" Owner="4" Item Description QtyReqd SupQty SupUnit IsOpen="true" PercentUnder PercentOver [DatePromised] ManualPrice Price SupQtyEntered`
  - When adding a line to an EXISTING PO there is no `<subs>`; instead `Ordr="<id>"` is the first data attribute (861711).

| Attr | Source |
|---|---|
| Item, Description | `PriceDetail.Item`, `PriceDetail.Description` (supplier's description for the item) |
| SupQty, SupQtyEntered | quantity typed by user in supplier units (same value) |
| QtyReqd | client-computed: `SupQty * QtyPerEntityQty` as double (10 x 44.09 -> `440.90000000000003`; 3 x 55 -> `165`) |
| SupUnit | `""` when pricing is package-based (PriceDetail.PkgType not NULL; stored NULL); else `PriceDetail.EntityUnit` (`lb`, `ea`). 4 rare old rows omit it. |
| PercentUnder / PercentOver | `Item.UnderReceive` / `Item.OverReceive` (always 1 here) |
| DatePromised | optional, user-entered date |
| ManualPrice | `false` when price came from the price list; `true` when the user typed a price |
| Price | list: tier price from PriceDetail (`PriceN` for largest `MinOrderN` <= quantity; 858290 qty 10 -> Price2 91.592) formatted as double (`404.545` for 404.5450). Manual: as typed (`3`). |

Stored: `OrdDetail.Version` NULL, `Line` NULL.

**Step `OrdDetailPricing Insert`** (snapshot of the PriceDetail row)

- keys: `OrdDetailPricing="@@identity"`
- subs: `FromStep="<line step>" FromTable="OrdDetail" FromKey="OrdDetail" Section="data"`
- data: `PriceDetail PriceVersion Item [EntityItemCode] Description [Comment] Currency [PkgType EntityQuantity] EntityUnit PriceByPackage [MinOrder1] Price1 [MinOrder2 Price2 … MinOrder4 Price4] QtyPerEntityQty [PkgType2 EntityQuantity2] EntityUnit2`
- Source: `PriceDetail` row for (supplier's current `PriceVersion`, Item); NULL columns omitted; prices as 4-dp literals. `QtyPerEntityQty` = `EntityQuantity` if packaged, else 1 (client-computed). `PkgType2/EntityQuantity2/EntityUnit2` duplicate `PkgType/EntityQuantity/EntityUnit`. Verified against PriceDetail 13720, 16699, 16793.
- With `ManualPrice="true"` and no price-list row the data is only `Item EntityItemCode Currency EntityUnit PriceByPackage QtyPerEntityQty` (846034).

**Steps `AddressRef Insert`**

- keys: `TableName="Ordr" Reference="SupplierAddress"` (or `"ShipToAddress"`)
- subs: `FromStep="1" FromTable="Ordr" FromKey="Ordr" Section="keys" ToName="TableID"`
- data: `Address Name AddrLine1 [AddrLine2] [AddrLine3] City [State] [ZipCode] [Contact] [Email] [Fax] [Phone] [CountryName]`
- Source: view `AddressRef` where `TableName='Entity' AND Reference='Address' AND TableID=<entity>`; NULLs omitted. ShipTo is always the owner's address (Entity 4 -> `Address="4" Name="Precision Ink Corporation" … CountryName="USA"`).

**Step `MemoRef Insert`** (optional, between the two AddressRef steps)

- keys: `TableName="Ordr" Reference="POMemo"`; subs as for AddressRef (ToName="TableID")
- data: `Memo="-1" Data="<text>"` (112 recent; 9 have `Data` only). Text defaults from the supplier's PO memo (`MemoRef` TableName='Entity', Reference='POMemo'); CR/LF serialised as `&#x0D;&#x0A;`, `&` as `&amp;`. Server de-duplicates Memo rows by content.

LogResult (864775): step 1 `Ordr=194671`; step 2 `OrdDetail=565499`; step 3 `OrdDetailPricing=54342`; AddressRef steps echo `Reference/TableID/TableName`.

### 1.2 Edit PO

Step 1 is always the Ordr row with the current `Ordr.Version` in keys (optimistic lock; every successful save, including receipts, increments it):

- header field(s) changed: `Ordr Update`, keys `Ordr Version`, data = only the changed fields: `Incoterms ShipVia` (53), `ShipVia` (27), `Incoterms` (8), `Terms`, `NoBill="true"` (845701)
- only children changed: `Ordr UpdateVersion`, keys `Ordr Version`, no data

Child steps, in this order within the request (updates, then deletes, then inserts):

| Change | Step(s) |
|---|---|
| promised date | `OrdDetail Update` keys `OrdDetail`, data `DatePromised` (185; 852597) |
| quantity | `OrdDetail Update` data `QtyReqd SupQty SupQtyEntered` (30; 863605) or `QtyReqd SupQty DatePromised SupQtyEntered` |
| delete line | `OrdDetailPricing Delete` keys `OrdDetailPricing`, THEN `OrdDetail Delete` keys `OrdDetail` (848643) |
| add line | `OrdDetail Insert` (with `Ordr` in data, no subs) + `OrdDetailPricing Insert` (861711) |
| replace line | delete pair then insert pair (861731) |
| add memo | `MemoRef Insert` keys `TableID TableName="Ordr" Reference="POMemo"`, data `Data` (861699) |
| change/clear memo | `MemoRef Update` same keys, data `Data` (824560; `Data=""` clears, 819079) |

### 1.3 Complete / reopen

- Complete (41 recent; 864238): `Ordr Update` data `DateCompleted="<= TransDate>" Status="CMP"`, then for each still-open line `OrdDetail Update` keys `OrdDetail`, data `IsOpen="false"`.
- Old action name `PurchaseOrderDataSet.MarkComplete` (8, none since Log 659996) is the same shape (618024 one line, 606567 two lines, 514526 with a delete pair). Current client sends it as `SaveChanges`.
- Reopen (845709): `Ordr Update` data `DateCompleted="" Status=""` + `OrdDetail Update` data `QtyReqd SupQty IsOpen="true" SupQtyEntered`.

### 1.4 Failures

None ever logged for PO saves. A stale `Version` would presumably give the same "has changed since last read" error seen on receipts.

---

## 2. PurchaseReceiptDataSet.SaveChanges

Plain English: receiver opens a PO, enters packing-slip number and received quantity for ONE line, saves. Each PO line received is its own request and its own ChangeSet; a three-line delivery is three consecutive saves with the same `Shipment` text and `Ordr.Version` 1, 2, 3 (866356/866357/866358).

Site facts (0 exceptions in ~9.4k receipts): no lots, sublots, containers, tare, sampling or QC steps. `NumberOfContainers="1"`, `QtyPerPSQty="1"`, `TareEntered=""`, `RecvdContainer=""`, `SubLot=""`, `TrgLocation="10669"` (Location context WHS, owner 4) always; `Item.LotRequired` = 0 for all items. Partial and over-receipts are allowed (830313 received 1588 against 1500).

### 2.1 New receipt (9029)

| # | Object / Operation | keys | subs | data |
|---|---|---|---|---|
| 1 | `Ordr UpdateVersion` | `Ordr Version` | | |
| 2 | `CreateChangeSet Execute` | | | `Context="PO" Owner="4" Ordr ChangeDate Shipment` |
| 3 | `ChangeSetReceipt Insert` | `OrdDetail` | `FromStep="2" FromTable="ChangeSet" FromKey="ChangeSet" Section="keys"` | `PSQty PSQtyEntered PSUnit QtyPerPSQty="1" NumberOfContainers="1" Item` |
| 4 | `CreateInventoryCost Execute` (costed items only) | | | `Owner="4" Item QtyMK UnitCostMK` |
| 5 | `MoveInventory Execute` | | ChangeSet subs `Section="data"`; if step 4 exists a second `<subs FromStep="4" FromTable="InventoryCost" FromKey="InventoryCost" Section="data"/>` | `Owner="4" Context="PO" ValidateStorageRules="false" TrgOrdDetail Quantity QtyEntered TareEntered="" RecvdContainer="" TrgLocation="10669" Item [UnitCost]` |
| 6 | `UpdateOrdDetailQtyUsed Execute` | | | `OrdDetail QtyDelta` |
| 7 | `CreateUpdateBillDetail Execute` | | ChangeSet subs `Section="data"` | (none) |
| 8 | `NotifyPurchaseReceiptCreated Execute` | | ChangeSet subs `Section="data"` | `SubLot=""` |

Values:

- `Ordr`, `Version` from `Ordr`; `OrdDetail`/`TrgOrdDetail`, `Item` from the `OrdDetail` line.
- `ChangeDate`: client clock when the receipt form was opened (before `TransDate`). `Shipment`: free text packing slip, never empty in practice.
- `PSUnit` = `Item.Unit` (`lb` or `ea`).
- `PSQty = PSQtyEntered = Quantity = QtyEntered = QtyMK = QtyDelta` = quantity typed (0 mismatches).
- `UnitCostMK = UnitCost` = `OrdDetail.Price` (always within 0.00005; occasionally a float artefact such as `3.8600000000000003` for Price 3.8600 - client arithmetic unknown, see open questions).
- Step 4 and `UnitCost` are omitted when `Item.Treatment='NONE'` (412 receipts; 862511). Steps then renumber (7 steps).

Server results (866358): step 2 returns `ChangeSet=139117` (+Ordr); step 3 `ChangeSet, OrdDetail`; step 4 `InventoryUnitCost=79259, InventoryCost=81595`; step 6 `OrdDetail`. LogRequest cascade at step 6: `<action Object="OrdDetail" Step="6" Operation="Update"><keys OrdDetail="564546"/><data QtyUsed="1.350000000000000e+003" IsOpen="false"/></action>` (IsOpen false once within tolerance). Server also sets `Ordr.Status='CMP'`/`DateCompleted` when the last line closes, and creates an unbilled `BillDetail` row (`Bill` NULL, `Amount` = qty x price).

### 2.2 Quantity correction of an existing receipt (33)

1. `Ordr UpdateVersion` keys `Ordr Version`
2. `ChangeSet UpdateVersion` keys `ChangeSet Version`
3. `ChangeSetReceipt Update` keys `ChangeSet OrdDetail`, data `PSQty PSQtyEntered` (new totals)
4. `MoveInventory Execute` data `Owner="4" Context="PO" ValidateStorageRules="false" TrgOrdDetail Quantity QtyEntered TareEntered="" RecvdContainer="" ChangeSet TrgLocation="10669" Item [InventoryCost UnitCost]` - `Quantity` = delta (new - old, as double: `-44.090000000000032`), `QtyEntered` = new total
5. costed only: `UpdateInventoryCost Execute` data `InventoryCost QtyMK` (new total)
6. `UpdateOrdDetailQtyUsed Execute` data `OrdDetail QtyDelta` (delta)
7. `CreateUpdateBillDetail Execute` data `ChangeSet` (explicit id, no subs)

No Notify step. Examples 801462 (costed), 851664 (uncosted).

### 2.3 Packing-slip text correction (6, old; 441178)

`Ordr UpdateVersion` -> `ChangeSet Update` keys `ChangeSet Version`, data `Shipment` -> `CreateUpdateBillDetail Execute` data `ChangeSet`.

### 2.4 Failures (3)

`Ordr ([Ordr] = 'N') has changed since last read` - stale `Version` (Logs 691774, 187926, 187928; typically a second line received from a form opened before the first save).

---

## 3. PurchaseReceiptDataSet.ReverseReceipt (20)

Plain English: undo a receipt (wrong line/PO). One request.

1. `Ordr UpdateVersion` keys `Ordr Version`
2. `ChangeSet UpdateVersion` keys `ChangeSet Version`
3. `ChangeSetReceipt Update` keys `ChangeSet OrdDetail`, data `PSQtyEntered="" PSQty="0"` (this order)
4. `MoveInventory Execute` data `Owner="4" Context="PO" ValidateStorageRules="false" TrgOrdDetail Quantity="-<received qty>" QtyEntered="" ChangeSet TrgLocation="10669" Item [InventoryCost UnitCost]` (no TareEntered/RecvdContainer)
5. costed only: `UpdateInventoryCost Execute` data `InventoryCost QtyMK="0"`
6. `UpdateOrdDetailQtyUsed Execute` data `OrdDetail QtyDelta="-<qty>" ReopenOrdDetail="true"`
7. `CreateUpdateBillDetail Execute` data `ChangeSet`
8. `NotifyPurchaseReceiptReversed Execute` data `ChangeSet SubLot=""`

Sources: `ChangeSet`, its `Version`, `ChangeSetReceipt.PSQty`; `InventoryCost`/`UnitCost` from the receipt's InventoryCost row. Cascade: OrdDetail Update `QtyUsed="0.000000000000000e+000" IsOpen="true"`. Examples 838836, 808379 (costed), 752792 (uncosted).

Failures (2): `ChangeSet 20878 already billed` (265400 - receipt attached to a Bill); `Ordr … has changed since last read` (335774).

---

## 4. ReturnToSupplierDataSet.SaveChanges (17, all one user)

Plain English: pick an earlier receipt, enter quantity to send back. One request, no Ordr version step.

1. `CreateChangeSet Execute` data `Context="RS" Owner="4" ChangeDate`
2. `ChangeSetReturn Insert` - NO `<keys>`; subs `FromStep="1" FromTable="ChangeSet" FromKey="ChangeSet" Section="keys"`; data `ChangeSetReceipt="<receipt ChangeSet id>"`
3. `MoveInventory Execute` subs ChangeSet `Section="data"`; data `TrgLocation="10669" Owner="4" Context="RS" Quantity="-<return qty>" QtyEntered="<original receipt PSQtyEntered>" InventoryCost UnitCost Item`
4. `UpdateInventoryCost Execute` data `InventoryCost`
5. `CreateUpdateBillDetail Execute` subs ChangeSet `Section="data"`

`UnitCost` = `InventoryCost.UnitCostMK` at full double precision (`7.8564090909090911`). Partial returns allowed (706508: -263 of 400; 821709: -400 of 3200). The three oldest (2020) also ran CreateUpdateBillDetail before step 4. LogResult: step 1 ChangeSet; step 2 ChangeSetReturn/ChangeSet. No failures. Examples 821709, 778884, 778886, 706507, 706508.

## 5. ReturnToSupplierDataSet.ReverseReturn (3)

1. `ChangeSet CheckVersion` keys `ChangeSet Version` (the RS ChangeSet)
2. `ReverseChangeSet Execute` data `ChangeSet`
3. `UpdateInventoryCost Execute` data `InventoryCost`
4. `CreateUpdateBillDetail Execute` data `ChangeSet`

Cascades at step 2 (832470): CreateChangeSet `Context="RVSRS"` (`Ordr="" Shipment="" Reference="" ReverseChangeSet=<orig>`), ChangeSet Update `ReverseChangeSet`, ChangeSetReturn Insert, MoveInventory with positive Quantity and `UseUnreservedInventory="true"`. Examples 832470, 267034.

---

## 6. BillUpdateDataSet.SaveChanges (Bill Entry (Inventory))

Plain English: AP enters a supplier invoice and ticks the unbilled receipt lines it covers; may add freight/rounding per line. BillDetail rows already exist (created by receipts, `Bill` NULL = unbilled; view `BillDetails` lists them with supplier, receipt, packing slip).

### 6.1 Create (one request)

1. `Bill Insert` - keys `Bill="@@identity"`; data `Supplier LandingFactor="Inventory" Invoice InvoiceDate [Memo] Terms [Tax1Group Tax1Amount] Amount Currency="USD" CurrencyRate="1"` (4729 plain, 2 with tax, 1 with memo)
2. per attached receipt line (1 to 12): `BillDetail Update` - keys `BillDetail`; subs `FromStep="1" FromTable="Bill" FromKey="Bill" Section="data"`; data none (majority), or `AddCost` (1932), or `Amount AddCost` (558)
3. `UpdateBillCost Execute` - subs Bill `Section="data"`

| Attr | Source |
|---|---|
| Supplier | `Entity.Entity` |
| Invoice | free text; unique per supplier |
| InvoiceDate | user date, `T00:00:00` |
| Terms | `Entity.Terms` (verbatim incl. case) |
| Amount (header) | sum over lines of (Amount + AddCost), 2 dp |
| AddCost (line) | extra cost on the line: freight (`75`, 863069) or rounding adjustment |
| Amount (line) | sent only with AddCost when the stored `BillDetail.Amount` has more than 2 dp: stored 4680.066135 -> `Amount="4680.07" AddCost="-0.07"` (862429). Inferred: user types the line total; client rounds Amount to 2 dp and puts the difference in AddCost. |

`<event>`: when `InvoiceDate` is more than 21 days before the save date (22+ days; exactly 21 gives no event) the request starts with
`<event DataSet="BillUpdateDataSet" Method="InvoiceDateLimit" Caption="Invoice Date preceds limit" User="…" UserExplanation="Your signature indicates that you have executed this step." UserFullName="…"><Memo>&#xA0;Invoice Date M/d/yyyy preceeds limit of 21 days</Memo></event>` (94 bills; 857142). Note the Memo text begins with a NO-BREAK SPACE (U+00A0), the misspellings are literal, and the event `User` domain case varies (`PIC\` or `pic\`).

LogResult: step 1 Bill id; BillDetail steps echo BillDetail. No cascades.

### 6.2 Edit

`Bill Update` keys `Bill` (no Version), data only changed fields (`InvoiceDate`, `Amount`, `Memo`, `Invoice`, `Tax1Group Tax1Amount`, combinations); optional `BillDetail Update` keys `BillDetail`, data `Amount` (824310) or `Bill` (attach another line, 493508); then `UpdateBillCost Execute` data `Bill` (851684).

### 6.3 Delete

`Bill Delete` keys `Bill` only - single action (655555, 661364); may carry the InvoiceDateLimit event (819447). Server detaches the BillDetails.

### 6.4 Failures (16, all identical)

`Cannot insert duplicate key row in object 'dbo.Bill' with unique index 'ix_Bill_Supplier_Invoice'. The duplicate key value is (<supplier>, <invoice>).` - duplicate Supplier + Invoice.

---

## 7. Open questions

1. Exact client arithmetic that yields `UnitCost="3.8600000000000003"` for `OrdDetail.Price` 3.8600 (probably price x qty / qty or a unit conversion in double). Safe choice: send `OrdDetail.Price` as a plain decimal.
2. Bill line `Amount`/`AddCost`: what the user actually types and how the client splits it is inferred from data, not observed.
3. When `MemoRef Insert` on create omits `Memo="-1"` (9 of 121) - likely memo typed from scratch vs defaulted from the supplier.
4. Whether auto-`CMP` of the Ordr is done inside `UpdateOrdDetailQtyUsed` (not visible in LogRequest), and the over/under tolerance rule (`PercentUnder/Over`) the server uses to close a line.
5. Client-side validations not visible in the log: required `Shipment`, over-receive limits, 21-day invoice rule requiring signature.
6. How the Return To Supplier form chooses `InventoryCost` when a receipt's stock was partly consumed.
7. Bill tax fields: requests sent `Tax1Group/Tax1Amount` but the resulting Bill row (4206) shows NULL.
8. Lot / sublot / multi-container / sampling paths are never exercised at this site; shapes unknown.
9. Meaning of `Version=""` in Ordr Insert keys (server assigns 1) and why OrdDetail has no version.
10. Why older clients used action `MarkComplete` (identical XML).

---

## 8. Fixtures

All files are the exact `CONVERT(NVARCHAR(MAX), Request)` text, single line, no trailing newline, UTF-8. Each was fetched individually and verified by MD5 + length against a SQL-side hash of the sanitised string (all 37 match).

Sanitisation applied (nothing else changed):

- every file: `Workstation` value -> `WS1`; the single `pic\<login>` user -> `pic\user1` (request `User`, and `PlacedBy` where present). Each file has only one distinct user.
- 864775: supplier AddrLine1 -> `1 Example St`, Email -> `a@example.com`, Phone -> `555-0100`, Fax -> `555-0101`; ship-to AddrLine1 -> `2 Example St`, Phone -> `555-0102`, Fax -> `555-0103`.
- 863603: supplier AddrLine1 -> `1 Example St`, Email -> `a@example.com`, Phone -> `555-0100`; ship-to as above.
- 857142, 819447: event `User` -> `PIC\user1` / `pic\user1` (original domain case kept), `UserFullName` -> `Person 1`. The U+00A0 at the start of `<Memo>` is preserved (2 bytes in UTF-8).
- Company names, city/state/zip, item descriptions, ids, quantities, prices and dates are untouched.

`PurchaseOrderDataSet.SaveChanges/`

| File | Scenario |
|---|---|
| 864775.xml | Create PO: 1 line, unit-based pricing (SupUnit lb), no Terms/Incoterms/ShipVia, no memo |
| 863603.xml | Create PO: 1 line, package pricing (SupUnit "", float QtyReqd), Terms+Incoterms, PO memo with CRLF and `&amp;` |
| 864786.xml | Header edit: `Ordr Update` Incoterms + ShipVia |
| 845701.xml | Header edit: NoBill="true" |
| 852597.xml | Line edit: DatePromised only |
| 863605.xml | Line edit: quantity change |
| 861711.xml | Add a line to an existing PO |
| 848643.xml | Delete a line (pricing then detail) |
| 861731.xml | Replace a line (delete pair + insert pair) |
| 861699.xml | Add memo to an existing PO (MemoRef Insert) |
| 819079.xml | Clear memo (MemoRef Update Data="") |
| 864238.xml | Complete PO: Status CMP + close 1 line |
| 845709.xml | Reopen a completed PO |

`PurchaseOrderDataSet.MarkComplete/`

| File | Scenario |
|---|---|
| 618024.xml | Legacy complete, 1 line |
| 606567.xml | Legacy complete, 2 lines |

`PurchaseReceiptDataSet.SaveChanges/`

| File | Scenario |
|---|---|
| 866358.xml | New receipt, costed item (8 steps) |
| 866357.xml | New receipt, costed, float-artefact UnitCost; previous line of the same delivery as 866358 |
| 862511.xml | New receipt, uncosted item (7 steps, no CreateInventoryCost) |
| 801462.xml | Quantity correction, costed (negative float delta) |
| 851664.xml | Quantity correction, uncosted |
| 441178.xml | Packing-slip (Shipment) text correction |

`PurchaseReceiptDataSet.ReverseReceipt/`

| File | Scenario |
|---|---|
| 838836.xml | Reverse costed receipt (fractional qty) |
| 808379.xml | Reverse costed receipt |
| 752792.xml | Reverse uncosted receipt |

`ReturnToSupplierDataSet.SaveChanges/`

| File | Scenario |
|---|---|
| 821709.xml | Partial return (400 of 3200) |
| 778886.xml | Partial return, long-double UnitCost |
| 706507.xml | Full return (1200 of 1200) |

`ReturnToSupplierDataSet.ReverseReturn/`

| File | Scenario |
|---|---|
| 832470.xml | Reverse the return made in 821709 |
| 267034.xml | Reverse return (2020) |

`BillUpdateDataSet.SaveChanges/`

| File | Scenario |
|---|---|
| 863067.xml | New bill, 1 receipt line, no adjustments |
| 863069.xml | New bill, 1 line with AddCost (freight) |
| 862429.xml | New bill, 2 lines each with Amount + AddCost rounding |
| 857142.xml | New bill with InvoiceDateLimit `<event>` |
| 851684.xml | Edit bill: InvoiceDate |
| 824310.xml | Edit bill: header Amount + BillDetail Amount |
| 655555.xml | Delete bill |
| 819447.xml | Delete bill with InvoiceDateLimit `<event>` |
