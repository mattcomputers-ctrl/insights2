# CMS write log research: inventory count, label/plan utilities, master data

Source: `CMS.dbo.Log` (+ `LogResult`, `LogRequest`), read-only SELECTs, researched 2026-11-09.
"Recent" = `Log > 766353` (client AppVersion 7.15.0.680). Counts written `total / failed / recent`.
All requests are the single argument to `ProcessXMLRequest(@Request)`.

## 0. Conventions that apply to every action below

Envelope (attribute order is fixed):

```
<request Application="Chemical Management System" AppVersion="7.15.0.680" Program="…" Action="…" Workstation="…" Owner="4" User="pic\<login>" TransDate="yyyy-MM-ddTHH:mm:ss">
```

- `TransDate` = client clock truncated to seconds (Log.StartTime has the ms). The server rejects stale values (`ResultCode 60267 "Request TransDate is out of tolerance."`, seen twice on Shell.Logout).
- `Workstation` is the machine name as the client sees it (same laptop appears as `ROD-LAPTOP` and `Rod-Laptop.pic.`).
- Inside `<action Object Step Operation>` the child order is always `keys`, `subs` (0..n), `data`. Elements with no attributes are omitted entirely (e.g. `UpdateVersion` has only `keys`; `ItemChemical Insert` has no `keys`).
- `<subs FromStep FromTable FromKey Section [ToName] [FromName=""]/>` copies the key generated/used at an earlier step into this step's `keys` or `data` section, under the name `ToName` (default = `FromKey`). Execute-type objects carry a literal `FromName=""`.
- New identity keys are sent as `"@@identity"`. `Version` on an Insert is normally `""` but the client sometimes sends a stale number (`"1"`, `"2"`, `"4"`, `"8"`); the server ignores it and the new row gets Version 1 (verified: Item 123104 inserted with `Version="2"` in 864641, then updated with `Version="1"` in 864642).
- Optimistic concurrency: `Update`/`UpdateVersion`/`Delete` on a versioned header send the Version the client last read. Mismatch fails with `<Table> ([<Key>] = 'N') has changed since last read`. When only child rows changed, the header is sent as `UpdateVersion` (keys only) to bump the version. Each successful request increments the header Version by 1.
- Edits send only changed columns. A cleared field is sent as `Attr=""`.
- Formats: booleans `true`/`false`; dates `yyyy-MM-ddTHH:mm:ss`; floats are .NET double round-trip strings with float noise (`-299.73399996059965`, `0.99999999999999989`, `83.600000000000023`, negated zero is `-0`); prices as typed (`9`, `6.73`, `11.46`) except defaulted money (`StandardCost="0.0000"`); CR/LF in text is `&#x0D;&#x0A;`, `<` is `&lt;`.
- Failure wrapper (ResultCode 50000): `<Result Level="16" State="1" Procedure="ProcessXMLRequest" Line="171" Message="…"/>` (Line 173 in 2014-era rows).
- `LogResult` (Log, Step, TableName, FieldName, FieldValue) holds the keys returned per step. `LogRequest` holds server-side cascaded requests. None of the master-data or count actions here produce LogRequest rows; only ResetLabelRequired does.

Fixture sanitisation applied (and nothing else): `pic\<name>` -> `pic\user1` (also inside `CreateUser`/`PlacedBy`); `Workstation` -> `WS1`; `AddrLine1/2/3` -> `1 Example St`/`2 Example St`/`3 Example St`; `Phone` -> `555-0100`; `Email` -> `a@example.com`; a person's name -> `Person 1`. Company names, entity codes, city/state/zip, ids, quantities, prices, dates are untouched. Files are single-line, UTF-8, no BOM, no trailing newline; lengths were reconciled against `LEN(CONVERT(NVARCHAR(MAX),Request))`.

---

## 1. Inventory Count: `InventoryCountDataSet.SaveChanges` (1655 / 0 / 156) and `.Post` (1564 / 20 / 151)

**What it is.** A cycle count: the user lists items with the counted quantity, saves, and posts. Posting writes an inventory adjustment for each line so on-hand equals the count.

**Request sequence.** A "create and post" is exactly two requests from the same user/workstation about 1 s apart: SaveChanges then Post (866080 -> 866081; 861111 -> 861112; 768178 edit -> 768179 post; 851619 create, 851620 edit, 851621 post). A count can be saved, edited any number of times and posted later; each is its own request.

### 1a. SaveChanges shapes

| Shape | Steps |
|---|---|
| Create | `Insert:InventoryCount`, then N x `Insert:InventoryCountDetail` (N=1 in 111 of 156 recent) |
| Edit lines | `UpdateVersion:InventoryCount`, N x `Update:InventoryCountDetail` |
| Edit header | `Update:InventoryCount` keys `InventoryCount Version`, data `Description` (26) or `EffectiveDate` (1) |
| Delete count | N x `Delete:InventoryCountDetail`, then `Delete:InventoryCount` (details first) |

Attributes:

- `InventoryCount Insert`: keys `InventoryCount="@@identity" Version=""`; data `Owner="4" Description EffectiveDate Posted="false"`. `Description` optional (omitted in 53 of 1554). `EffectiveDate` is user-chosen, date-only (`T00:00:00`), usually the day before TransDate.
- `InventoryCountDetail Insert`: keys `InventoryCountDetail="@@identity"`; subs `FromStep="1" FromTable="InventoryCount" FromKey="InventoryCount" Section="data"`; data `Item Location QtyEntered Qty`. Variants over history: data `Item Location` only (20103 rows, blank count sheet, last 851619); adding a line to an existing count sends `InventoryCount="<id>"` first in data and no subs (56).
  - `Item` = Item.Item; `Location` = Location.Location. At this site always 10669 (Location.Context WHS, Owner 4, Reference ACTWHS): counts are per item at warehouse level, no container/sublot (`InventoryCountDetail.Sublot` is always NULL).
  - `QtyEntered` is the text the user typed (column is varchar); `Qty` is the same value as a float.
- `InventoryCountDetail Update` (edit): keys `InventoryCountDetail`; data `QtyEntered Qty` (both always together).
- `InventoryCountDetail Delete`: keys `InventoryCountDetail` (no version). `InventoryCount Delete`: keys `InventoryCount Version`.
- `UpdateVersion:InventoryCount`: keys `InventoryCount Version` (1 after insert, +1 per subsequent request).

### 1b. Post shape (always)

1. `UpdateVersion:InventoryCount` keys `InventoryCount Version`
2. N x `Update:InventoryCountDetail` keys `InventoryCountDetail`, data `QtyAdjust`
3. `Execute:CreateChangeSet` data `Context="COUNT" Owner="4" ChangeDate`
4. N x `Execute:MoveInventory` subs `FromStep=<3's step> FromTable="ChangeSet" FromKey="ChangeSet" Section="data"`; data `Owner="4" Context="COUNT" SrcLocation QtyEntered Quantity Item ValidateStorageRules="false" IsQtyVerified="true"`
5. `Update:InventoryCount` keys `InventoryCount` (no Version), subs same ChangeSet -> data, data `Posted="true"`

MoveInventory steps are in the same order as the QtyAdjust steps (which is not necessarily ascending detail id; 768179 sends 38099 then 38098).

Value rules (verified on 866081, 861112, 768179, 851621):

- `ChangeDate` = InventoryCount.EffectiveDate.
- `QtyAdjust` = `Qty` minus current on-hand of the Item at the Location, computed client-side as a double.
- `MoveInventory.Quantity` = minus QtyAdjust, as a string negation of the same digits (`-299.73399996059965` -> `299.73399996059965`; `304.8` -> `-304.8`; `0` -> `-0`). Positive Quantity removes stock from `SrcLocation`.
- `MoveInventory.QtyEntered` = the detail's QtyEntered; `SrcLocation` = detail Location; `Item` = detail Item.
- Zero-adjust lines are still sent (MoveInventory with `Quantity="-0"`) and still create 0-qty movements (851621 -> InvMovement 674287-674290).

**Server results.** LogResult 866080: step 1 InventoryCount=1586, step 2 InventoryCountDetail=38401. LogResult 866081: step 1 InventoryCount, step 2 InventoryCountDetail, step 3 ChangeSet=139046, step 5 InventoryCount; nothing for MoveInventory steps. No LogRequest rows. Effects: one `InvMovement` per MoveInventory step (Context COUNT, ChangeSet, Log, Step) with `InvMovementDtl` Qty = QtyAdjust and a costed Value (861112 -> InvMovement 682213/682214, Qty -299.734 / +304.8, Value -1017.8638 / +948.9305); `InventoryCount.ChangeSet` set and `Posted=1`.

**Failures.** SaveChanges: none ever. Post: 20, all `Quantity of the item '<ItemCode>' is negative` (last 841909, item '3AC3200'), i.e. the adjustment would drive stock below zero, normally because on-hand changed between the client computing QtyAdjust and the post.

**Open questions.**
- Exact on-hand source the client uses for QtyAdjust (which view / costing-context filter). Insights must reproduce it or compute QtyAdjust at post time; float noise suggests a sum of float quantities.
- Whether the server validates QtyAdjust against Qty at all, or trusts the client (looks like it trusts it).
- Posting a count with zero detail lines never observed.

**Fixtures**

- `InventoryCountDataSet.SaveChanges/866080.xml` create, one line, counted 0
- `InventoryCountDataSet.SaveChanges/861111.xml` create, two lines
- `InventoryCountDataSet.SaveChanges/768178.xml` edit one line's quantity (UpdateVersion + Update detail)
- `InventoryCountDataSet.SaveChanges/798764.xml` delete an unposted count (2 details then header)
- `InventoryCountDataSet.Post/866081.xml` post of 866080, one negative adjustment
- `InventoryCountDataSet.Post/861112.xml` post of 861111, one negative and one positive adjustment
- `InventoryCountDataSet.Post/768179.xml` post after an edit (Version 2), float-noise quantities

---

## 2. `LocationTools.ResetLabelRequired` (Program PrintContainerLabel) (27105 / 0 / 4060)

**What it is.** After the client prints a container/sample label, it tells the server the label is no longer outstanding.

**Sequence.** One standalone single-step request per printed label, 4-8 s after the request that created the container (e.g. 866316 after BatchExecutionDataSet.InProcessTesting 866315). Shape identical in all 27105 rows:

```
<action Object="ResetLabelReqd" Step="1" Operation="Execute"><data Location="119590"/></action>
```

`Location` = Location.Location of the labelled container (119590 = LocationCode 031861, Context SMP).

**Server.** LogResult step 1: Location=<id>. LogRequest cascade (the only action here that has one): `<action Object="Location" Step="1" Operation="Update"><keys Location="119590"/><data LabelStatus="Hold,A,,," LabelReqd="0"/></action>`.

**Failures.** None ever.

**Open questions.** How `LabelStatus` ("Hold,A,,,") is derived is server-side; Insights only needs to send the Execute. Whether Insights should send it at all if it does not print the CMS label.

**Fixtures:** `LocationTools.ResetLabelRequired/866316.xml`, `866281.xml`, `866258.xml` (three different containers, same shape).

---

## 3. `InventoryTools.RecalculatePlanTrace` (Program Recalculate PlanTrace) (5251 / 0 / 288)

**What it is.** A manual "recalculate planning / expected ship dates" button.

**Sequence.** One single-step request, identical in all 5251 rows, taking 6-8 s:

```
<action Object="CalculateESD" Step="1" Operation="Execute"><data Owner="4"/></action>
```

No LogResult, no LogRequest, no failures.

**Open question.** Which tables CalculateESD rewrites (not visible from the log); long-running, so call it asynchronously.

**Fixtures:** `InventoryTools.RecalculatePlanTrace/866076.xml`, `865020.xml`, `864938.xml`.

---

## 4. `ItemUpdateDataSet.SaveChanges` (Program Item Update) (14938 / 129 / 1338)

**What it is.** Create/edit/delete an inventory item (bulk product or raw material, Item.Context SUNDRY) together with its chemical/custom property rows, QC tests, site stocking row and its packaged-product (pack size) child items (Item.Context PP).

**Sequence.** One request per save, however many child rows changed. (Not part of the sequence but commonly follows: an Item Name Update insert for the customer alias about a minute later, then recipe saves.)

**Skeleton.** Step 1 is always the parent item:

- `Insert:Item`, or
- `Update:Item` keys `Item Version`, data = changed columns only (seen: SpecificGravity, Description, AltDescription, Supplier, ItemCode, CostingRecipe), or
- `UpdateVersion:Item` keys `Item Version` when only child rows changed.

Then child steps, then always the two-step trailer:

```
Execute:InheritItemsPropertiesByPackagedProducts  subs FromStep="1" FromTable="Item" FromKey="Item" Section="data" FromName=""   data Owner="4" ChangeTests="true|false"
Execute:FixupItemManf                             subs FromStep="1" FromTable="Item" FromKey="Item" Section="data" FromName=""   (no data)
```

`ChangeTests="true"` iff an ItemTest row was inserted or deleted in the request. The only request with no trailer is a plain delete.

Recent shapes:

| n | Shape |
|---|---|
| 768 (+57 with 3 tests) | create bulk + tests + one pack: `Insert:Item, Insert:ItemChemical, Insert:ItemCustom, Insert:ItemTest x k, Insert:Item(PP), Insert:ItemChemical, Insert:ItemCustom, Insert:ItemEntity, Execute:FixupItemManf(pack), Insert:ItemPackagedProduct, Execute:InsertUpdatePackagedProductRecipe, trailer` |
| 22 | create plain item: `Insert:Item, Insert:ItemChemical, Insert:ItemCustom, trailer` |
| 46 | edit item columns: `Update:Item, trailer` |
| 76 | edit min stock: `UpdateVersion:Item, Update:ItemEntity, trailer` |
| 19 | add stocking row: `UpdateVersion:Item, Insert:ItemEntity, trailer` |
| 44 | edit parent that has packs: `Update:Item`, then for every pack size `[Update:Item(pack)] [Update:ItemEntity] Execute:FixupItemManf(pack), Update:ItemPackagedProduct(DateUpdated), Execute:InsertUpdatePackagedProductRecipe`, then trailer |
| 13+ | add pack to existing item: `UpdateVersion:Item, Insert:Item(PP), Insert:ItemChemical, Insert:ItemCustom, Insert:ItemEntity, FixupItemManf(pack), Insert:ItemPackagedProduct, InsertUpdatePackagedProductRecipe, trailer` |
| few | ItemTest insert/update/delete; `UpdateVersion:Item, Update:ItemChemical` |
| rare | delete item: `Execute:DeleteInventoryCostByItem (data Item), Delete:Item (keys Item Version)`; delete a pack (862427): UpdateVersion parent, `Delete:ItemPackagedProduct, Delete:Recipe, DeleteInventoryCostByItem, Delete:Item`, refresh of every remaining pack, trailer |

Attribute lists (order as sent):

