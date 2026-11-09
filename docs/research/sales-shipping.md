# CMS write research: sales orders, assembly, waybill, labels, invoices

Scope: `Log > 766353` (current app version 7.15.0.680) unless stated. All findings come from SELECTs on
`CMS.dbo.Log`, `LogResult`, `LogRequest` and the base tables. Nothing was written to the database.
Counts are "successful requests in scope" unless stated. Log ids quoted are real and can be re-read with
`SELECT CONVERT(NVARCHAR(MAX), Request) FROM CMS.dbo.Log WHERE Log = n`.

Conventions used below:

- "keys / data / subs" list attributes in the exact order the client sends them. `[x]` = optional (only sent when it has a value / was changed).
- Dates: `2026-11-09T12:37:23` (local time, no zone, seconds precision). Date-only columns are sent as midnight (`2026-11-06T00:00:00`).
- Booleans: `true` / `false` (lower case). NULL / cleared value: empty string `""`.
- Decimals: shortest form with `.` (`10.72`, `35`, `-96.5`, `29.6`); values re-read from `money` columns come back with 4 dp (`643.2000`, `188.0000`).
- Newlines in text: `&#x0D;&#x0A;`. `<` is `&lt;`.
- Request envelope: `<request Application="Chemical Management System" AppVersion="7.15.0.680" Program="..." Action="..." Workstation="..." Owner="4" User="pic\name" TransDate="...">`. `Owner` is the logged-in area/warehouse entity (4 = main company, 100877 = consignment warehouse). `TransDate` = time of save.
- Optimistic locking: `Update` with `Version` in keys, or a bare `UpdateVersion` step (keys only), bumps `Version` by 1 and fails with `<Table> ([Key] = 'N') has changed since last read` (ResultCode 50000) when stale. `CheckVersion` verifies without bumping. Every request touching an order starts by bumping `Ordr.Version`, so the client must re-read the version after every request.

## 0. End-to-end flow (what one shipped order looks like in the log)

Example, order 194579, Logs 866340-866346, one user, one workstation:

| Log | Action | Meaning |
|---|---|---|
| (earlier) | ShippingOrderDataSet.SaveChanges | create order (1 request) |
| (seconds later, usually) | ShippingOrderDataSet.SaveChanges | release from credit hold (`Release="true"`) |
| 866340 | AssembleDataSet.SaveChanges | pick stock into a new shipping container (assembly) |
| 866341 | WaybillDataSet.SaveChanges | waybill request 1: create waybill + to-do rows |
| 866342 | WaybillDataSet.SaveChanges | waybill request 2: ship order (creates shipment ChangeSet + invoice) |
| 866343 | WaybillDataSet.SaveChanges | waybill request 3: SaveShippedPallets |
| 866344 | WaybillDataSet.SaveChanges | waybill request 4: mark waybill CMP |
| 866345 | PrintShippingLabel.UpdateShippingLabelAddress | one per container when the label prints (~20-50 s later) |
| 866346 | ShippingOrderDataSet.SaveChanges | user closes the short-shipped order (Status CMP) |

Key point: **the invoice is created by the server inside waybill request 2** (`CreateShipmentsByOrder`). `InvoiceDataSet.SaveChanges` is only used later to *amend* an invoice (reverse + re-issue). `ShippingOrderDataSet.CompleteSale` is the no-waybill path (ship + invoice straight from the order form).

Volumes (all requests, failed in brackets): WaybillDataSet.SaveChanges 9435 (15) = about 2317 waybills; ShippingOrderDataSet.SaveChanges 5462 (8); AssembleDataSet.SaveChanges 2886 (2); PrintShippingLabel.UpdateShippingLabelAddress 2457 (0); InvoiceDataSet.SaveChanges 444 (0); WaybillDataSet.RejectWaybill 31 (0); ShippingOrderDataSet.CompleteSale 12 (1).

---

## 1. ShippingOrderDataSet.SaveChanges (Program "Shipping Order")

Creates or edits a sales order (`Ordr.Context='SH'`). **Always exactly one request per save.** The request is a diff of the client dataset: only inserted/changed/deleted rows and only changed columns.

Optional first child of `<request>` (before the actions), 464 requests: `<event DataSet="ShippingOrderDataSet" Method="DuplicateCustomerPO" Caption="Duplicate PO#"/>` - sent when the user acknowledged the "duplicate PO number" warning (fixture 866062).

### 1.1 Create order

Step order: `Insert Ordr` | (`Insert OrdDetail`, `Insert OrdDetailPricing`) x N lines | [`Insert MemoRef` InternalMemo] | `Insert AddressRef` | [`Insert MemoRef` ShippingMemo] | `Execute UpdateOrderTaxes` | `Execute CheckOrderCredit`.
2161 creates. Zero-line creates exist (8).

**Step 1 `Ordr` Insert**

