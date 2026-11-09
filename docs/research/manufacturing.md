# CMS write research: batch tickets, recipes, batch execution

Source: read-only SELECTs against `CMS.dbo.Log` (request XML), `LogResult` (ids the server returned per step),
`LogRequest` (server-side cascades), plus `Ordr`, `OrdDetail`, `OrdDetailCommit`, `OrdDetailTest`, `ItemTest`,
`Recipe`, `RecipeDetail`, `Lot`, `Sublot`, `ChangeSet`, `ChangeSetMFReceipt`, `InventoryCost`, `RecipeReplacement*`.
Research date 2026-11-09, client `AppVersion="7.15.0.680"`. "Recent" means `Log > 766353`.

Every user action below is **exactly one** `ProcessXMLRequest` call (one Log row) unless a section says otherwise.
A failed call has `ResultCode = 50000`, a ResultText of the form
`<Result Level="16" State="1" Procedure="ProcessXMLRequest" Line="171" Message="..."/>` and **no LogResult rows**
(the whole request is rolled back).

Contents

1. Common envelope and conventions
2. Batch ticket: create / edit (`ManufacturingOrderDataSet.SaveChanges`, Program `Batching Order`)
3. Batch ticket: release (`ManufacturingOrderDataSet.ReleaseOrder`, two programs)
4. Batch ticket: close (`ManufacturingOrderDataSet.CloseOrder`)
5. Recipes: save, publish, activate/deactivate
6. Recipe Replacement
7. Batch execution (Program `Batch Execution Express`)
8. End-to-end traced runs
9. Failures
10. Fixture index
11. Open questions

---

## 1. Common envelope and conventions

```
<request Application="Chemical Management System" AppVersion="7.15.0.680" Program="..." Action="..."
         Workstation="..." Owner="4" User="pic\<login>" TransDate="yyyy-MM-ddTHH:mm:ss" [StartDate=".."] [EndDate=".."]>
  [<event Method=".." Caption=".." ...>[<Memo>..</Memo>][<properties .../>]</event>]*
  <action Object=".." Step="n" Operation="Insert|Update|UpdateVersion|CheckVersion|Delete|Execute">
     [<keys .../>] [<subs .../>]* [<data .../>]
  </action>*
</request>
```

- Attribute order in `<request>` is fixed: Application, AppVersion, Program, Action, Workstation, Owner, User, TransDate,
  StartDate, EndDate. `Program` and/or `Action` are simply **absent** when Log.Program / Log.Action is NULL
  (see Recipe Replacement). `Owner="4"` everywhere (single-company install).
- The request is one line, no whitespace between elements. Child order inside `<action>` is always keys, subs, data.
- `Operation="Insert"`: `<keys X="@@identity"/>` (Ordr, Recipe and OrdDetailTest also carry `Version=""`).
  The new id is returned in `LogResult` for that step and can be referenced by later steps with `<subs>`.
- `<subs FromStep="n" FromTable="T" FromKey="K" Section="data" [FromName=""] [ToName="X"]/>` copies id K produced/used by
  step n into this step's `<data>` under name K (or `ToName`). Two forms occur and must be reproduced exactly:
  table steps omit `FromName`; Execute-procedure trailer steps carry `FromName=""`.
- `Operation="Update"` with `<keys Ordr=".." Version="n"/>` is the optimistic-concurrency check; Version is the current
  `Ordr.Version` / `Recipe.Version` read by the client, and the server increments it. `UpdateVersion` is "check and bump
  the header version without changing columns" and is step 1 of nearly every request that changes child rows.
  A stale version fails with `Ordr ([Ordr] = 'n') has changed since last read`.
- OrdDetail / RecipeDetail keys carry no Version (except `OrdDetail UpdateVersion ... Version=""` in InProcessTesting).
- Booleans are `true`/`false`; dates `yyyy-MM-ddTHH:mm:ss`; date-only values are sent as `...T00:00:00`.
  Numbers are .NET double round-trip strings, so client-computed values look like `4.5359237009071851`.
  They must be sent exactly as the client would compute them only if byte equality matters; the server stores them as-is.
- Contexts: `Ordr.Context` `MFBA` = bulk batch ticket, `MFPP` = pack (packaged product) ticket.
  `OrdDetail.Context`: `BA` batch/phase node, `PK` product made, `UB` "use batch" link, `UI` ingredient,
  `INSTR` instruction, `IPT` in-process test, `FT` final test (server-created), `UGR` ingredient group (repack).
  `Recipe.Context` `RMBA`; RecipeDetail uses the same line contexts.
- Statuses: `NST` not started, `RLS` released, `CMP` complete, `CLS` closed; `UserHold="HOLD"` until released.

---

## 2. Batch ticket create / edit — `ManufacturingOrderDataSet.SaveChanges`, Program `Batching Order`

Volume 62,558 (15 failed, none recent). One request per press of Save. The usual life of a ticket is three or four
consecutive requests by the same user/workstation, seconds apart:

| # | Request | Example run A | Example run B | Example run C (repack) |
|---|---------|---------------|---------------|------------------------|
| 1 | SaveChanges: create bulk ticket (MFBA) | 866303 | 865831 | 861076 |
| 2 | SaveChanges: add pack ticket(s) (MFPP), one request can add several | 866304 | 865832 | (861077: add repack source line) |
| 3 | ReleaseOrder (section 3) | 866305 | 865833 | 861079 |

### 2.1 Create bulk ticket from a recipe (fixture 866303, 8,015 chars)

Events (always these two, in this order, when a batch size was entered):
`<event Method="ScaleOrderQuantity" Caption="ScaleOrderQuantity"/><event Method="ScaleOrderQuantity"/>`.

Step 1 `Ordr Insert`, `keys Ordr="@@identity" Version=""`, data attributes in order:

| Attribute | Value / source |
|---|---|
| Context | `MFBA` |
| Owner | `4` |
| Recipe | `Recipe.Recipe` of the published recipe chosen. **Omitted** for a recipe-less (repack) ticket |
| DateOrdered | client clock at form open |
| DateRequired, PlanStartDate | user-entered; default = DateOrdered (866303) or a later date (861076) |
| Status | `NST` |
| UserHold | `HOLD` |
| PlacedBy | same as request User |
| BasePriority | `100` |
| AllowOvercommit | `true` or `false` (both seen on create; see open questions) |
| Comment | copied from `Recipe.Comment` (e.g. `NEW`); free text for repack tickets |
| PreweighZone, ExecutionZone, PackagingZone | constants `1`, `3`, `4` |
| PercentPrecision | `Recipe.PercentPrecision` (2) |
| ChangeNumber | client-supplied counter (82239, 81659...). Shared numbering with other order types; source not identified |
| Revision | `0` |
| SimpleFinalPhase, PreweighOnly | `false` |
| BillTo | optional (present 866303, 861076; absent 860225). Customer id chosen on the form |
| Ready | `false` |

Steps 2..n `OrdDetail Insert` — one per RecipeDetail row, `keys OrdDetail="@@identity"`, first
`<subs FromStep="1" FromTable="Ordr" FromKey="Ordr" Section="data"/>`, then for child lines a second
`<subs FromStep="<parent step>" FromTable="OrdDetail" FromKey="OrdDetail" Section="data" ToName="Parent"/>`.
Order sent: BA, PK (parent BA), UB (parent PK), UI lines in `Line` order (parent BA), INSTR lines (parent BA).
Per context, the `<data>` attributes in order:

- **BA**: `RecipeDetailReference Context="BA" AltID Owner="4" Phase BatchType TimeToAddUnit TotalWeight TotalVolume Status="NST" ExecStatus="NST" ExecOrder Discarded="false" Inactive="false"`
- **PK**: `RecipeDetailReference Context="PK" AltID="1" Owner Item QtyEntered QtyReqd StdQty="1" TotalWeight TotalWeightPercent="100" TotalVolume TotalVolumePercent="100" IsOpen="true" Status ExecStatus Discarded RepackagingYield="0" Inactive`
- **UB**: `RecipeDetailReference Context="UB" AltID Owner MustPreweigh="0" Status UseFrom ExecStatus Discarded IsCommentFormatted="false" Inactive`
- **UI**: `RecipeDetailReference Context="UI" AltID Owner Line Item QtyEntered QtyReqd StdQty IsOpen="true" HoldAllowed="false" MustPreweigh="0" IgnoreDrug="false" Status ExecStatus ExecOrder Discarded IsCommentFormatted="false" AdjControlValue="100" Inactive`
- **INSTR**: `RecipeDetailReference Context="INSTR" AltID Owner Description Status ExecStatus ExecOrder Discarded RecordFirst="false" RecordLast="false" Inactive`

Value sources (verified by joining OrdDetail to RecipeDetail on `RecipeDetailReference = RecipeDetail.RecipeDetail`):

- `RecipeDetailReference` = `RecipeDetail.RecipeDetail`; `AltID`, `Line`, `ExecOrder`, `Item`, `Phase`, `BatchType`,
  `TimeToAddUnit`, `UseFrom`, `AdjControlValue`, `Description`, `MustPreweigh`, `IgnoreDrug` copied from RecipeDetail.
- `StdQty` = `RecipeDetail.QtyReqd` (per 1 unit of product; PK StdQty = 1).
- `QtyReqd` = StdQty x batch size (client arithmetic in double, hence `0.050000001`).
- `QtyEntered` = `"<QtyReqd> <unit>"` for UI lines (`4.34 lb`, `2.15 ea`; unit is the unit part of
  `RecipeDetail.QtyEntered`), bare number for PK.
- `TotalWeight` (kg) = batch lb x 0.45359237 (client), `TotalVolume` = client-computed from ingredient densities
  (not reproduced; BA and PK values differ in the last digits). `TotalWeightPercent`/`TotalVolumePercent` = 100.

Trailer (always, all referencing step 1 with `FromName=""` except CreateDocument which uses `ToName="TableID"`):

```
CheckOrderItemsStatus  Execute  subs(Ordr)
CreateDocument         Execute  subs(Ordr -> TableID)  data TableName="Ordr" Role="Ordr" Prefix="OO" Description="Batching Order"
VerifyESD              Execute  subs(Ordr)  data Owner="4"
ModifyMFOrderProcedureDetails Execute subs(Ordr) data UpdateStatus="false" UpdateLot="false"
UpdateLinkedPackagingOrders   Execute subs(Ordr)
NotifyMFOrder          Execute  subs(Ordr)  data NotificationCode="MFO Created Notification"
```

Server returns (LogResult): new `Ordr` (step 1), each new `OrdDetail`, and a `Document` id for CreateDocument.

### 2.2 Create recipe-less / repack bulk ticket (fixtures 861076, 860225)

Same Ordr Insert without `Recipe`. Only a PK line (`RecipeDetailReference` is still sent: it is the PK line of the
item's own recipe/"costing" RecipeDetail; `StdQty="1" TotalWeight="0" TotalVolume="0"`, no QtyEntered/QtyReqd,
`RepackagingYield="0"` or the yield quantity). 860225 also inserts the repack source line in the same request:
`Context="UI" IsOpen="true" IsRepackaging="true" AltID="2" Owner="4" Item QtyEntered="5" QtyReqd="5" QtyYield="5" MustPreweigh="0" Status="NST" ExecStatus="NST" Discarded="false"` (no RecipeDetailReference, no Parent). Same trailer.

Follow-up edit 861077 adds the source line later: `Ordr UpdateVersion`; `OrdDetail Update keys <PK> data RepackagingYield="30"`;
`OrdDetail Insert` with literal `Ordr="194238"` in data (no subs because the Ordr already exists); trailer
VerifyESD / ModifyMFOrderProcedureDetails / UpdateLinkedPackagingOrders.

### 2.3 Add pack ticket(s) linked to the bulk (fixtures 866304, 865832; 865555 adds a second one mid-run)

1. `Ordr UpdateVersion keys Ordr=<bulk> Version=<current>`.
2. `Ordr Insert keys Ordr="@@identity" Version=""` data:
   `Context="MFPP" Owner="4" Recipe=<pack recipe> DateOrdered=<now> DateRequired=<bulk DateRequired> PlanStartDate=<bulk PlanStartDate> Status="NST" UserHold="HOLD" PlacedBy BasePriority="100" AllowOvercommit="false" Comment=<pack recipe comment, e.g. "5LB SLIP LID CAN"> PreweighZone="1" PercentPrecision="2" Revision="0" SimpleFinalPhase="false" PreweighOnly="false" Ready="false"`
   (no ExecutionZone/PackagingZone, ChangeNumber or BillTo). Recipe = the pack item's `Item.CostingRecipe`.