- **Item Insert, bulk**: keys `Item="@@identity" Version=""`; data `ItemCode Prototype="false" Description Context="SUNDRY" Unit PkgType="1" Treatment="WHS" ReceivedApproved="false" IsKit="false" LotRequired="0" QtyPerPackage="1" NoExpiry="true" AutoApprove="true" SpecificGravity="1" PerPackageTolerance="100" PkgPerOuter="1" ByVolume="false" UnderReceive="1" OverReceive="1" GLGroup StandardCost="0.0000" CreateUser CreateArea="4" ShipInContainer CertifiedOrganic="false"`. Optional `Supplier` (17 of 879). User-entered: ItemCode, Description, Unit (`lb`/`ea`), GLGroup (`Finished Good`, `Raw Material`, `Resale`, ...), ShipInContainer (true on the raw-material example). Everything else is a client default. `CreateUser` = request User.
- **Item Insert, pack (PP)**: data `ItemCode Prototype Description AltDescription Context="PP" Unit Treatment ReceivedApproved IsKit LotRequired NoExpiry AutoApprove SpecificGravity ByVolume GLGroup Comment CreateUser CreateArea ShipInContainer CertifiedOrganic NotInHost="false" ControlledSubstance="false"`. ItemCode = parent ItemCode + `-` + ItemCode of the packaging prototype (Item with Context PROTOTYPE; e.g. Item 101338 code `50`, Comment `5LB SLIP LID CAN`); `Comment` = prototype.Comment; Description/AltDescription default to parent description plus a size suffix like ` (5 LB)` (user-editable; 863620 has no suffix); SpecificGravity copies the parent's with float noise.
- **ItemChemical Insert**: no keys; subs `FromStep=<item step> FromTable="Item" FromKey="Item" Section="keys"`. Bulk data: `ReverseWeightAllowed PPE="A" DisposalGroup="PENDING" HMISHealth="0" HMISFire="0" HMISReact="0" HMISCarcinogen HMISMutagen HMISTeratogen HMISIrritant HMISSensitizer HMISPoison HMISToxic HMISCorrosive HMISOxidizer HMISFlammable HMISCombustible Api Drug InSolution` (all false). Pack data omits PPE/DisposalGroup/HMIS numbers and inserts `CompressedGas DangerouslyReactive Biohazard DoNotFreeze NegativeWeightAllowed MayGangWeigh` before `InSolution`. Rare extras: `PercentVOC`, `ConcentrationPercent`. Update: keys `Item`, data e.g. `PercentVOC`.
- **ItemCustom Insert**: no keys; subs Item -> keys; data `ListCarcinogen ListEDSTA ListHPV ListPBTP ListPOP Sara302 Sara313 Seveso ListCRTQ` (false) `Group` (`Raw Material` / `Packaging Material`).
- **ItemTest Insert**: keys `Version="" ItemTest="@@identity"` (this order); subs Item -> data when the item is new, otherwise data starts with `Item="<id>"`; data `Test [Min] [Max] TestGroup="DEFAULT" OnReceipt="true" OnProduction="true" OnRetest="false" Grade="GMP" Line Deferrable="false"`. `Test` = Test.Test code; `Line` = 1-based sequence. Update: keys `Version ItemTest`, data changed of `Min`/`Max`/`Test`. Delete: keys `Version ItemTest`.
- **ItemEntity Insert**: keys `ItemEntity="@@identity"`; subs Item -> data (new pack) or data `Item="<id>"` first; data `[Item] Entity="4" Context="ST" [MinimumStock] Inactive="false" ByRequestOnly="false"`. Update: keys `ItemEntity`, data `MinimumStock` (may be `""`).
- **ItemPackagedProduct Insert**: keys `ItemPackagedProduct="@@identity"`; subs parent Item -> data (only when the parent is new in this request) and pack Item -> data with `ToName="PackagedProduct"`; data `AltID="1" DateUpdated [Item=<parent id> when parent already exists] PackagingPrototype Qty="1" Inactive="false"`. `DateUpdated` = client now (0-2 s before TransDate). Update: keys `ItemPackagedProduct`, data `DateUpdated` only (a touch that makes the next step rebuild the pack recipe).
- **InsertUpdatePackagedProductRecipe**: subs `FromStep=<IPP step> FromTable="ItemPackagedProduct" FromKey="ItemPackagedProduct" Section="data" FromName=""`; data `PlacedBy="<User>" Owner="4"`.

**Server results** (866294): step 1 Item.Item=123123 and Item.ItemCode=E9137; steps 2-3 ItemChemical.Item / ItemCustom.Item; steps 4-5 ItemTest ids; step 6 Item 123124 / E9137-50; step 9 ItemEntity; step 11 ItemPackagedProduct=7534; step 12 returns about 4800 `Item.ItemCode` rows (it touches every pack item; plus RecipeDetail rows on edits) so never dump LogResult for these unaggregated; step 13 Item.ItemCode of the pack. Verified rows: ItemPackagedProduct 7534 (Item 123123, PackagingPrototype 101338, PackagedProduct 123124, Recipe 21352, Qty 1, AltID 1); pack Item 123124.CostingRecipe = 21352 (created by step 12). No LogRequest rows.