- keys: `Ordr="@@identity" Version=""`. (When the order was made with "copy order", `Version` carries the source order's version, e.g. `"2"`, and data includes `ReserveAmount` - 170 cases, fixture 865480. Server ignores both as far as can be seen; new order gets Version 1.)
- data, in order: `Context="SH" Owner [DateCompleted] DateOrdered DateRequired Status PlacedBy Currency ShipTo BillTo [ShipVia] [Contact] [FreightService] [FreightOption] [FreightAccount] [PoNumber] BasePriority="100" CancelBackOrder="false" ShipComplete="false" CreditHold="false" AllowOvercommit="true" [IncoTerms] [Terms] [Salesman] [Tax1Group] ChangeNumber IsQuote [ReserveAmount] HeadsUpSent="false" ClearPO="false" [Tax1Number] [Tax2Number]`
- Optional-attribute frequency (of 2161): Terms 2138, Salesman 2135, PoNumber 2125, ShipVia 2113, Tax1Number 1730, IncoTerms 1471, FreightOption 577 (values 0/1/2), FreightAccount 545, ReserveAmount 170, FreightService 106, Tax1Group 75 (`IL`), Tax2Number 32, DateCompleted 9, Contact 1.

| Attribute | Value / source |
|---|---|
| Owner | request `Owner` (4; 100877 for consignment-warehouse orders, 10 cases) |
| DateOrdered | client clock when the form was opened (a few minutes before TransDate) |
| DateRequired | user entry; defaults to DateOrdered (same time of day), often + N days |
| Status | `RTS` normal; `QUO` with `IsQuote="true"` for a quote; `CMP` with `DateCompleted` for an order entered already complete; `CSH` + `CreditHold="true"` seen once on a copy |
| PlacedBy | request `User` (`pic\name`) |
| ShipTo | chosen customer `Entity.Entity` |
| BillTo | `Entity.Parent` of ShipTo, or ShipTo itself when no parent (verified Ordr 194781) |
| Currency, Terms, Salesman, Tax1Number, Tax2Number, Tax1Group | copied from the BillTo `Entity` row (`Currency`, `Terms`, `Salesman`, `Tax1Number`, ...) |
| IncoTerms | `Entity.Incoterms` of BillTo |
| ShipVia, FreightOption, FreightAccount, FreightService | defaults from the ShipTo/BillTo `Entity` row; user can override (entity had ShipVia 100753, order sent 100741) |
| PoNumber | typed |
| ChangeNumber | next value of SQL SEQUENCE `IDGenerator_ChangeNumber` (verified: sequence current value = latest `Ordr.ChangeNumber`). Obtained by the client *outside* ProcessXMLRequest (encrypted proc `GenerateID`). The `IDGenerator` table row is stale; do not use it |
| IsQuote | `false` / `true` |
| constants | `BasePriority="100" CancelBackOrder="false" ShipComplete="false" CreditHold="false" AllowOvercommit="true" HeadsUpSent="false" ClearPO="false"` |

**`OrdDetail` Insert** (per line)

- keys: `OrdDetail="@@identity"`
- subs (on create): `FromStep="1" FromTable="Ordr" FromKey="Ordr" Section="data"`. When adding a line to an existing order there is no subs and data starts with `Ordr="<id>"`.
- data: `[Ordr] Context="SH" Owner IsOpen ManualPrice Price QtyReqd [QtyCommitted] Item [Description] SupQty SupQtyEntered ItemName`

| Attribute | Value / source |
|---|---|
| IsOpen | `true`; `false` for quote lines |
| ManualPrice | `true` when user typed the price (typical); `false` when price came from the price list |
| Price | unit price, shortest decimal |
| QtyReqd | quantity in the item's unit; negative for credit lines |
| QtyCommitted | = QtyReqd for open lines; `0` for negative lines; omitted on quote lines |
| Item | inventory item id (`Item.Item`) |
| ItemName | the alias the customer orders by: `Item` row with `Context='NAME'` whose `ReplacedBy` = Item; equals Item when there is no alias (sundries such as 112583 SHIPPING CHARGE, 115107 WIRE FEE) |
| Description | alias `Item.Description`; omitted when null |
| SupQty, SupQtyEntered | both = QtyReqd |

**`OrdDetailPricing` Insert** (immediately after its OrdDetail)

- keys: `OrdDetailPricing="@@identity"`; subs: `FromStep="<step of its OrdDetail>" FromTable="OrdDetail" FromKey="OrdDetail" Section="data"`
- data: `[PriceDetail PriceVersion] Item [Description] [Currency] EntityUnit PriceByPackage="false" [MinOrder1 Price1] QtyPerEntityQty="1" EntityUnit2`
- Normal (manual price): `Item=<alias or item> [Description] EntityUnit=<Item.Unit: lb/ea> PriceByPackage="false" QtyPerEntityQty="1" EntityUnit2=<same unit>`.
- Price-list variant (122 steps, fixture 861996): adds `PriceDetail`, `PriceVersion`, `Currency`, `MinOrder1`, `Price1` copied from the `PriceDetail` row (`PriceDetail.Item` = alias, `PriceDetail.InvItem` = inventory item, `PriceVersion.Entity` = customer); `Price1` has 4 dp (`5.1100`).

**`MemoRef` Insert** (optional; `MemoRef` is a view over Memo + MemoReference)

- keys: `TableName="Ordr" Reference="InternalMemo"` (or `"ShippingMemo"`, 37 cases)
- subs: `FromStep="1" FromTable="Ordr" FromKey="Ordr" Section="keys" ToName="TableID"`
- data: `[Memo="<id>"] Data="<text>"`. `Memo` + `Data` are copied from the BillTo entity's memo (`MemoRef` where `TableName='Entity'`, `Reference='InternalMemo'`), i.e. the same Memo row is shared. When the user typed or edited the text, `Memo` is omitted (232 cases) and a new Memo row is made.
- On an existing order: keys `TableID="<ordr>" TableName="Ordr" Reference="..."`, no subs (fixture 858964).

**`AddressRef` Insert** (always on create; `AddressRef` is a view over Address + AddressReference)

- keys: `TableName="Ordr" Reference="ShipToAddress"`; subs as MemoRef (`Section="keys" ToName="TableID"`).
- data: `Address Name AddrLine1 [AddrLine2] [AddrLine3] City [State] [ZipCode] [Country] [CountryName] [Contact] [Email] [Fax] [Phone]` - only non-empty columns. Copied from the ShipTo entity's address (`AddressRef` where `TableName='Entity'`, `TableID=ShipTo`, `Reference='Address'`); the same `Address` id is reused (shared row) unless the user edits it.

**Trailer (create)**

```
<action Object="UpdateOrderTaxes" Step="n" Operation="Execute"><subs FromStep="1" FromTable="Ordr" FromKey="Ordr" Section="data" FromName=""/></action>
<action Object="CheckOrderCredit" Step="n+1" Operation="Execute"><subs FromStep="1" FromTable="Ordr" FromKey="Ordr" Section="data" FromName=""/><data OldReserveAmount="0"/></action>
```

`UpdateOrderTaxes` is in every request (5454). `CheckOrderCredit` is omitted in about 120 (quotes, and a few edits such as 860919, 862343 - rule unknown).

### 1.2 Edit order

Header change: step 1 is `Update Ordr` with `<keys Ordr="id" Version="v"/>` and only the changed columns. No header change: step 1 is `UpdateVersion Ordr` (keys only). Then line/memo/address steps, then the trailer in its edit form:

```
<action Object="UpdateOrderTaxes" ... Operation="Execute"><data Ordr="194781"/></action>
<action Object="CheckOrderCredit" ... Operation="Execute"><data Ordr="194781" OldReserveAmount="643.2000"/></action>
```

`OldReserveAmount` = `Ordr.ReserveAmount` as last read (4 dp; `0.0000` when zero).

| Variant | Shape | Fixtures |
|---|---|---|
| Release from credit hold (1411) | `Update Ordr` data `Status="RTS" CreditHold="false"`; CheckOrderCredit data `Ordr="id" Release="true"` (no OldReserveAmount). Normally sent about 5 s after create with `Version="1"` | 866348, 866301 |
| Complete (close) order | `Update Ordr` data `DateCompleted="<TransDate>" Status="CMP"`; plus `Update OrdDetail` `<keys OrdDetail/>` `<data IsOpen="false" QtyCommitted=""/>` for every still-open line | 864293, 865616, 866346 |
| Reopen | `Update Ordr` data `DateCompleted="" Status="RTS"` (usually with new lines) | 860983, 859135 |
| Header fields | any of `ShipVia`, `DateRequired`, `PoNumber`, `Terms`, `FreightService` (may be `""`), `DeliveryConfirmation`, `IncoTerms`. A DateRequired change sometimes also sends a new `ChangeNumber` | 864816, 860919, 866352, 866339 |
| Change quantity | `Update OrdDetail` keys `OrdDetail`, data `QtyReqd QtyCommitted SupQty SupQtyEntered` (price change: `Price`) | 864679, 864848 |
| Add line | `Insert OrdDetail` (data starts `Ordr="id"`, no subs) + `Insert OrdDetailPricing` | 862343, 863536 |
| Delete line | `Delete OrdDetailPricing` `<keys OrdDetailPricing="id"/>` then `Delete OrdDetail` `<keys OrdDetail="id"/>` | 842628, 852291 |
| Replace line | delete pair then insert pair | 863694 |
| Ship-to address edit | `Update AddressRef` keys `TableID TableName="Ordr" Reference="ShipToAddress"`, data = changed columns | 861386 |
| Memo add / edit | `Insert MemoRef` with TableID in keys / `Update MemoRef` same keys, data `Data` | 858964 / 833602 |
| Order to quote | `Update Ordr` `Status="QUO" IsQuote="true"` + lines `IsOpen="false" QtyCommitted=""` | 804159 |
| Quote to order | `Update Ordr` `Status="RTS" IsQuote="false"` + lines `IsOpen="true" QtyCommitted="<qty>"` | 804160 |
| Rare | `Update Ordr` `AllowOvercommit="false"` + `Execute SHCheckInventory` `<data Ordr OverCommitList=""/>` (838215, failed) | - |

### 1.3 Server returns

- `LogResult`: one key per step - `Ordr.Ordr`, `OrdDetail.OrdDetail`, `OrdDetailPricing.OrdDetailPricing`; MemoRef/AddressRef steps return `Reference,TableID,TableName` = `InternalMemo,194781,Ordr`; UpdateOrderTaxes returns `Ordr.Ordr`; CheckOrderCredit returns nothing. Example Log 866347: Ordr 194781, OrdDetail 566319, OrdDetailPricing 54393.
- `LogRequest` cascade: only from UpdateOrderTaxes: `Update Ordr` setting `Tax1Amount ... Tax3BookedAmount` (all `""` for non-taxed customers).
- `CheckOrderCredit` writes directly (not logged): sets `Ordr.ReserveAmount` = sum(qty x price) and, judging by the 1411 follow-up release requests, puts the order on `Status='CSH'`, `CreditHold=1` when the customer fails the credit check. The client must re-read the order after saving.
- `Ordr.Version` +1 per request.

### 1.4 Failures (whole Log table)

- `The DELETE statement conflicted with the REFERENCE constraint "FK_TransDetail_OrdDetail"...` (41) - deleting a line that has been invoiced.
- Same with `"FK_Inventory_OrdDetail"` (3) - line has stock assembled against it.
- `Order detail has been referenced, cannot be deleted` (2).
- `Ordr ([Ordr] = 'N') has changed since last read` - stale Version.
- SHCheckInventory shortage (5): message `60531#<item>,<qty>;...#Item: E5447-3G<TAB>Available: 0 lb<TAB>Committed: 150 lb...#`.

---

## 2. ShippingOrderDataSet.CompleteSale (Program "Shipping Order")

"Complete sale" button: ships and invoices the order's open lines without a waybill (used for credits, sundry charges, wire fees). 12 requests (1 failed). **Two requests per user action**: an ordinary SaveChanges (reopen order + add line(s), section 1.2) immediately followed by CompleteSale. Pairs: 860983/860984, 859135/859136, 840459/840460.

```
<action Object="Ordr" Step="1" Operation="UpdateVersion"><keys Ordr="193154" Version="5"/></action>
<action Object="CreateShipmentsByOrder" Step="2" Operation="Execute"><data Ordr="193154"/></action>
```

Version = `Ordr.Version` re-read after the preceding SaveChanges. No Waybill attribute.

Server (Log 860984): LogResult returns `ChangeSet` 138146, `Document`, `Trans.TransDocument` N00133644, `Trans.Trans` 24015, `TransDetail`, `OrdDetail`. Cascade: `CreateChangeSet(Context SH, Owner, Ordr, ChangeDate, PoNumber)`, `Insert Document` + `CreateDocument (Role Shipment)`, `MoveInventory` per line (`SrcLocation="10669" UseUnreservedInventory="true"` - takes stock straight from the default location, negative qty returns it), `Insert Trans` (Context CI, invoice number from encrypted GenerateInvoiceNumber), `Insert MemoRef InvoiceMemo`, `Insert ChangeSetShipment (Waybill="")`, `Insert TransDetail` per line, `Update OrdDetail (QtyUsed, QtyCommitted="", IsOpen="false")`, `Update Ordr (Status="CMP", DateCompleted)`, tax update.

Failures: `Ordr ([Ordr] = '0') does not exist` (2), `... has changed since last read` (1).

Fixtures: 860984, 859136, 846031 (and the preceding SaveChanges 860983, 859135).

---

## 3. AssembleDataSet.SaveChanges (Program "Shipping Assembly")

Picks stock for order lines into a shipping container ("assembly", a `Location` row with `Context='ASM'`, code like `A018693`). One request per save. 2884 successful.

**New assembly** (2461): `UpdateVersion Ordr` | `Execute CreateAssembly` | `Execute MoveInventory` x N (1 to 8+ lines).

```
<action Object="Ordr" Step="1" Operation="UpdateVersion"><keys Ordr="194579" Version="2"/></action>
<action Object="CreateAssembly" Step="2" Operation="Execute"><data Owner="4" Location="2" PkgType="1"/></action>
<action Object="MoveInventory" Step="3" Operation="Execute"><subs FromStep="2" FromTable="Location" FromKey="Location" Section="data" ToName="TrgLocation"/><data Owner="4" Context="PICK" SrcLocation="10669" Quantity="29.6" QtyEntered="29.6" TrgOrdDetail="564912" Item="112057" Reserve="true" ValidateStorageRules="false" UseUnreservedInventory="true"/></action>
```

- CreateAssembly data: `Owner` (request Owner), `Location` = parent location for assemblies (`2` for Owner 4, `31285` for Owner 100877 - appears to be the area's staging/in-transit location, cf. `Entity.InTransitLocation`), `PkgType` (`1` default; `101100` also seen = an `ItemPkg` package type).
- MoveInventory data, in order: `Owner Context="PICK" SrcLocation [TrgLocation] Quantity QtyEntered TrgOrdDetail Item Reserve="true" ValidateStorageRules="false" UseUnreservedInventory="true"`. `SrcLocation` = stock location picked from (10669 almost always; 31429 for the consignment area). `Quantity` = qty in item unit, `QtyEntered` = qty as typed (same here). `TrgOrdDetail` = order line. `Item` = inventory item (OrdDetail.Item). `TrgLocation` is supplied by subs from the CreateAssembly step.

**Add to an existing assembly** (423): `UpdateVersion Ordr` | `UpdateVersion Location` (`<keys Location="119472" Version="1"/>`, the assembly) | MoveInventory x N with literal `TrgLocation="119472"` and no subs (fixture 865759).

**Remove from an assembly** (37): as above, but each MoveInventory is preceded by `<action Object="Location" Operation="CheckVersion"><keys Location="10669" Version="1"/></action>` (the stock location) and has `Quantity="-5" QtyEntered="5"` (fixtures 864847, 855689).

Server returns: LogResult `Ordr`, and for CreateAssembly `Location.Location` 119594 + `Location.LocationCode` A018693. No LogRequest cascades (procs write directly: inventory moved and reserved to the order line).

Failures (all time): `Quantity of the item '<code>' is negative` (not enough stock in SrcLocation), `Ordr ... has changed since last read`.

Fixtures: 866340, 866325 (new, 1 line), 866114 (new, 2 lines), 865759 (add to existing), 864847, 855689 (remove).

---

## 4. WaybillDataSet.SaveChanges (Program "Waybill")

Ships one or more assembled orders. **One user "save" = 3 + N requests (N = number of orders on the waybill), all within about 0.3-1 s**: verified on 866341-866344, 866334-866337, 866326-866329, 866308-866311 (N=1), 861915-861919 (N=2), 848377-... (N=3). Counts: request-1 shape 2317 successful; CreateShipmentsByOrder requests 2466; SaveShippedPallets 2317; MarkCompleted 2317. Request 1 writes a to-do list (`WaybillInProcess` rows); each later request ticks one row off (`IsDone`) in the same transaction as the work it does.

### Request 1 - create waybill and to-do list

Steps: `UpdateVersion Ordr` x N | `UpdateVersion Location` x C (each assembly container) | `Insert Waybill` | `Insert WaybillTrackingNumber` x C | `Insert AddressRef` | `Execute CreateDocument` | `Insert WaybillInProcess` x (N + 2).

- `Ordr` keys `Ordr Version`; `Location` keys `Location Version` (assembly `Location.Version`).
- `Waybill` Insert: keys `Waybill="@@identity" Version=""`; data in order `Owner PlacedBy DateShipped ShipAsIs="false" TrailerNumber="" SealNumbers="" Comment="" [ShipVia] [FreightService] [FreightOption] [FreightAccount] [PoNumber] [DeliveryConfirmation]`. `DateShipped` = time the form was opened. ShipVia/FreightService/FreightOption/FreightAccount/PoNumber are copied from the (first) order (verified Ordr 194579: ShipVia 100741, FreightAccount 107281, PoNumber 75622; FreightOption sent as `0` when the order has NULL). TrailerNumber/SealNumbers/Comment were always empty.
- `WaybillTrackingNumber` Insert (one per container): keys `WaybillTrackingNumber="@@identity"`; subs `FromStep="<Waybill step>" FromTable="Waybill" FromKey="Waybill" Section="data"`; data `Location TrackingNumber="" FreightCost="" ActWeightKg ActWeightLb`. Weight = contents of the container in lb; kg = lb x 0.45359237 unrounded (`29.6` -> `13.426334152`). `0`/`0` is sent for a container with no weighable content.
- `AddressRef` Insert: keys `TableName="Waybill" Reference="ShipToAddress"`; subs `... Section="keys" ToName="TableID"`; data is the **full** address row, empty columns included: `Address Name Department AddrLine1 AddrLine2 AddrLine3 City State ZipCode Country Contact Email Fax Phone StateName CountryName URL Residential TableID TableName="Waybill" Reference="ShipToAddress" EmergencyContact`. `Address` = the order's ShipToAddress address id (shared row). The `TableID` inside data is a client-side placeholder that matched the real new id in only 488 of 2187 cases; the server uses the subs value.
- `CreateDocument` Execute: subs `FromStep FromTable="Waybill" FromKey="Waybill" Section="data" ToName="TableID"`; data `TableName="Waybill" Role="Waybill"`.
- `WaybillInProcess` Insert (no keys; subs `... Section="data"`): one per order `<data Ordr="194579" Action="CreateShipmentsByOrder" IsShipped="true" [FreightCharge="188"]/>`, then `<data Action="SaveShippedPallets"/>`, then `<data Action="MarkCompleted"/>`.

LogResult: Ordr, Location, `Waybill.Waybill` (new id), WaybillTrackingNumber, AddressRef, `Document.Document`. The WaybillInProcess ids are **not** returned: the client re-reads `WaybillInProcess WHERE Waybill = <id>` (ids are consecutive in insert order).

### Request 2 (one per order) - ship the order

```
<action Object="WaybillInProcess" Step="1" Operation="Update"><keys WaybillInProcess="55977"/><data IsDone="true"/></action>
<action Object="Waybill" Step="2" Operation="UpdateVersion"><keys Waybill="18541" Version="1"/></action>
<action Object="CreateShipmentsByOrder" Step="3" Operation="Execute"><data Waybill="18541" Ordr="194579" IsShipped="true" FreightCharge=""/></action>
```

`FreightCharge` is `""` or the to-do row's value re-read from the database (`188.0000`). Waybill Version starts at 1 and increases by 1 per request.

Cascade (Log 866342): `CreateChangeSet (Context SH, Owner, Ordr, ChangeDate=Waybill.DateShipped, PoNumber)`; `Insert Document` + `CreateDocument (TableName ChangeSet, Role Shipment)`; `Update Location (Status="DEL")` for the container; `MoveInventory` per assembled line (`Context SH, SrcOrdDetail, SrcLocation=<container>, Quantity, DeleteSrcContainerIfEmpty, DeleteSrcZeroInventory`); `Insert Trans` (Context `CI`, `TransDocument` = new invoice number, TransDate = date, DocumentDate = DateShipped, Ordr, Entity=ShipTo, BillTo, Currency, FreightCharge, PoNumber, Salesman); `Insert MemoRef InvoiceMemo`; `Insert ChangeSetShipment (ChangeSet, Waybill, Empty="0")`; `Insert TransDetail` per line (Price 4 dp, Qty shipped, Unit); `Update OrdDetail (QtyUsed, QtyCommitted=<remaining>, IsOpen)`; order tax update. LogResult returns the new `ChangeSet`, `Document`, `Trans`, `TransDocument`, `TransDetail` ids - **the ChangeSet id is the key for the packing slip, Trans for the invoice**.

### Request 3 - `Update WaybillInProcess IsDone` | `UpdateVersion Waybill` | `<action Object="SaveShippedPallets" ... Operation="Execute"><data Waybill="18541"/></action>`

Cascade: `Insert PalletTracking (Ordr, PkgType, QtyShipped)` per order/package type.

### Request 4 - `Update WaybillInProcess IsDone` | `<action Object="Waybill" Step="2" Operation="Update"><keys Waybill="18541" Version="3"/><data Status="CMP"/></action>`

### Variants

- **Multiple orders on one waybill** (129 waybills: 2 orders 107, 3 orders 21, 4 orders 1): request 1 has N `Ordr` steps, then all `Location` steps, and N CreateShipmentsByOrder to-do rows; request 2 repeats once per order with Waybill Version 1, 2, ...; so the sequence is N+3 requests (fixtures 861915-861919; 859033 = 3 orders).
- **Multiple containers** for an order: more `Location` + `WaybillTrackingNumber` steps than orders (e.g. 1 order / 2 containers, 6 cases).
- **Freight charge** (10 waybills): `FreightCharge` on the to-do row and on CreateShipmentsByOrder; ends up on `Trans.FreightCharge` (fixtures 855512-855515).
- **Partial shipment**: there is no special request. Whatever was assembled ships; the server leaves the line open with the remainder (`QtyCommitted="0.39999999999999858" IsOpen="true"` in 866342) and the order stays `RTS`. The user either ships the rest on a later waybill or closes the order with a "Complete" SaveChanges (866346). `IsShipped` was `true` in all 2466 cases; `WaybillInProcess.ContainersNotShipped` is NULL in all 55627 rows, so "ship only some containers" is unused here.
- **Edit of an existing waybill**: not observed. Every request-1 in scope inserts a new Waybill; corrections are done with RejectWaybill then a new waybill (section 5).

### Resumability

`WaybillInProcess` (WaybillInProcess, Waybill, IsDone, Ordr, IsShipped, ContainersNotShipped, Action, FreightCharge) is the persisted to-do list. Because each later request sets `IsDone` and does the work atomically, a failure leaves the remaining rows `IsDone=0`. Real example (2020, Logs 299815-299824): request 1 succeeded, request 2 failed (`In Transit Location is not set in Target Area`); the user fixed the setup, reopened the Waybill program, and the client replayed the outstanding rows in order as three requests with `Action="WaybillDataSet.ProcessOutstandingRequests"` - byte-for-byte the same bodies as requests 2-4. Today no row in the table is outstanding (0 of 55627 with IsDone=0).

An implementation should therefore: after request 1 read the to-do rows for the waybill ordered by id; for each row with IsDone=0 send the matching request (re-reading Waybill.Version each time); on start-up look for rows with IsDone=0 and finish them.

### Failures (all time)

Almost all `Ordr ([Ordr] = 'N') has changed since last read` on request 1 (15 in scope; nothing is written, user retries). One `In Transit Location is not set in Target Area` on request 2 (above).

Fixtures: 866341-866344 and 866334-866337 (1 order, 1 container), 861915-861919 (2 orders), 855512-855515 (freight charge), 859033 (request 1 with 3 orders).

---

## 5. WaybillDataSet.RejectWaybill (Program "Waybill")

Reverses a completed waybill. One request, identical shape in all 31 cases:

```
<action Object="Waybill" Step="1" Operation="Update"><keys Waybill="18162" Version="6"/><data Status="REJ"/></action>
<action Object="RejectWaybill" Step="2" Operation="Execute"><data Waybill="18162"/></action>
```

Version = current `Waybill.Version` (4 straight after shipping; higher if touched since).

Cascade (Log 848374, 3 orders), per shipment on the waybill: `UpdateVersion Trans` (original invoice); `Insert Trans` with the same `TransDocument`, `ReversedTrans=<original>`; `Insert TransDetail` with negated Qty; `CreateChangeSet (Context="RVSSH", ReverseChangeSet=<original>, Trans=<reversal>)`; `Insert ChangeSetShipment`; Document; `Update Location Status=""` (container restored); `MoveInventory Context="RVSSH"` negative qty back into the container with `UseInventoryCost`; `Update OrdDetail (QtyUsed="0", QtyCommitted=<qty>, IsOpen="1")`; `Update Ordr (Status="RTS", DateCompleted="")`; tax update. The order is back to assembled-and-ready; in the log the user then edits the order and creates a new waybill (848375-848380).

Failures: `Negative average unit cost in inventory cost N` (5, 2014 only).

Fixtures: 848374, 846026, 844378.

---

## 6. PrintShippingLabel.UpdateShippingLabelAddress (Program "PrintShippingLabel")

Sent by the label-printing component, one request per container, when a shipping label is printed (20-50 s after the waybill). The envelope has **no Workstation, Owner or User attribute** and `Log.LoggedInUser` is NULL:

```
<request Application="Chemical Management System" AppVersion="7.15.0.680" Program="PrintShippingLabel" Action="PrintShippingLabel.UpdateShippingLabelAddress" TransDate="2026-11-09T12:38:04"><action Object="AddressReference" Step="1" Operation="Insert"><keys TableID="119594" TableName="Location" Reference="ShipToAddress"/><data Address="101504"/></action></request>
```

`TableID` = container `Location` (from `WaybillTrackingNumber.Location`); `Address` = the waybill's ShipToAddress address id. It records which address the label was printed with. One `Update` instead of `Insert` exists (same keys/data) for a reprint with a different address. LogResult: `ShipToAddress,119594,Location`. No cascades, no failures.

Fixtures: 866345, 861920, 861921 (the last two are the two containers of waybill 861915).

---

## 7. InvoiceDataSet.SaveChanges (Program "Invoice")

Amends an existing invoice by reversing it and re-issuing it under the same invoice number - in practice to add a freight charge (375 of 444), change PO number, memo or tax. One request per save; 444, no failures in scope; all one shape with 2 x L TransDetail rows (L = invoice lines).

Steps: `UpdateVersion Trans` (original) | `Insert Trans` (reversal) | `Insert TransDetail` x L (negative) | `Insert Trans` (new) | `Insert TransDetail` x L | `Insert MemoRef` | `Execute UpdateInvoiceTax` | `Update ChangeSet` | `Execute CheckInvoiceQty` | `Execute UpdateShipmentPoNumber`.

- `Trans` UpdateVersion keys `Trans="24104" Version="1"`.
- `Trans` Insert: keys `Trans="@@identity" Version="1"`; data `Context="CI" TransDate Owner Salesman TransDocument DocumentDate Ordr Entity BillTo Currency [FreightCharge] [Tax1Group] [Tax1Amount] [PoNumber] [ReversedTrans]`. Reversal row: values copied from the original `Trans` plus `ReversedTrans=<original>` (and negated tax amounts when taxed). New row: same values with the user's edits (FreightCharge shortest decimal `271.18`), no ReversedTrans. `TransDate` = today at midnight; `DocumentDate` = original document date; `TransDocument` = original invoice number.
- `TransDetail` Insert: keys `TransDetail="@@identity"`; subs `FromStep="<its Trans step>" FromTable="Trans" FromKey="Trans" Section="data"`; data `Context="SH" OrdDetail Item Price Qty Unit ItemQtyPerQty="1" [Tax1Amount]` (copied from the original TransDetail; Qty negated on the reversal).
- `MemoRef` Insert: keys `TableName="Trans" Reference="InvoiceMemo"`; subs from the **new** Trans step `Section="keys" ToName="TableID"`; data `Memo="<id>" Data="<text>"` (Data empty in 327).
- `UpdateInvoiceTax`, `CheckInvoiceQty`: Execute with only subs (new Trans -> data).
- `ChangeSet` Update: keys `ChangeSet="138909" Version="1"` + subs (new Trans -> data): re-points the shipment ChangeSet at the new invoice.
- `UpdateShipmentPoNumber`: subs + data `PoNumber`.

LogResult (865454): original Trans 24104, reversal 24105, new 24106 (all TransDocument N00133714), TransDetail ids, ChangeSet. Cascade: tax amount updates on the new Trans/TransDetail.

Failures (all time): `Total quantity is not equal to the shipped quantity for item(s): <code>` (4, from CheckInvoiceQty).

Fixtures: 865454, 852933, 852177 (all 1-line, freight charge added).

---

## 8. Read side: documents to print

### Packing slip (key = shipment `ChangeSet`, returned by waybill request 2 / CompleteSale)

- `PackingSlipReport` (view, readable): one header row per shipment. `FROM ChangeSet cs JOIN ChangeSetShipment csh JOIN Waybill wb JOIN Ordr o LEFT JOIN Transes tr ON tr.Trans = cs.Trans ... WHERE cs.Context = 'SH'`. Ship-to comes from the Waybill's ShipToAddress, ship-via from `wb.ShipVia`, memo from the order's ShippingMemo. Because of the inner join to Waybill, **CompleteSale shipments (no waybill) do not appear**.
  Columns: ChangeSet, Ordr, Owner, TransDocument, DateShipped, ShipToCode, ShipToName, ShipToAddrLine1-3, ShipToCity, ShipToState, ShipToZipCode, ShipToCountry, ShipToContact, ShipToPhone, ShipToFax, ShipToCityStateZip, EMail, BillToCode, BillToName, BillToAddrLine1-3, BillToCity, BillToState, BillToZipCode, BillToCountry, BillToContact, BillToPhone, BillToFax, BillToCityStateZip, Currency, CurrencyDescription, Dateordered, DateRequired, DateReleased, Incoterms, IncoTermsDescription, ShipViaCode, ShipViaName, Status, UserHold, Terms, TermsDescription, PlacedBy, PhoneNumber, Department, Contact, PoNumber, DontShipBefore, FreightService, FreightOption, BillTo, FreightCharge, IsQuote, ShippingMemo, Logo.
  Sample (`WHERE ChangeSet = 139114`): Ordr 194579, TransDocument N00133731, DateShipped 2026-11-09 12:37:23, ShipToCode COMPANY1, ShipToCityStateZip `Anytown, NY  00000`, Currency USD, Incoterms `Ship Point`, ShipViaCode `UPS - SMALL PACKAGE`, Status CMP, Terms NET 30, PlacedBy = user's display name, PoNumber 75622.
- `PackingSlipDetailReport` and `PackingSlipContainerDetailReport` are **empty stub views** (`select ... convert(int, null) ... where 1=2`): they only define the column shape (Detail: PackingSlipDetailReport, ChangeSet, Item, ItemCode, Description, Lot, Assembly, HandlingUnit, Pieces, CasesPerPallet, TotalCases, TotalPieces, Comment; Container: PackingSlipContainerDetailReport, PackingSlipDetailReport, Container, Quantity, Unit). The rows are produced by the encrypted procedure `PreparePackingSlipReport`. Insights 2 must build the detail itself, e.g. from `ShipmentDetails` (see SCHEMA_NOTES.md) or `InvoiceDetailLotReport` filtered by the shipment.

### Invoice (key = `Trans`, or ChangeSet / Waybill / Ordr)

- `InvoiceReport` (view): header, one row per invoice Trans (`WHERE tr.Context IN ('CI','BH','TI')`). Columns: ChangeSet, Waybill, Ordr, Trans, Invoice (number), Empty, Context, DateRequired, DateShipped, PoNumber, ShipViaCode, ShipViaName, TruckRoute, TruckRouteSequence, Reference, Incoterms, IncoTermsDescription, Owner, OwnerCode, Div* (division address), Contact, Email, ShipTo* (code, name, address, CityStateZip), BillTo*, SalesmanCode, SalesmanName, Document, Tax1Number, TermsDescription, Currency, CurrencyDescription, Amount, FreightCharge, Tax1-3Amount, Tax1-3Description, TotalAmount, Operator, OperatorName, NetAmountDue, InvoiceMemo, Logo.
  Sample (`WHERE Trans = 24123`): ChangeSet 139114, Waybill 18541, Ordr 194579, Invoice N00133731, Context CI, PoNumber 75622, TermsDescription NET 30, Amount 190.3300, FreightCharge NULL, TotalAmount 190.3300, NetAmountDue 190.3300.
- `InvoiceDetailReport` (view): lines. Columns: InvoiceDetailReport (=TransDetail), Trans, Item, Unit, ItemCode, ItemContext, ItemDescription, ItemAltDescription, OrdDetail, QtyReqd, QtyShipped, Price, QtyUsed, IsOpen, OrdDetailComment, WeightKg, WeightLb, Value, TheirCode, Shipped* quantities, EntityQuantity, TotalWeight, TotalWeightUnit, EntityUnit, PkgTypeCode, OrderUnit, OrderUnitDescription, PricingUnit, EntityDescription. Sample: TransDetail 60868, Trans 24123, ItemCode B1929-50, QtyReqd 30, QtyShipped 29.6, Price 6.4300, Value 190.3300.
- Also readable: `InvoiceDetailLotReport` (Trans, InvoiceDetailReport, SublotCode, Lot, ManfLot, Item, ItemCode, LotRequired, Container, QtyShipped, Unit, Tare, TareUnit, ExpiryDate), `InvoiceTaxReport` (Trans, TaxNumber, TaxGroup, TaxDescription, TaxAmount), `InvoiceMemo` (`MemoRef WHERE TableName='Trans' AND Reference='InvoiceMemo'`), `Invoices` (list view with NetAmountDue; has Waybill, ChangeSet), `InvoiceDetail`, `InvoicePaymentReport`, `TransTotal`, function `GetInvoiceTotals`.
- A reversed-and-reissued invoice has three Trans rows with the same number; print the latest non-reversed one (`Invoices.IsReversed`).

### Shipping label

- `ShippingLabel` (view, readable): one row per container. First branch: completed waybills (`wb.Status = 'CMP'`, containers via the waybill); second branch (`Waybill` NULL): assemblies of orders not yet shipped, weights computed from `GetAssemblyContent(Location)` plus package tare. Columns: Waybill, Ordr, PoNumber, DateOrdered, ContainerId (Location), Container (code), WeightKg, WeightLb, ShipViaCode, ShipViaName, ShipToName, ShipToAddress (address id), ShipToAddrLine1-3, ShipToCity, ShipToCountry, ShipToState, ShipToZipCode, ShipToContact, ShipToPhone, Warehouse* (ship-from), OrdDetail, ItemCode, Description, LotNumber, Reprint.
  Sample (`WHERE Waybill = 18541`): Ordr 194579, PoNumber 75622, ContainerId 119594, Container A018693, WeightKg 13.426, WeightLb 29.6, ShipViaCode `UPS - SMALL PACKAGE`, ShipToAddress 101504, WarehouseCode PRECISION INK.
- `ShippingLabelFormat` (view): `SELECT ReportName, Report FROM Report WHERE Context = 'ShippingLabelFormat'` - stored label layouts.
- Procedures `GetShippingLabelInfoByOrderNo` / `GetShippingLabelInfoByShippingContainer` are readable but legacy (they reference tables such as WayBillPackages / Product that belong to an older schema); do not use.
- After printing, the client records the address used (section 6). `UpdateShippingLabelAddress` (proc) is encrypted, but the logged request is a plain `Insert AddressReference`.

---

## 9. Fixtures

Directory: `C:\Claude Sessions\insights2\tests\fixtures\cms-requests\<Action>\<Log>.xml`. Each file is the exact `CONVERT(NVARCHAR(MAX), Request)` text (single line, no trailing newline), extracted by script from the query result rather than retyped. Sanitisation applied to every file (and nothing else): `pic\<name>` -> `pic\user1` (only one user per file occurred); `Workstation` -> `WS1`; non-empty `Contact`/`EmergencyContact` -> `Person N`; non-empty `AddrLine1-3` -> `N Example St`; non-empty `Phone`/`Fax` -> `555-01NN`; non-empty `Email` -> `a@example.com`; in 833602 a person's name inside memo text -> `Person 1`. Company names, cities, states, zip codes, ids, codes, quantities, prices and dates are untouched. PrintShippingLabel files needed no change.

**ShippingOrderDataSet.SaveChanges**

| File | Scenario |
|---|---|
| 864914.xml | create, 1 line, no memo |
| 864862.xml | create, 1 line, no memo, address with contact/email/phone |
| 866062.xml | create with `<event ... DuplicateCustomerPO>` (861996 and 865480 also carry this event) |
| 864643.xml | create with InternalMemo (typed text, FreightAccount) |
| 866306.xml | create with InternalMemo copied from customer (`Memo` id) |
| 861996.xml | create from price list (PriceDetail/PriceVersion pricing), Owner 100877 consignment |
| 865480.xml | create by copying an order (keys Version non-empty, ReserveAmount) |
| 787401.xml | create quote (Status QUO, IsQuote true, no CheckOrderCredit) |
| 804159.xml / 804160.xml | order -> quote / quote -> order |
| 866348.xml, 866301.xml | release from credit hold |
| 864293.xml, 865616.xml, 866346.xml | complete (close) order |
| 860983.xml, 859135.xml | reopen + add lines (precede CompleteSale 860984 / 859136) |
| 864816.xml | header edit: ShipVia |
| 860919.xml | header edit: DateRequired (no CheckOrderCredit) |
| 866352.xml | header edit: DateRequired + new ChangeNumber |
| 866339.xml | header edit: DateCompleted + ShipVia |
| 864679.xml, 864848.xml | change line quantity |
| 862343.xml, 863536.xml | add line to existing order |
| 842628.xml, 852291.xml | delete line |
| 863694.xml | replace line (delete + insert) |
| 861386.xml | edit ship-to address (AddressRef Update) |
| 858964.xml | add ShippingMemo to existing order |
| 833602.xml | edit InternalMemo (MemoRef Update) |

**ShippingOrderDataSet.CompleteSale**: 860984.xml, 859136.xml, 846031.xml - complete sale of an order.

**AssembleDataSet.SaveChanges**: 866340.xml, 866325.xml (new assembly, 1 line); 866114.xml (new assembly, 2 lines); 865759.xml (add to existing assembly); 864847.xml (remove 1 line, CheckVersion); 855689.xml (remove 2 lines).

**WaybillDataSet.SaveChanges**

| File | Scenario |
|---|---|
| 866341.xml - 866344.xml | full 4-request sequence, 1 order, 1 container |
| 866334.xml - 866337.xml | second full 4-request sequence |
| 861915.xml - 861919.xml | 5-request sequence, 2 orders / 2 containers (861916, 861917 = one CreateShipmentsByOrder each) |
| 855512.xml - 855515.xml | 4-request sequence with FreightCharge |
| 859033.xml | request 1 only, 3 orders / 3 containers |

**WaybillDataSet.RejectWaybill**: 848374.xml, 846026.xml, 844378.xml.

**PrintShippingLabel.UpdateShippingLabelAddress**: 866345.xml, 861920.xml, 861921.xml.

**InvoiceDataSet.SaveChanges**: 865454.xml, 852933.xml, 852177.xml - 1-line invoice re-issued with a freight charge.

No fixture: AddressReference `Update` label variant (1 case), SHCheckInventory (only failed/rare), multi-line invoice amendment (same shape, more TransDetail steps), taxed invoice amendment (11 cases with Tax1Group/Tax1Amount).

---

## 10. Open questions

1. **ChangeNumber**: the client gets it from sequence `IDGenerator_ChangeNumber` via something outside ProcessXMLRequest (encrypted `GenerateID`). Insights 2 has SELECT-only access, so how will it obtain the next value? Is `Ordr.ChangeNumber` required/unique, or would the server accept it missing? Also unclear when a DateRequired edit sends a new one.
2. **CheckOrderCredit** is encrypted. Inferred: recalculates `ReserveAmount` and may set `Status='CSH'`/`CreditHold`. 1411 of 2161 new orders are followed seconds later by a release request - is the user doing that by hand (a prompt), and should Insights 2 replicate it automatically? When may the step be omitted?
3. **Waybill to-do row ids** are not returned in LogResult; assumed the client re-reads `WaybillInProcess` by Waybill. Confirm ordering by id is the right replay order.
4. **Placeholder `TableID`** in the waybill AddressRef data - assumed ignored by the server (subs wins). Is it safe to send the real attribute list without it, or with `TableID=""`?
5. **Does attribute order matter** to the server? All evidence is consistent ordering from one client; never seen a different order, so keep it identical.
6. **Packing slip detail**: `PreparePackingSlipReport` is encrypted and the detail views are stubs. The detail rows (lot, assembly, pieces, cases) must be reconstructed; exact rules (HandlingUnit, CasesPerPallet) unknown. Also CompleteSale shipments are excluded from `PackingSlipReport`.
7. **CreateAssembly parameters**: `Location="2"` (31285 for the consignment area) and `PkgType="1"`/`101100` - where does the client read them (area default staging location / package type list)? `SrcLocation` 10669 is assumed to be the default stock location for Owner 4.
8. **Container weights** on WaybillTrackingNumber: assumed sum of contents in lb (no tare) from the assembly; `0` cases unexplained (non-weight items such as "ea" sundries?).
9. **FreightOption** meaning of 0/1/2 (prepaid / collect / third party?) and why the waybill sends `0` when the order has NULL.
10. **Invoice amendment memo**: `Memo="276"` with empty Data appears often - is 276 a shared empty memo row, and what should be sent when the original invoice has no memo?
11. **Label request envelope** has no User/Workstation/Owner; does the server accept that from any caller, and is the insert needed at all for label reprints (unique key on TableID/TableName/Reference would make a second Insert fail - the one `Update` case suggests the client checks first)?
12. Waybill fields never used here (TrailerNumber, SealNumbers, Comment, ShipAsIs, DeliveryConfirmation, ContainersNotShipped / IsShipped=false) - behaviour unverified.