3. `OrdDetail Insert` PK (subs -> step 2 Ordr):
   `RecipeDetailReference Context="PK" AltID="1" Owner Item=<pack item> QtyEntered QtyReqd StdQty="1" TotalWeightPercent="100" TotalVolumePercent="100" IsOpen="true" Status ExecStatus Discarded Inactive`.
4. `OrdDetail Insert` UI per pack recipe line (subs -> Ordr step 2, and -> PK step as `Parent`):
   `RecipeDetailReference Context="UI" AltID Owner Item QtyEntered QtyReqd StdQty IsOpen="true" HoldAllowed="false" MustPreweigh="0" IgnoreDrug="false" Status ExecStatus Discarded IsCommentFormatted="false" Inactive`
   (no Line, ExecOrder or AdjControlValue). One of these lines is the bulk item itself (`Item` = bulk PK item, unit lb).
5. `OrdDetailCommit Insert keys OrdDetailCommit="@@identity"`, `subs FromStep=<bulk-item UI step> FromTable="OrdDetail" FromKey="OrdDetail" Section="data"`,
   `data SrcOrdDetail=<bulk ticket PK OrdDetail> Qty=<lb drawn from the bulk>`. **This row is the bulk-to-pack link**
   (`OrdDetailCommit.OrdDetail` = pack UI line, `.SrcOrdDetail` = bulk PK line).
6. Trailer on step 1 (the bulk Ordr): VerifyESD (omitted in 865555 where the bulk was already released),
   ModifyMFOrderProcedureDetails, UpdateLinkedPackagingOrders. No CreateDocument/Notify for pack tickets.

### 2.4 Edits

| Scenario | Fixture | Shape |
|---|---|---|
| Header field (BillTo) | 850590 | `Ordr Update keys Ordr Version / data BillTo`; trailer VerifyESD, ModifyMF..., UpdateLinked... |
| Header field (AllowOvercommit) | 862211 | same with `data AllowOvercommit="true"` (the standard fix for a failed release) |
| Dates on a released ticket | 839011 | `data DateRequired PlanStartDate`; trailer plus `CreateLotForPackagingProductOrders subs(Ordr -> ParentOrdr, FromName="") data User LotPrefix=<yyMMdd> LotLength="3"` |
| Rescale batch size | 863400 | events ScaleOrderQuantity x2; `Ordr UpdateVersion`; `OrdDetail Update` BA `TotalWeight TotalVolume`; PK `QtyEntered QtyReqd TotalWeight TotalVolume`; each UI `QtyEntered QtyReqd`; trailer |
| Remove a pack ticket | 857625, 863850 | `Ordr UpdateVersion` bulk; `CloseLinkedPackagingOrders Execute data Ordr=<bulk> DemandOrdr=<pack>`; `Ordr UpdateVersion` pack; `ValidateSupplyQtyForPackagedProductOrder data OrdDetail=<pack bulk-item UI line> SupplyQty="0"`; `OrdDetailCommit Delete keys OrdDetailCommit`; trailer (863850, released bulk, also VerifyESD + CreateLotForPackagingProductOrders) |
| Change pack quantity | 865555 (steps 1-4) | `UpdateVersion` both Ordrs; `ValidateSupplyQty... SupplyQty=<new>`; `OrdDetailCommit Update keys OrdDetailCommit data OrdDetail SrcOrdDetail Qty` |

Only changed columns are sent in `<data>` of an Update.

---

## 3. Release — `ManufacturingOrderDataSet.ReleaseOrder`

### 3.1 Program `Batching Order` (32,289 total; 2,689 failed, 182 recent)

One request. Fixture 866305 (bulk from recipe):

```
event ManfLotNotSpecified / event manflotnotspecified      (both always present, second is lower-case)
1  Ordr Update   keys Ordr Version   data DateReleased="<today>T00:00:00" Status="RLS" UserHold=""
2  OrdDetail Insert  data Ordr=<literal> Context="IPT" AltID=<max AltID+1> Owner="4" Parent=<BA OrdDetail> Description="Specification Test"
                     Qualifier="F" Status="NST" ExecStatus="NST" ExecOrder=<max ExecOrder+1> Discarded="false" RecordLast="false"
                     RecordFirst="false" AutoApprove="true" Inactive="false"
3+ OrdDetailTest Insert keys OrdDetailTest="@@identity" Version=""  subs(step 2 OrdDetail)
                     data Test [Min] [Max] TestGroup Grade Line Deferrable
n  UpdateOrderQtyCommitted Execute data Ordr
   CreateLot Execute data Item=<PK item> OrdDetail=<PK line> ManfLotSameAsLot="true" Status="Hold" Manufacturer="9" LotPrefix="<yyMMdd>" Length="3"
   CreateLotIngredient Execute subs(FromStep=<CreateLot> FromTable="Lot" FromKey="Lot" FromName="")
   CreateLotComponent  Execute subs(same)
   CreateMFOrderFinalTests Execute subs(step 1 Ordr, FromName="")
   CreateLotForPackagingProductOrders Execute subs(step 1 Ordr, FromName="" ToName="ParentOrdr") data User LotPrefix LotLength="3"
   ModifyMFOrderProcedureDetails Execute subs(Ordr) data UpdateStatus="false" UpdateLot="false"
   NotifyMFOrder Execute subs(Ordr) data NotificationCode="MFO Released Notification"
```

- The IPT line and its tests are **client-built at release time**. OrdDetailTest rows are one per `ItemTest` row of the
  bulk PK item (verified for Item 121303: DRAWDOWN line 1; GRIND Min 0 Max 2 line 2; TestGroup DEFAULT, Grade GMP,
  Deferrable false). Min/Max omitted when NULL. Items with no ItemTest rows: no IPT insert at all (865823).
- `LotPrefix` = release date `yyMMdd`; the server appends a 3-digit sequence (`Length="3"`) to make the lot number.
- LogResult returns: Ordr; IPT OrdDetail; OrdDetailTest ids; Lot, SubLot (and Release id) from CreateLot; the FT
  OrdDetail created by CreateMFOrderFinalTests; lots created for linked pack tickets.
- Server cascades (LogRequest): QtyCommitted updates per UI line, Lot/Sublot/LotIngredient inserts, pack-ticket lots.