**Failures (129).** Most common: `Cannot insert duplicate key row in object 'dbo.Item' with unique index 'IX_Item_ItemCode'. The duplicate key value is (X).` Others: `Item ([Item] = 'N') has changed since last read`; `Item ([Item] = 'N') does not exist`; `Cannot change item trace from "Inventory, No Lot Trace" to "No Inventory, No Lot Trace"` (and other combinations); delete blocked by FKs `FK_RecipeDetail_Item` (15), `FK_Item_ReplacedBy` (8), `FK_Ordr_Recipe` (7), `FK_InventoryUnitCost_Item`, `FK_InventoryUsed_InventoryCost`, `FK_InvMovementDtl_InventoryCost`, `FK_OrdDetail_ItemName`; `Cannot delete packaged product E6477-50. It is being referenced by order(s) or recipes(s).`

**Open questions.**
- What InheritItemsPropertiesByPackagedProducts and FixupItemManf do server-side (no LogRequest trace). Safe approach: always send them exactly as the client does.
- Full list of client defaults that differ by item type beyond the two observed templates (bulk SUNDRY vs PP); other GLGroup/Unit choices may alter defaults (e.g. ShipInContainer).
- On editing a parent with many pack sizes the client re-sends a refresh group for every pack; unknown whether skipping it leaves pack recipes stale.
- Item "trace" change rule (which column drives the "Cannot change item trace" error).

**Fixtures**

- `ItemUpdateDataSet.SaveChanges/864641.xml` create plain raw-material item (5 steps)
- `ItemUpdateDataSet.SaveChanges/866294.xml` create finished good with 2 tests and a 5 lb pack (14 steps)
- `ItemUpdateDataSet.SaveChanges/863620.xml` add a pack size to an existing item
- `ItemUpdateDataSet.SaveChanges/864642.xml` edit one Item column (Supplier)
- `ItemUpdateDataSet.SaveChanges/865429.xml` edit MinimumStock on ItemEntity
- `ItemUpdateDataSet.SaveChanges/858032.xml` add an ItemEntity stocking row
- `ItemUpdateDataSet.SaveChanges/852308.xml` delete an ItemTest (ChangeTests true)
- `ItemUpdateDataSet.SaveChanges/799699.xml` delete an item (no trailer)

---

## 5. `ItemNameUpdateDataSet.SaveChanges` (Program Item Name Update) (9140 / 57 / 1100)

**What it is.** Maintains customer-facing alias item codes: an Item row with Context NAME whose `ReplacedBy` points at the real (usually pack) item.

**Sequence.** One request per alias. Shapes: `Insert:Item, Execute:FixupItemManf` (972 recent); `Update:Item, Execute:FixupItemManf` (128); historic delete `Execute:DeleteInventoryCostByItem, Delete:Item` (7, last 721768).

- Insert: keys `Item="@@identity" Version=""`; data `ItemCode Prototype="false" Description Context="NAME" ReceivedApproved="false" IsKit="false" ReplacedBy NoExpiry="false" AutoApprove="false" ByVolume="false" CreateUser CreateArea="4" ShipInContainer="false" CertifiedOrganic="false" NotInHost="false" ControlledSubstance="false"`. `ReplacedBy` = Item.Item of the real item (866295: 123124 = E9137-50 created a minute earlier in 866294); Description typed/copied from that item.
- Update: keys `Item Version`; data only the changed one of `Description` (83), `ReplacedBy` (39), `ItemCode` (31).
- FixupItemManf: subs `FromStep="1" FromTable="Item" FromKey="Item" Section="data" FromName=""`.
- Delete: `DeleteInventoryCostByItem` data `Item`; `Item Delete` keys `Item Version`.

**Server.** LogResult step 1: Item.Item and Item.ItemCode (866295 -> 123125 / OR1457UV-50). No LogRequest.

**Failures.** All 57 are the duplicate ItemCode error (IX_Item_ItemCode is shared with real items), last 834770.

**Open question.** None significant; alias delete is blocked by `FK_OrdDetail_ItemName` once used on an order (seen under Item Update).

**Fixtures**

- `ItemNameUpdateDataSet.SaveChanges/866295.xml` insert alias
- `ItemNameUpdateDataSet.SaveChanges/864876.xml` edit Description
- `ItemNameUpdateDataSet.SaveChanges/859363.xml` re-point ReplacedBy
- `ItemNameUpdateDataSet.SaveChanges/721768.xml` delete alias (2025)

---

## 6. `EntityUpdateDataSet.SaveChanges` (Programs Bill To Update 1932/11/126, Ship To Update 855/11/85, Supplier/Manufacturer Update 678/6/40, Sales Rep Update 14/0/1)

Legacy Programs using the same Action: Ship Via Update (59/4), Warehouse/Lab Update (17), Salesman Update (8), Entity Update (2).

**What it is.** One shared `Entity` table holds customers (bill-to), ship-to addresses (child entities with `Parent` = bill-to), suppliers/manufacturers, carriers and sales reps, distinguished by Is* flags. Addresses and memos are stored through the `AddressRef` / `MemoRef` views keyed by (TableID, TableName='Entity', Reference).

**Sequence.** One request per save.