Variants:

- **Repack / recipe-less ticket already carrying structure** (865823): no IPT/OrdDetailTest;
  `CreateLot ... Status="Approved" Manufacturer="9" LotPrefix Length="3" ReleasedBy="pic\<user>" ManfDate="<datetime>"`.
- **Repack ticket released for the first time** (861079, 4,482 chars): the client also builds the missing structure:
  `OrdDetail Insert BA (Phase="FINAL" BatchType="2" TimeToAddUnit="hr" TotalWeight="0" TotalVolume="0" ExecOrder="1")`,
  `OrdDetail Update <PK> subs(BA -> Parent)`, `OrdDetail Insert UB (Parent=<PK> UseFrom=<BA AltID>)`,
  IPT (+OrdDetailTest rows), `OrdDetail Insert UGR (UseOrder="0" ExecOrder="2")`,
  `OrdDetail Update <repack UI line> subs(UGR -> Parent) data ExecOrder="3" StdQty="30"`, then the normal trailer.

### 3.2 Program `Batch Execution Express` (28,243 total, 0 failed)

Sent automatically by the execution screen for the **pack ticket** just before its first packout (it sits between
ExecutePackagingPhase and PackoutRequested, same second). Differences from the Batching Order form:

- no events; `Ordr Update data DateReleased Status="RLS" UserHold="" AllowOvercommit="true"` — the forced
  AllowOvercommit is why this program never fails with "Insufficient inventory";
- no IPT/tests, no CreateMFOrderFinalTests, no CreateLotForPackagingProductOrders;
- 866320 (pack ticket existed when the bulk was released, so its lot already exists): Ordr Update,
  UpdateOrderQtyCommitted, ModifyMFOrderProcedureDetails, NotifyMFOrder;
- 865557 (pack ticket added after the bulk release): additionally
  `CreateLot data Item OrdDetail=<pack PK line> ManfLotSameAsLot="true" Status="Approved" Manufacturer="9" LotPrefix Length="3" ReleasedBy ManfDate=<bulk PlanStartDate>`,
  CreateLotIngredient, CreateLotComponent.

---

## 4. Close — `ManufacturingOrderDataSet.CloseOrder` (Program `Batching Order`, 92 total, 0 failed)

One request.

- Completed ticket (854877): `Ordr Update keys Ordr Version data Status="CLS"`;
  `CloseLinkedPackagingOrders Execute data Ordr`; `ExportTransaction Execute data OrderCompleted=<Ordr>`.
- Never-run ticket (863585): `Ordr Update data DateCompleted="<now>" Status="CLS"`; then `OrdDetail Update keys OrdDetail
  data IsOpen="false"` for the PK line and every UI line (ExecOrder order, no Version); CloseLinkedPackagingOrders;
  ExportTransaction. LogResult shows the linked pack Ordr the server also closed.

---

## 5. Recipes (Program `Batching Recipe`)

### 5.1 `ManufacturingOrderDataSet.SaveChanges` (10,027 total, 30 failed)

A brand-new recipe is normally three requests by the same user within about a minute:
**SaveChanges (insert)** -> **SaveChanges (add phase/instructions)** -> **PublishRecipe**
(e.g. 861269 -> 861270 -> 861271; 863613 -> 863614 -> 863615).

Insert (fixtures 863613, 861269):

```
1 Recipe Insert keys Recipe="@@identity" Version=""
    data Context="RMBA" Owner="4" RecipeNumber="E9119.01" PlacedBy Comment="NEW" DateCreated=<now> IsPublished="false" Inactive="false"
         Imported="false" PercentPrecision="2" QtyPrecision="3" WeightUnit="lb" VolumeUnit="gal" Shared="false" Rework="false"
         DateUpdated="<today>T00:00:00"
2 RecipeDetail Insert keys RecipeDetail="@@identity" subs(step 1 Recipe)
    data Context="PK" AltID="1" Owner="4" Item QtyEntered="1" QtyReqd="1" TotalWeight="0.45359237" TotalWeightPercent="100" TotalVolume TotalVolumePercent="100"
3+ RecipeDetail Insert subs(step 1 Recipe)
    data Context="UI" AltID Owner Line Item QtyEntered="<n> lb" QtyReqd MustPreweigh="0" IgnoreDrug="false" Imported="false" IsCommentFormatted="false" AdjControlValue="100"
```

RecipeNumber is typed by the user (`<formula>.<2-digit version>`); a duplicate fails on unique index
`IX_Recipe_RecipeNumber`. Recipes are per 1 lb of product (PK qty 1; UI quantities are fractions summing to 1).

Add structure (fixture 861270): `Recipe UpdateVersion`; `RecipeDetail Insert` BA with **literal** `Recipe="21046"` in data
(`Context="BA" AltID Owner Phase="PHASE" BatchType="2" TimeToAddUnit="hr" TotalWeight TotalVolume ExecOrder="1" TimeRequiredUnit="hr"`);
`RecipeDetail Update keys <UI line> subs(BA -> Parent) data ExecOrder` for each ingredient;
`RecipeDetail Insert` INSTR (`Recipe Context="INSTR" AltID Owner Description ExecOrder RecordFirst="false" RecordLast="false" Inactive="false"`, subs BA -> Parent).

Simple edits: 865829 (`Recipe UpdateVersion`; `RecipeDetail Update data QtyEntered QtyReqd`), 846224 (quantity change plus
recomputed `TotalVolume`/`TotalWeightPercent` on BA and PK lines), 844641 (`Recipe Update keys Recipe Version data Comment`).

### 5.2 `ManufacturingOrderDataSet.PublishRecipe` (9,024 `Batching Recipe`; 3,754 with Program NULL)

One request (fixtures 861271, 863615, 866297; 862575 is the Program-less form sent by Recipe Replacement and has no
`Program` attribute at all):

```
1 Recipe UpdateVersion keys Recipe Version
[ RecipeDetail Update keys <BA line> data TotalWeight ]        optional (866297)
2 RecipeDetail Update keys <PK line> data Parent="<BA RecipeDetail>"
3 RecipeDetail Insert keys RecipeDetail="@@identity" data Recipe Context="UB" AltID=<max AltID+1> Owner="4" Parent=<PK line> UseFrom=<BA AltID> IsCommentFormatted="false"
4 PublishRecipe Execute data Recipe
```

Publishing is what gives the recipe its PK-under-BA / UB shape that ticket creation copies. LogResult for the
PublishRecipe step returns Recipe, the Item and ItemCode (and the superseded Recipe id): the server sets
`IsPublished`/`DatePublished`, points the item at the recipe, and marks the previous version Inactive
(verified: 12731 E6802.01 became inactive when 21087 E6802.02 was published).

### 5.3 `ManufacturingOrderDataSet.ChangeActiveState` (154 `Batching Recipe`, 2 `Batching Recipe (Readonly)`)

One request, one action: `Recipe Update keys Recipe Version data Inactive="true|false"`. Identical in both programs
(only the Program attribute differs). Fixtures 841135, 844803, 839561.

---

## 6. Recipe Replacement

A replacement run (swap ingredient A for B in N recipes) is a burst of requests by one user, about one per second.
Traced 862569-862580 (4 recipes) and 862696-863008 (153 recipes):

| # | Program | Action | Count | Content |
|---|---|---|---|---|
| 1 | `Recipe Replacement` | `RecipeReplacementDataSet.SaveChanges` | 1 | `RecipeReplacement Insert data Closed="false" DateCreated Description="<from code> to <to code>"`; `RecipeReplacementIngredient Insert subs(RecipeReplacement) data FromIngredient ToIngredient` (item ids); per recipe `RecipeReplacementRecipe Insert subs data Selected="true" Recipe RecipeNumber Status="4" Item` |
| 2 | `Recipe Replacement` | *(no Action attribute)* | N | Clone: `Recipe Insert keys Recipe="@@identity" Version="2"` (same data list as 5.1; RecipeNumber = old number with suffix +1; Comment = the replacement Description) and RecipeDetail inserts BA, UI (with `ExecOrder`, subs BA -> Parent, ingredient swapped), INSTR, PK (no Parent) |
| 3 | `RecipeReplacement` | *(no Action)* | 1 | `RecipeReplacementRecipe Update keys <id> data Status="1"` for every row |
| 4 | *(no Program)* | `ManufacturingOrderDataSet.PublishRecipe` | N | as 5.2, `keys Version="1"` |
| 5 | `RecipeReplacement` | *(no Action)* | 1 | `... data Status="3"` for every row |
| 6 | `RecipeReplacement` | *(no Action)* | 1 | `RecipeReplacement Update keys RecipeReplacement data Closed="true"` |

Note the two different Program spellings (`Recipe Replacement` with a space, `RecipeReplacement` without).
Step 1 of the large run (862699) is 47,570 chars and was not saved.

---

## 7. Batch execution (Program `Batch Execution Express`)

Execution requests carry `StartDate`/`EndDate` (equal to TransDate except Packaging, where StartDate is a few seconds
earlier; MarkOrderComplete has StartDate only; BatchExecutionExpress and CreateChangeSet have neither) and warning
`<event>` elements for each soft warning the operator clicked through:

| Method | Caption | Memo |
|---|---|---|
| SanitizationIsIncomplete | Sanitization incomplete | `Order #<Ordr>` |
| ReservationIsIncomplete | Ingredients incomplete | `Order #<Ordr>` |
| OrderEditQtyExceeded (signed: `User`, `UserExplanation="Your signature indicates that you have executed this step."`, `UserFullName`) | Maximum quantity exceeded | `Order <Ordr>` |
| NoCurrentAssembly | Packout without Assembly | empty `<Memo/>` |
| InsufficientQuantityForPackaging (`DataSet="BatchExecutionDataSet"` first) | Ingredients short | `Item(s): <codes>` |
| ManufacturingReviewIsIncomplete | Manufacturing Review is incomplete | U+00A0 + `Order #<Ordr>`, plus `<properties OrdrContext="MFBA"/>` |

Events are informational (they are logged); which ones appear depends on ticket state, so byte-equal reproduction
needs the same warnings. Constants: `SrcLocation="10669"` (the general stock location), `Location`/`CurrentLocation`/
`SampleLocation="2"`.

### 7.1 `BatchExecutionDataSet.NewIngredient` (13,905; 1 failed) — add an unplanned ingredient

`Ordr UpdateVersion keys Ordr Version`; `NewIngredient Execute data Ordr Item QtyReqd QtyEntered`
(QtyEntered is the raw text typed, e.g. `.22`; QtyReqd its numeric form `0.22`); `UpdateManufacturingOrderStatus Execute data Ordr`.
Server creates a new UI OrdDetail (returned in LogResult) used by the following BatchExecutionExpress.
One request per ingredient (Run D sent four in a row, versions 5-8). Fixtures 866313, 866099.

### 7.2 `BatchExecutionDataSet.BatchExecutionExpress` (31,013; 2,575 failed) — "make the batch"

One request consumes all ingredients into a vessel and completes all steps (fixture 866314):

```
1  Ordr UpdateVersion
2  CreateChangeSet Execute data Context="MF" Owner="4" Ordr
3  CreateInventoryCost Execute subs(step 2 ChangeSet) data Owner Item=<bulk item> QtyMK="0" IsBulk="true" UnitCostMK=<client estimate>
4  CreateMFReceipt Execute subs(ChangeSet)
5  CreateLocationRecord Execute subs(ChangeSet) data Owner InLocation="" LocationCode="VO<Ordr>" Context="VSL" OrdDetail=<BA line> MarkAsDeletedIfAlreadyExists="true"
per ingredient actually used:
   MoveInventory Execute subs(ChangeSet) subs(step 5 Location -> TrgLocation)
       data Owner Context="CMNGL" SrcLocation="10669" Quantity SrcOrdDetail=<UI line> TrgOrdDetail=<BA line> Item QtyEntered UseContext="US" MakeContext="MKB" UseUnreservedInventory="true"
   UpdateManufacturingOrdDetailQtyUsed Execute data OrdDetail=<UI line>
then status steps in ExecOrder:
   INSTR line:  UpdateManufacturingOrdDetailExecStatus data OrdDetail Status="CMP"
   UI line:     UpdateManufacturingOrdDetailPreweighStatus data OrdDetail Status="CMP"  then  ...ExecStatus data OrdDetail Status="CMP"
last: UpdateManufacturingOrdDetailExecStatus data OrdDetail=<BA line>      (no Status attribute)
      UpdateManufacturingOrderStatus data Ordr
```