| Shape | When |
|---|---|
| `Update:Entity` | Entity columns changed. Keys `Entity Version`; data changed columns (seen: ShipVia, FreightAccount, Salesman, EntityCode, Terms, Incoterms, CreditLimit, FreightOption, IsShipTo, Inactive) |
| `UpdateVersion:Entity, Update:AddressRef` | address/contact fields only |
| `UpdateVersion:Entity, Insert:AddressRef` | add a second address (`Reference="ShipToAddress"`) to a bill-to |
| `UpdateVersion:Entity, Insert:MemoRef` / `Update:MemoRef` | memo text |
| `Update:Entity, Update:AddressRef [, Insert:MemoRef]` | combinations |
| `Insert:Entity, Insert:EntityDocument x 6, Insert:AddressRef [, Insert:MemoRef] [, Insert:AddressRef]` | create Bill To or Ship To |
| `Insert:Entity, Insert:AddressRef` | create Supplier (no EntityDocument rows) |

Attributes:

- **Entity Insert**: keys `Entity="@@identity" Version=""`. Data order: `EntityCode IsSupplier IsManufacturer IsSite IsLab IsWarehouse IsShipVia IsDivision IsInstallation IsCMS [Currency] [Parent] IsShipTo IsBillTo IsPriceList IsSalesman [SdsOnChange NoBill] Prototype [ShipVia] [Terms] [Incoterms] Inactive CancelBackOrder [FreightAccount] PoRequired ShipComplete DoNotShip SendMSDS SendCertificateOfAnalysis SendCertificateOfOrigin ShowCountryOfOrigin PackingListRequired IsRetain [CreditLimit]`.
  - Bill To: `Currency="USD" IsShipTo="true" IsBillTo="true"`, `Terms` (text code such as `NET 30`, `ADVANCE CC`), `CreditLimit`.
  - Ship To: `Parent=<bill-to Entity.Entity> IsShipTo="true" IsBillTo="false" SdsOnChange="false" NoBill="false"`, no Currency/Terms/CreditLimit.
  - Supplier: `IsSupplier="true" IsManufacturer="true"`, Currency, Terms, Incoterms.
  - `ShipVia` and `Salesman` are Entity.Entity ids of carrier / sales-rep entities; `FreightAccount` is free text. Other optional columns seen on updates only: Salesman, FreightOption, FreightService, Tax1Number.
- **EntityDocument Insert x 6** (customers only): keys `EntityDocument="@@identity"`; subs `FromStep="1" FromTable="Entity" FromKey="Entity" Section="data"`; data `Type Order="false" BillTo="false" Print="false"`, Types in the fixed order `W I P S C N`.
- **AddressRef Insert**: on create, keys `TableName="Entity" Reference="Address"` plus subs `FromStep="1" FromTable="Entity" FromKey="Entity" Section="keys" ToName="TableID"`; on an existing entity keys `TableID="<Entity>" TableName="Entity" Reference="Address|ShipToAddress"`, no subs. Data, in order, all but Name/AddrLine1/City optional: `[Address] Name AddrLine1 [AddrLine2] [AddrLine3] City [State] ZipCode [Email] [Phone] [Residential="false"]` (customers send Residential; the supplier example sends a leading `Address="5"` and no Residential). Country/Contact/Fax positions were not observed in the fetched samples.
- **AddressRef Update**: keys `TableID TableName Reference`; data changed fields only.
- **MemoRef Insert/Update**: keys `TableID TableName="Entity" Reference="InternalMemo|ShippingMemo|POMemo"` (subs with `ToName="TableID"` on create); data `Data="<text>"`.

**Server.** LogResult: step 1 Entity.Entity + Entity.EntityCode; each EntityDocument id; AddressRef/MemoRef steps return the composite key as one row with FieldName `Reference,TableID,TableName` and FieldValue e.g. `Address,101530,Entity`. No LogRequest. Verified storage: AddressRef rows for TableID 100896 / 101514 show the view resolves to shared `Address` rows (Address 101728, 101695, 101696), so the server de-duplicates/creates Address rows itself; the client never sends an Address id on customer saves.

**Failures.** Duplicate `IX_Entity_EntityCode` (most; EntityCode is unique across all entity types); delete blocked by `FK_Ordr_Entity_ShipTo`, `FK_Trans_BillToEntity`; `FK_Address_Country` / `FK_Address_State` (invalid Country/State code); `Entity ([Entity] = 'N') has changed since last read`; `Invalid PriceList`; `Entity has reference, cannot change Currency.`; `Cannot insert the value NULL into column 'Name', table 'CMS.dbo.Address'` (Name is mandatory on AddressRef).

**Open questions.**
- Meaning of `Address="5"` on the supplier AddressRef insert (looks like a client-side temp row id; server result shows a new address).
- Attribute positions for Country, CountryName, Contact, Fax, Department on AddressRef inserts.
- Meaning of the EntityDocument Type codes W/I/P/S/C/N and when Order/BillTo/Print become true.
- Entity delete shape was not sampled (it exists: FK failures above).
- State must exist in `State`, Country in `Country` (2-letter keys); Terms lookup table not identified.

**Fixtures**