- Quantity/QtyEntered = actual quantity entered (defaults to OrdDetail.QtyReqd). A UI line with nothing consumed
  still gets its Preweigh/Exec CMP steps but no MoveInventory (QtyUsed stays NULL).
- LogResult: ChangeSet, InventoryCost, Receipt (`ChangeSetMFReceipt.Receipt`), Location (the vessel), inventory rows.
- **Re-run after ReverseReceipt** (fixture 865042): no CreateChangeSet/CreateInventoryCost/CreateLocationRecord;
  `CreateMFReceipt data ChangeSet="<original MF ChangeSet>"`; MoveInventory has literal `ChangeSet` and `TrgLocation`
  attributes (order `Owner Context ChangeSet SrcLocation TrgLocation Quantity SrcOrdDetail TrgOrdDetail Item QtyEntered UseContext MakeContext UseUnreservedInventory`) and no subs.

### 7.3 `BatchExecutionDataSet.InProcessTesting` (27,119; 0 failed)

`OrdDetail UpdateVersion keys OrdDetail=<BA line> Version=""`; `Location CheckVersion keys Location=<vessel> Version="1"`;
`UpdateManufacturingOrdDetailExecStatus data OrdDetail=<IPT line>`; `UpdateManufacturingOrderStatus data Ordr`;
`CreateSamplingRequest data SubLot=<bulk Sublot from release> CreateSample="true" SampleDate SampledBy SampleLocation="2" Vessel=<vessel Location> OrdDetail=<IPT line>`.
Fixtures 866315, 866101. Test entry and approval then happen in other programs/actions
(`ResetLabelRequired`, `TestsDataSet.SaveChanges`, `ReleaseDataSet.Approve`) — not in this document's scope.

### 7.4 `BatchExecutionDataSet.ExecutePackagingPhase` (28,198; 0 failed)

Single action `ExecutePackagingPhase Execute data OrdDetail=<bulk PK line> Location="2"`. Fixtures 866319, 866106.

### 7.5 `BatchExecutionDataSet.PackoutRequested` (37,586; 0 failed)

Single action `PackoutRequested Execute data Ordr=<bulk Ordr> Item=<pack item>`. Sent before **every** packout.
Fixtures 866321 (no event), 866270 (with ReservationIsIncomplete event).

### 7.6 `BatchExecutionDataSet.CreateChangeSet` (28,131; 0 failed)

`Ordr UpdateVersion keys Ordr=<pack Ordr>`; `CreateChangeSet Execute data Context="MF" Owner="4" Ordr=<pack Ordr>`.
Sent once per pack ticket, before its first Packaging; the returned ChangeSet id is carried **as a literal** into that
Packaging request. Fixtures 866322, 866109.

### 7.7 `BatchExecutionDataSet.Packaging` (37,573; 295 failed)

First packout into a new container (fixtures 866323, 866110):

```
1  Ordr UpdateVersion keys Ordr=<bulk Ordr>
2  PackoutStarted Execute data Ordr=<bulk>
3  CreateInventoryCost Execute data Owner ChangeSet=<from 7.6> Item=<pack item> QtyMK="0" UnitCostMK=<client estimate>
4  CreateContainer Execute data ChangeSet=<from 7.6> Owner InLocation="" Prefix="V" OrdDetail=<pack PK line>
5  CreateChangeSet Execute data Context="MFP" Owner Ordr=<pack Ordr>
6  MoveInventory subs(step 5 ChangeSet) subs(step 4 Location -> TrgLocation)
       data Owner Context="PCKAGE" TrgOrdDetail=<pack PK> Item="1" MakeContext="MKB" UseUnreservedInventory="true" SrcLocation="10669" Quantity=<number of packages> UseContext="US"
7  per packaging component: MoveInventory (same subs)
       data Owner Context="PCKAGE" SrcOrdDetail=<pack UI line> TrgOrdDetail Item MakeContext="MKB" UseUnreservedInventory="true" SrcLocation="10669" Quantity UseContext="US"
       + UpdateManufacturingOrdDetailQtyUsed data OrdDetail
8  bulk draw: MoveInventory (same subs)
       data ... SrcOrdDetail=<pack bulk-item UI line> TrgOrdDetail Item=<bulk item> MakeContext="MKB" UseUnreservedInventory="true" Sublot=<bulk Sublot> TareEntered="0" SrcLocation=<vessel> Quantity=<lb> UseContext="USB"
       + UpdateManufacturingOrdDetailQtyUsed
9  make: MoveInventory subs(step 5 ChangeSet) subs(step 4 Location -> SrcLocation) subs(step 3 InventoryCost)
       data Owner Context="PCKAGE" TrgLocation="10669" Quantity QtyEntered TareEntered="0" TrgOrdDetail=<pack PK> Item=<pack item> UseContext="USB" MakeContext="MK"
10 UpdateManufacturingOrdDetailQtyMade data OrdDetail=<pack PK>
11 UpdateManufacturingOrdDetailPackoutStatus data OrdDetail=<pack PK>
12 UpdateManufacturingOrderStatus data Ordr=<bulk>
```

Repeat packout into the existing container (fixture 866271): no CreateInventoryCost/CreateContainer; CreateChangeSet MFP
is step 3; MoveInventory rows carry a literal `TrgLocation="<container Location>"` (attribute placed right after Context)
and only the ChangeSet subs; the make move carries literal
`SrcLocation="<container>" TrgLocation="10669" ... InventoryCost="<existing InventoryCost>"` at the end.
`Item="1"` is the "NA" package pseudo-item; its Quantity matches packages filled (2 for 10 lb into 5 lb cans; 1 for 4 lb).

### 7.8 `BatchExecutionDataSet.MarkOrderComplete` (29,527; 7 failed)

`Ordr UpdateVersion keys Ordr=<bulk>`; `MarkManufacturingOrderComplete Execute data Ordr CurrentLocation="2"`.
Server sets bulk and pack tickets to CMP, closes lines, deletes the vessel location. Fixtures 866324, 866111
(both contain the U+00A0 Memo).

### 7.9 `BatchExecutionDataSet.Unpackaging` (377; 1 failed) — undo one packout

`Ordr UpdateVersion (bulk)`; `ReverseChangeSet Execute data ChangeSet=<the MFP ChangeSet of the Packaging being undone>`;
`UpdateManufacturingOrdDetailQtyUsed` per pack UI line; `...QtyMade` and `...PackoutStatus` for the pack PK line;
`UpdateManufacturingOrderStatus`. Cascades: a new ChangeSet with Context `RVSMFP` and `ReverseChangeSet` pointing at the
original, negative MoveInventory rows, OrdDetail reopened (`IsOpen=true`). Fixtures 865478, 865040.

### 7.10 `BatchExecutionDataSet.ReverseReceipt` (206; 0 failed) — undo the batch make

`Ordr UpdateVersion`; `ReverseReceipt Execute data Receipt=<ChangeSetMFReceipt.Receipt of the BatchExecutionExpress>`;
`UpdateManufacturingOrdDetailQtyUsed` per bulk UI line; optional `CloneFIPT Execute data Ordr` (present when tests had
already been recorded: 865041; absent in 860995); `UpdateManufacturingOrderStatus`. Only possible after every packout
has been unpackaged. Cascades: new ChangeSetMFReceipt row with `ReversedBy`, original updated, negative moves per
line, `OrdDetail.QtyUsed` cleared and QtyCommitted restored; LogResult returns the new Receipt and a cloned IPT line.

---

## 8. End-to-end traced runs (same LoggedInUser + Workstation, consecutive Logs)

Canonical order for one bulk ticket with one pack ticket:

```
[NewIngredient]*  ->  BatchExecutionExpress  ->  InProcessTesting  ->  (tests entered + lot approved in QC programs)
->  ExecutePackagingPhase  ->  ReleaseOrder (Express, pack ticket)  ->  PackoutRequested  ->  CreateChangeSet
->  Packaging  ->  MarkOrderComplete
```

| Run | Logs | Ordr (bulk / pack) | Notes |
|---|---|---|---|
| A | 866313-866324 | 194761 / 194762 | 1 NewIngredient; whole sequence in 2.5 minutes |
| B | 866098-866111 | 194765 / 194766 | 866098 BEE failed (negative stock) -> NewIngredient substitute -> BEE OK |
| C | 866279-866289 | 194055 / 194056 | plain run, no NewIngredient |
| D | 866252-866276 | 194447 / 194448, 194449, 194450 | NewIngredient x4; per pack ticket: [ReleaseOrder] -> PackoutRequested -> [CreateChangeSet first time only] -> Packaging; repeat packouts reuse container |
| E | 865032-865049 | 194072 | BEE -> IPT -> Packout -> Packaging -> Unpackaging -> ReverseReceipt -> reduced BEE (reusing ChangeSet) -> IPT -> Packout -> Packaging -> MarkOrderComplete |

What carries from request to request (all must be read back from LogResult or the tables):

- `Ordr.Version` increments with every request that touches the ticket (A: 3 -> 4 -> ... -> 7).
- NewIngredient -> new UI OrdDetail used as SrcOrdDetail in BEE.
- BEE -> ChangeSet (MF), Receipt (for ReverseReceipt), vessel Location (IPT `Location`/`Vessel`, Packaging SrcLocation).
- Release -> Lot/Sublot (IPT `SubLot`, Packaging `Sublot`), IPT OrdDetail.
- CreateChangeSet (pack) -> ChangeSet literal in first Packaging; Packaging -> container Location, InventoryCost,
  MFP ChangeSet (literal in repeat Packaging and in Unpackaging).

---

## 9. Failures (whole table; all ResultCode 50000)

| Action (Program) | Count | Message | Trigger / remedy seen |
|---|---|---|---|
| ReleaseOrder (Batching Order) | 2,684 (182 recent) | `Insufficient inventory for item(s) 'A,B'` | UpdateOrderQtyCommitted with `Ordr.AllowOvercommit = false` and stock short. Always followed (Log+1, Log+2, same user) by SaveChanges `AllowOvercommit="true"` and the identical ReleaseOrder with Version+1 (864638 -> 864639 -> 864640; 863753 -> 863754 -> 863755) |
| ReleaseOrder (Batching Order) | 5 | `User does not have permission to perform this action.` | Logs 267159-267163 (old) |
| BatchExecutionExpress | 2,575 (304 recent) | `Quantity of the item 'X' is negative` | a MoveInventory from 10669 would take stock below zero. Remedies: NewIngredient with a substitute and resubmit without the short move (866098 -> 866099 -> 866100), or fix stock (receipt/adjustment) and retry |
| Packaging | 292 (44 recent) | `Quantity of the item 'X' is negative` | packaging component (can, drum) short, e.g. 865477 `55GM-SS` |
| Packaging | 3 | `Current stock on hand is negative for Item ... Cannot create new inventory cost` | old |
| MarkOrderComplete | 7 | CHECK constraint `ck_InventoryUsed_Qty` | Logs 158125-158144 (old) |
| NewIngredient | 1 | `Ordr ([Ordr] = '115517') has changed since last read` | stale Version |
| Unpackaging | 1 | `Quantity of the item ... is negative` | packed stock already moved/shipped |
| SaveChanges (Batching Order) | 6 | `Supplied quantity cannot be edited to less than quantity done of product ...` | ValidateSupplyQtyForPackagedProductOrder below packed qty |
| SaveChanges (Batching Order) | 5 + 4 | DELETE / INSERT conflict with `FK_OrdDetailCommit_OrdDetail1` | pack link edits in wrong order (old) |
| SaveChanges (Batching Recipe) | 30 (2 recent) | `Cannot insert duplicate key row in object 'dbo.Recipe' with unique index 'IX_Recipe_RecipeNumber'` | RecipeNumber already used |

Zero failures recorded for: CloseOrder, PublishRecipe, ChangeActiveState, ReleaseOrder (Express), ExecutePackagingPhase,
PackoutRequested, CreateChangeSet, InProcessTesting, ReverseReceipt, RecipeReplacement requests.

---

## 10. Fixture index

All under `C:\Claude Sessions\insights2\tests\fixtures\cms-requests\<folder>\<Log>.xml`. Each file is
`CONVERT(NVARCHAR(MAX), Request)` on one line, no trailing newline, sanitised only as specified
(`pic\<login>` -> `pic\user1` in either case of the prefix, Workstation -> `WS1`, operator full name -> `Person 1`).
Each was verified by length and MD5 against the same expression computed in SQL. Files are UTF-8 without BOM;
three files (865040, 866111, 866324) contain one non-ASCII character, U+00A0, as the first character of `<Memo>`.
Requests with Action NULL are in folder `_NoAction`.