- `EntityUpdateDataSet.SaveChanges/864934.xml` create Ship To under a parent bill-to (8 steps)
- `EntityUpdateDataSet.SaveChanges/863533.xml` create Bill To with email + phone
- `EntityUpdateDataSet.SaveChanges/853675.xml` create Supplier/Manufacturer
- `EntityUpdateDataSet.SaveChanges/864695.xml` Ship To: change ShipVia + FreightAccount (single Update:Entity)
- `EntityUpdateDataSet.SaveChanges/866298.xml` Ship To: edit address lines
- `EntityUpdateDataSet.SaveChanges/860665.xml` Bill To: edit email
- `EntityUpdateDataSet.SaveChanges/839948.xml` Bill To: add ShipToAddress
- `EntityUpdateDataSet.SaveChanges/851784.xml` Bill To: update InternalMemo (multi-line text)
- `EntityUpdateDataSet.SaveChanges/815408.xml` Sales Rep: rename (AddressRef.Name)

---

## 7. `PriceVersionUpdateDataSet.SaveChanges` (Program PriceVersion Update) (2038 / 35 / 180)

**What it is.** Supplier price lists. A `PriceVersion` is (Entity, EffectiveDate) with a comment; `PriceDetail` rows are per item / package type with up to five quantity-break prices. In recent data it is used for supplier (purchase) pricing.

**Sequence.** One request per save. Recent shapes:

| n | Shape |
|---|---|
| 60 | `UpdateVersion:PriceVersion, Update:PriceDetail x N` (price change) |
| 28 | `UpdateVersion:PriceVersion, Insert:PriceDetail x N` (add items) |
| ~70 | `Insert:PriceVersion, Insert:PriceDetail x N` (new effective-dated version; N up to dozens because the client copies all lines of the previous version) |
| 6 | `Insert:PriceVersion, Insert:PriceDetail x N, Update:Entity` (first price list for a supplier) |
| 8 | `UpdateVersion:PriceVersion, Delete:PriceDetail x N [, Update:PriceDetail ...]` (deletes come before updates) |
| 7 | `Update:PriceVersion [, Update/Insert:PriceDetail]` (comment change) |

Attributes:

- **PriceVersion Insert**: keys `PriceVersion="@@identity" Version=""`; data `Entity EffectiveDate [Comment]`. EffectiveDate date-only, may be in the future. Unique on (Entity, EffectiveDate).
- **PriceVersion Update**: keys `PriceVersion Version`; data `Comment`. **UpdateVersion**: keys `PriceVersion Version`.
- **PriceDetail Insert**: keys `PriceDetail="@@identity" Version=""`; subs `FromStep="1" FromTable="PriceVersion" FromKey="PriceVersion" Section="data"` when the version is new, otherwise data starts with `PriceVersion="<id>"`. Data order: `[PriceVersion] Item [PkgType] Description [EntityQuantity] EntityUnit PriceByPackage="false" [MinOrder1] Price1 [MinOrder2 Price2] [MinOrder3 Price3] [MinOrder4 Price4] Currency="USD" InvItem [EntityItemCode]`. Tier pairs sit between Price1 and Currency (confirmed on 860690 with 3 tiers and 858554 with 4 tiers: `MinOrder1="4" Price1="40.232" MinOrder2="100" Price2="38.808" MinOrder3="200" Price3="34.24" MinOrder4="500" Price4="31.672" Currency="USD"`). `Comment` was only observed on Update (825279: data `Comment="$1000 minumim PO"`); its position on Insert is unconfirmed.
  - `Item` = `InvItem` = Item.Item (identical in every sample); `Description` copied from Item.Description; `EntityUnit` = Item.Unit; `PkgType` = Item.Item of a packaging item with Context PACKAGE (101186 BAG, 101077 PAIL, 101084 DRUM); `EntityQuantity` = quantity per package (22, 44, 440); PkgType and EntityQuantity are omitted together for `ea` items; `EntityItemCode` = supplier's part number (free text); `Price1` as typed.
- **PriceDetail Update**: keys `PriceDetail Version`; data changed only (Price1 106, EntityItemCode 10, Description, MinOrder1, EntityQuantity, Comment). **Delete**: keys `PriceDetail Version`. PriceDetail has its own Version, unlike the other child tables in this document.
- **Entity Update** (first list only): keys `Entity Version`; data `PriceList="<same Entity id>"`, i.e. the supplier is made its own price list.

**Server.** LogResult: PriceVersion id at step 1, PriceDetail id per detail step, Entity + EntityCode for the Entity step (853677 -> PriceVersion 741, PriceDetail 16823/16824). No LogRequest. Verified rows: PriceDetail 16823/16824 (PriceVersion 741, Entity 101519 COMPANY1, Entity.PriceList 101519, Price1 9.0000 / 6.7300, Version 1); PriceDetail 16793 Version 2 after 865647; PriceVersion 733 Version 6 after requests sent with Version 4 and 5.

**Failures (35).** `IX_PriceVersion_EntityEffectiveDate` duplicate (second version for same supplier/date; most); `Cannot insert duplicate key row in PriceDetail. The duplicate key value is (ItemCode:…, Currency:USD, PkgType:…, PerPkg:…, Unit:lb, PriceByPkg:0, Manufacturer:)` (trigger-enforced uniqueness within a version); `PriceVersion ([PriceVersion] = 'N') has changed since last read`.