`ManufacturingOrderDataSet.SaveChanges` — Program `Batching Order`

| Log | Scenario |
|---|---|
| 866303 | create bulk ticket from recipe (BA, PK, UB, 6 UI, 2 INSTR) |
| 861076 | create recipe-less (repack) bulk ticket, PK line only |
| 860225 | create repack ticket with PK and repack source UI line, no BillTo |
| 866304 | add one pack ticket linked to bulk (OrdDetailCommit insert) |
| 865832 | add one pack ticket (second example) |
| 865555 | change existing pack qty (OrdDetailCommit update) and add a second pack ticket on a released bulk |
| 850590 | header edit: BillTo |
| 862211 | header edit: AllowOvercommit=true (fix for failed release) |
| 839011 | header edit on released ticket: dates, with CreateLotForPackagingProductOrders |
| 863400 | rescale batch quantity |
| 861077 | repack edit: set RepackagingYield and add source line |
| 857625 | remove pack ticket (unreleased bulk) |
| 863850 | remove pack ticket (released bulk) |

`ManufacturingOrderDataSet.SaveChanges` — Program `Batching Recipe`

| Log | Scenario |
|---|---|
| 863613 | new recipe, PK + 1 ingredient |
| 861269 | new recipe, PK + 4 ingredients |
| 861270 | add BA phase and instructions, re-parent ingredients |
| 865829 | edit one line quantity |
| 846224 | edit quantity with recomputed totals |
| 844641 | edit recipe Comment |

`ManufacturingOrderDataSet.ReleaseOrder`

| Log | Program | Scenario |
|---|---|---|
| 866305 | Batching Order | release bulk with IPT + 2 tests |
| 865823 | Batching Order | release without IPT, lot Approved |
| 861079 | Batching Order | first release of repack ticket (builds BA/UB/IPT/UGR) |
| 866320 | Batch Execution Express | auto-release pack ticket (lot exists) |
| 865557 | Batch Execution Express | auto-release pack ticket added after bulk release (CreateLot) |

`ManufacturingOrderDataSet.CloseOrder` (Batching Order): 854877 close completed ticket; 863585 close never-run ticket.

`ManufacturingOrderDataSet.PublishRecipe`: 861271, 863615 (Batching Recipe, 4 steps); 866297 (Batching Recipe, with BA
TotalWeight update); 862575 (Program NULL, from Recipe Replacement).

`ManufacturingOrderDataSet.ChangeActiveState`: 841135 deactivate (`Inactive="true"`), 844803 reactivate (`Inactive="false"`), both Batching Recipe;
839561 deactivate from `Batching Recipe (Readonly)`.

`RecipeReplacementDataSet.SaveChanges` (Recipe Replacement): 862569 (4 recipes), 862553 (larger example).

`None` (Action NULL): 862570 and 862583 (Program `Recipe Replacement`: clone recipe); 862574 (Program
`RecipeReplacement`: Status=1), 862579 (Status=3), 862580 (close replacement).

`BatchExecutionDataSet.*` (all Program `Batch Execution Express`)

| Folder | Log | Scenario |
|---|---|---|
| NewIngredient | 866313 | add ingredient, one signed event |
| NewIngredient | 866099 | add substitute ingredient, two events |
| BatchExecutionExpress | 866314 | full make, 5 moves (Run A) |
| BatchExecutionExpress | 866100 | make with one line not consumed (Run B) |
| BatchExecutionExpress | 865042 | re-make after ReverseReceipt, literal ChangeSet/TrgLocation |
| InProcessTesting | 866315, 866101 | sample request |
| ExecutePackagingPhase | 866319, 866106 | start packaging phase |
| PackoutRequested | 866321 | no event |
| PackoutRequested | 866270 | with ReservationIsIncomplete event |
| CreateChangeSet | 866322, 866109 | MF change set for pack ticket |
| Packaging | 866323, 866110 | first packout, new container |
| Packaging | 866271 | repeat packout into existing container |
| MarkOrderComplete | 866324, 866111 | complete ticket |
| Unpackaging | 865478 | undo packout, three events |
| Unpackaging | 865040 | undo packout, one pack line |
| ReverseReceipt | 865041 | with CloneFIPT |
| ReverseReceipt | 860995 | without CloneFIPT |

Not saved (over ~15,000 chars): 862699 (RecipeReplacementDataSet.SaveChanges for 153 recipes, 47,570 chars) and
large-recipe ticket creates / BatchExecutionExpress requests (20+ ingredients); they repeat the per-line blocks above.

---

## 11. Open questions

1. `Ordr.ChangeNumber` on bulk create: which counter the client reads (values are monotonically increasing across
   tickets and appear shared with other documents). Not found in the IDGenerator kinds examined.
2. `UnitCostMK` in CreateInventoryCost is a client estimate (sent 5.97078 / 6.2019; `InventoryCost` later stored
   5.90635 / 6.13747). Formula unknown; does the server recompute regardless of what is sent?
3. `TotalVolume` (and BA vs PK rounding differences) — density source and exact arithmetic not reproduced.
4. When the form defaults `AllowOvercommit` to true vs false on a new bulk ticket (both seen).
5. `BillTo` on a batch ticket: optional; which customers are offered and why.
6. Recipe Replacement inserts send `keys Version="2"` on Insert (normal inserts send `""`); effect unknown.
7. `ManfDate` in CreateLot for Approved/repack lots (865823 sent a datetime 3 days after the release date; 865557 sent
   the bulk PlanStartDate) — rule not confirmed.
8. Constants assumed from uniform data, not from configuration: `Manufacturer="9"`, `Location`/`CurrentLocation="2"`,
   `SrcLocation="10669"`, zones 1/3/4, `Length="3"`.
9. `MoveInventory Item="1"` Quantity = packages filled is inferred from two examples only.
10. Which warning `<event>`s the server requires (if any) versus merely logs; whether omitting them changes behaviour.
11. `CloneFIPT` semantics and exactly when the client includes it in ReverseReceipt.
12. The QC steps between InProcessTesting and ExecutePackagingPhase (`TestsDataSet.SaveChanges`,
    `ReleaseDataSet.Approve`) were observed but not analysed here.