**Open questions.**
- How sales pricing uses these tables (Entity.IsPriceList, customers' PriceList) - not exercised recently through this program.
- Which PriceVersion is "current" for purchasing (latest EffectiveDate <= today presumably).
- `Manufacturer`, `VerifiedDate`, `LeadTime`, `DefaultVerifiedDate` columns exist but are never sent recently.
- Deleting a whole PriceVersion not observed recently.

**Fixtures**

- `PriceVersionUpdateDataSet.SaveChanges/853677.xml` first price list for a new supplier (2 details + Entity.PriceList)
- `PriceVersionUpdateDataSet.SaveChanges/849338.xml` new version with multi-line Comment, one `ea` item (no PkgType)
- `PriceVersionUpdateDataSet.SaveChanges/864696.xml` add a PriceDetail to an existing version
- `PriceVersionUpdateDataSet.SaveChanges/865647.xml` edit a PriceDetail (EntityItemCode)
- `PriceVersionUpdateDataSet.SaveChanges/789376.xml` delete a PriceDetail
- `PriceVersionUpdateDataSet.SaveChanges/809831.xml` edit the version Comment only

---

## 8. Brief notes (no fixtures)

**`TaxRuleUpdateDataSet.SaveChanges`** (Program "Tax 1 Rule Update", 10 / 0 / 3, last 858511). Single-step edits of the sales-tax rate table: `<action Object="TaxRule" Step="1" Operation="Update"><keys TaxRule="15" Version="4"/><data Rate="10.25"/></action>`. Rare admin action; no failures.

**`RoleUpdateDataSet.SaveChanges`** (Role Update, 99 / 4 / 3, last 859764). Security roles and per-program permissions: `UpdateVersion:Role` keys `Role="Lab" Version="9"`, then `Update:RoleProgram` keys `Role Program="actCoatingsProductUpdate"`, data `Allow="Allow"`. Role key is the role name. The 4 failures are 2014 (FK_AssignableRole_Role, NULL Role).

**`UserUpdateDataSet.SaveChanges`** (User Update, 62 / 0 / 1, last 838003). Adds/edits CMS users: `Insert:User` keys `User="pic\<login>" Version`, data `DisplayName DefaultArea="4" Role Prototype="false" Disabled="false"`. Key is the Windows login.

**`CountryUpdateDataSet.SaveChanges`** (Country Update, 6 / 0 / 1, last 824313). `Insert:Country` keys `Country="MX"`, data `Name="Mexico" CodeA3="MEX" Number="484"`. Needed before an address can use a new country (see FK_Address_Country).

**`TestUpdateDataSet.SaveChanges`** (Test Update, 77 / 5 / 1, last 849512). QC test definitions: `Insert:Test` keys `Test="<code>" Version`, data `Description TestResultType="BOOL" TestGroup="DEFAULT" Prototype="false"`. Failures: `The result has been recorded for this test "…", cannot change TestResultType` (3); delete blocked by FK_OrdDetailTest_Test, FK_ItemTest_Test.

**`Shell.Login` / `Shell.Logout`** (Program Shell; 12313 / 11732, Logout 2 failed). Real Execute requests, not journal entries: `<action Object="Login" Step="1" Operation="Execute"><data User="pic\<login>" Area="4"/></action>` and the same with `Object="Logout"`. They register/release the session (licence seat). The two Logout failures are ResultCode 60267 `Request TransDate is out of tolerance.` (client clock drift), which shows the server validates TransDate.

**`Login.LicenseUnavailable`** (278). Pure journal entry: `<action Object="LicenseUnavailable" Step="1" Operation="Log"/>`; the envelope has no `Owner` attribute (user never got a session). Indicates all licence seats were in use; relevant if Insights logs in as a CMS user.

**`Shell.SaveGridLayout`** (about 200, 1 recent: 834768). `Execute:SaveGridLayout` with data `Name="<Form.gridView>" Layout="<escaped DevExpress XtraSerializer XML>"`. Program = the window title where the grid lives. Per-user UI preference storage; irrelevant to Insights.

**`*.ExportToExcel`** (dozens of Actions named `<X>SetViewerDataSet.ExportToExcel` / `<X>DataSet.ExportToExcel`; largest ShipmentDetailsSetViewer 408, OpenShippingOrderDetail 287, InventoryMovementsSetViewer 172). Confirmed Log-only journal entries: the whole body is `<action Object="ExportToExcel" Step="1" Operation="Log"/>`, about 330 chars, always ResultCode 0, no data. They record that a user exported a viewer grid; nothing is written to business tables.

---

## 9. Cross-cutting open questions

1. Inventory count: the client-side on-hand figure behind `QtyAdjust` (and therefore `MoveInventory.Quantity`) cannot be derived from the log; needs the on-hand query or a decision to compute at post time.
2. Item saves: the server-side effect of `InheritItemsPropertiesByPackagedProducts`, `FixupItemManf` and `InsertUpdatePackagedProductRecipe` (the latter touches about 4800 items per call) is opaque; replicate the call pattern exactly rather than reason about it.
3. Insert `Version` values other than `""` are ignored, but this was verified on Item only.
4. AddressRef: optional attribute order for Country/Contact/Fax and the meaning of the supplier `Address="5"`.
5. Whether Insights needs a Shell.Login session (licence seat) before sending writes, given LicenseUnavailable events and TransDate tolerance checking.
6. EntityDocument type codes and PriceVersion's role in sales pricing.
