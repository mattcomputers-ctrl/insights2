# PARITY.md — every CMS client action, and where it stands in Insights

Source: the CMS request log, `Log > 766353` (the most recent ~100,000 requests,
up to 2026-11-09). Counts are requests in that window / of which failed.
Sequences were traced from at least three real successful requests each;
the step-by-step shapes, every variant and the failure texts are in
`docs/research/*.md`, and the sanitised requests themselves are in
`tests/fixtures/cms-requests/<Action>/<log>.xml`.

**Status** values:

- **live-ready** — template, flow, screen, dry-run preview and golden tests all
  done; needs only the sandbox check in `docs/SANDBOX_CHECKLIST.md` before live use.
- **researched** — sequence traced, fixtures stored, not built yet.
- **read-only** — Insights shows the data; no write.
- **not planned** — out of scope (CMS admin) or not a write.

A "request" below means one call to `ProcessXMLRequest`. Unless a row says
otherwise, one user action is exactly one request and a failure rolls back
everything in it.

## 1. Quality control (Insights module: QC Results)

| CMS action (program) | Count / failed | What the user is doing | Request sequence | Insights today | Status |
|---|---|---|---|---|---|
| `TestsDataSet.SaveChanges` (Enter Test Results) | 4,077 / 0 | Entering test values and pass/fail for a batch sample | 1 request: `UpdateVersion SampleSet` → `CheckVersion Location` (the sample container) → one `Update LocationSampleTest` per changed test (`Result` omitted for pass/fail-only tests, `Passed`, `TestedBy`, `TestedTime`) | `/qc` result browser, `/qc/coa`; **new** `/qc/queue`, `/qc/sample/{id}` | **live-ready** (`qc.save_results`) |
| `ReleaseDataSet.Approve` (Disposition Sublot) | 4,058 / 1 | Approving the tested sublot so the batch can be packed | 1 request, 9 steps: `UpdateVersion SubLot`, `Lot`, `SampleSet` → `Insert Release` (Status Approved) → `Update SampleSet BeingTested=false` → `Execute ReleaseSublot` → `UpdateVersion Ordr` → `Execute` complete the ticket's in-process-test line → notify | same screens | **live-ready** (`qc.approve_sublot`) |
| `ReleaseDataSet.Reject` (Disposition Sublot) | 9 / 0 | Rejecting the sublot | 1 request: the first 6 steps of Approve with Status Rejected | same screens | **live-ready** (`qc.reject_sublot`) |
| `TestUpdateDataSet.SaveChanges` (Test Update) | 1 / 0 | Editing a test definition | 1 request | Specs shown on item page | not planned (rare master data) |

Failure seen: Approve once failed on a stale `Version` ("has changed since last
read"). Insights re-reads versions at confirm time and refuses a stale preview.

## 2. Sales orders (Sales Orders, Order Readiness)

| CMS action (program) | Count / failed | What the user is doing | Request sequence | Insights today | Status |
|---|---|---|---|---|---|
| `ShippingOrderDataSet.SaveChanges` (Shipping Order) — create | 5,462 / 8 (all shapes) | Entering a new order | 1 request: `Insert Ordr` → per line `Insert OrdDetail` + `Insert OrdDetailPricing` → `Insert MemoRef` (if notes) → `Insert AddressRef ShipToAddress` → `Execute UpdateOrderTaxes` → `Execute CheckOrderCredit`. Usually followed seconds later by a second request releasing it (Status `RTS`, `CreditHold=false`, `CheckOrderCredit Release=true`) | `/sales-orders` list and detail, Order Readiness coverage; **new** `/sales-orders/new` (customer search, order or quote, price list or typed price, memos, duplicate-PO check). Not sent by Insights: the follow-up release request (see `sales.release_order`) | **live-ready** (`sales.create_order`) |
| same — edit | (in the above) | Changing header, lines, notes, address | 1 request: `Update` or `UpdateVersion Ordr` → line updates / deletes / inserts → taxes → credit check | **new** `/sales-orders/{order}/edit` (PO, required date, carrier, line quantity/price, add and remove lines, memos) and buttons on the order page: release from credit hold, complete order. Still in the client only: reopen a completed order, change the ship-to address on an order, copy an order, turn a quote into an order | **live-ready** (`sales.edit_order`, `sales.release_order`, `sales.complete_order`) |
| `ShippingOrderDataSet.CompleteSale` | 12 / 1 | Completing a counter sale without a waybill | 2 requests: `SaveChanges`, then `UpdateVersion Ordr` → `Execute CreateShipmentsByOrder` | "Complete sale" button on the order page (open orders with at least one open line); sends the second request only, since Insights has no unsaved edits at that point | **live-ready** (`sales.complete_sale`) |
| `AssembleDataSet.SaveChanges` (Shipping Assembly) | 2,886 / 2 | Picking stock into a carton/pallet for an order, or taking it back out | 1 request. New container: `UpdateVersion Ordr` → `Execute CreateAssembly` → `Execute MoveInventory` per picked line (the new container's id is filled in from the create step). Existing container: `UpdateVersion Ordr` → `UpdateVersion Location` → `Execute MoveInventory` per line. Take out: the same with a `CheckVersion` of the stock location before each move and a negative `Quantity` | Shipping shows assemblies and contents; **new** `/shipping/assemble`: orders ready to ship with open lines, per line ordered / shipped / in containers / left / in stock, pick into a new or an existing container, take stock back out | **live-ready** (`ship.assemble`, `ship.unassemble`) |
| `InvoiceDataSet.SaveChanges` (Invoice) | 444 / 0 | Correcting an issued invoice | 1 request: reverse the invoice and re-issue it | Invoice numbers on order and shipping pages | researched |

Failures seen: order locked by another user ("is currently locked by …"), stale
version, credit hold refusals. Client-rendered output to rebuild as print pages:
order acknowledgement.

## 3. Purchasing (Purchase Orders, Need to Order)

| CMS action (program) | Count / failed | What the user is doing | Request sequence | Insights today | Status |
|---|---|---|---|---|---|
| `PurchaseOrderDataSet.SaveChanges` (Purchase Order) — create | 745 / 0 (all shapes) | Raising a PO | 1 request: `Insert Ordr` → per line `Insert OrdDetail` + `Insert OrdDetailPricing` → `Insert AddressRef SupplierAddress` → `Insert MemoRef` (optional) → `Insert AddressRef ShipToAddress` | `/purchase-orders`, Need to Order buy list; **new** `/purchase-orders/new` (supplier search, lines by our code or the supplier's, price list or typed price, promised dates, delivery terms, carrier, memo) and "Start a PO" on each vendor group of Need to Order | **live-ready** (`po.create_order`) |
| same — edit / complete / reopen | (in the above) | Changing lines; closing or reopening the PO | 1 request: `Update`/`UpdateVersion Ordr` → updates, then deletes, then inserts. Complete = `Update Ordr Status=CMP` + `Update OrdDetail IsOpen=false` per open line | **new** `/purchase-orders/{po}/edit` (line quantity and promised date, add and remove lines, delivery terms, carrier, memo) and "Complete PO" on the PO page. Still in the client only: price change on an existing line, reopen a completed PO, the no-bill flag | **live-ready** (`po.edit_order`, `po.complete_order`) |
| `PurchaseReceiptDataSet.SaveChanges` (Purchase Receipt) — receive | 892 / 0 | Receiving a delivery | **1 request per PO line**: `UpdateVersion Ordr` → `Execute CreateChangeSet` → `Insert ChangeSetReceipt` → `Execute CreateInventoryCost` (costed items) → `Execute MoveInventory` → `Execute UpdateOrdDetailQtyUsed` → `Execute CreateUpdateBillDetail` → notify. A three-line delivery is three saves with `Ordr.Version` rising each time | Receipts per PO line; **new** `/purchase-orders/{po}/receive` (one line per receipt, quantity in the stock unit, packing slip number). US-dollar POs only; per-package or other-unit pricing stays in the client | **live-ready** (`po.receive`) |
| same — correct a receipt | (33 all-time) | Fixing a received quantity | 1 request: `UpdateVersion Ordr`, `UpdateVersion ChangeSet`, `Update ChangeSetReceipt`, `MoveInventory` (difference), cost and bill updates | same | researched (not built: reverse the receipt and receive again) |
| `PurchaseReceiptDataSet.ReverseReceipt` | 3 / 0 | Undoing a receipt | 1 request: receipt skeleton with zero quantity, negative movement, line reopened | "Reverse" beside each receipt on `/purchase-orders/{po}/receive` (single-line receipts not yet on a supplier bill) | **live-ready** (`po.reverse_receipt`) |
| `ReturnToSupplierDataSet.SaveChanges` / `.ReverseReturn` | 3 / 0, 1 / 0 | Returning stock to a supplier; undoing that | 1 request each | — | researched |
| `BillUpdateDataSet.SaveChanges` (Bill Entry) | 490 / 1 | Entering, editing, deleting a supplier bill | 1 request: `Insert Bill` → `Update BillDetail` per receipt line → `Execute UpdateBillCost` | — | researched |

Failures seen: stale `Ordr` version on receipts, "ChangeSet N already billed" on
reversal, duplicate supplier + invoice number on bills.

## 4. Batch tickets and recipes (Batch Tickets, Scheduling, Recipes)

| CMS action (program) | Count / failed | What the user is doing | Request sequence | Insights today | Status |
|---|---|---|---|---|---|
| `ManufacturingOrderDataSet.SaveChanges` (Batching Order) | 9,059 / 0 | Cutting a bulk ticket, adding pack tickets, editing | 1 request each. Create: `Insert Ordr` + `OrdDetail` rows copied from the recipe and scaled, six-step trailer. Pack ticket: second request, linked by `OrdDetailCommit` | `/batch-tickets` with batch sheet, yield, QC; Scheduling board; **new** `/batch-tickets/new` (bulk item, pounds, dates, overcommit, customer, packaging lines in units or pounds of bulk, optional release) and, on the ticket page, change dates / overcommit / customer and add a packaging ticket before release. Still in the client: hand-built and repack tickets, rescaling, changing or removing a packaging ticket | **live-ready** (`mf.create_ticket`, `mf.add_pack_ticket`, `mf.edit_ticket`) |
| `ManufacturingOrderDataSet.ReleaseOrder` (Batching Order) | 4,636 / 182 | Releasing the ticket to the floor | 1 request: `Update Ordr Status=RLS`, in-process-test line and its tests built from the item's tests, `UpdateOrderQtyCommitted`, `CreateLot` | "Release to the floor" on the ticket page, or as the last step of a new ticket. Stock shortfalls are left for CMS to refuse | **live-ready** (`mf.release_ticket`) |
| `ManufacturingOrderDataSet.ReleaseOrder` (Batch Execution Express) | 4,259 / 0 | Automatic release of the pack ticket before first packout | 1 request | same | researched |
| `ManufacturingOrderDataSet.CloseOrder` | 15 / 0 | Closing a ticket without completing | 1 request: `Update Ordr Status=CLS`, close linked pack tickets, export | "Close this ticket" on a completed ticket, "Cancel this ticket" on one never released. A ticket on the floor is closed in the client | **live-ready** (`mf.close_ticket`) |
| `ManufacturingOrderDataSet.SaveChanges` (Batching Recipe) | 1,383 / 2 | Creating or editing a recipe draft | 1 request per save | `/recipes` (read only; recipes are drafted in the client) | researched |
| `ManufacturingOrderDataSet.PublishRecipe` | 1,304 + 623 / 0 | Publishing a recipe version | 1 request; the server retires the old version | "Publish this recipe" on the recipe sheet, for a drafted bulk recipe with one batch and one product | **live-ready** (`mf.publish_recipe`) |
| `ManufacturingOrderDataSet.ChangeActiveState` | 13 / 0 | Activating / deactivating a recipe | 1 request: `Update Recipe Inactive` | "Retire this recipe" / "Make this recipe active again" on the recipe sheet | **live-ready** (`mf.recipe_active`) |
| `RecipeReplacementDataSet.SaveChanges` + un-named follow-ups (Recipe Replacement) | 19 + 680 / 0 | Swapping an ingredient across many recipes | Multi-request: save the job → N clone requests → status 1 → N publishes → status 3 → closed | — | researched |

Failure seen: release refuses with "Insufficient inventory for item(s)" when
over-commit is off; operators fix it by saving `AllowOvercommit=true` and
releasing again. Client-rendered output to rebuild: the printed batch ticket.

## 5. Batch execution (Batch Tickets)

All program Batch Execution Express, one request per button press. The usual
order for one batch is the order of the rows.

| CMS action | Count / failed | What the user is doing | Request sequence | Status |
|---|---|---|---|---|
| `BatchExecutionDataSet.NewIngredient` | 2,437 / 0 | Adding an unplanned ingredient or substitute | 1 request | **live-ready** (`mfx.add_ingredient`) |
| `…BatchExecutionExpress` | 4,642 / 304 | "Make the batch": consume ingredients, receive bulk into a vessel | 1 request (large) | **live-ready** (`mfx.make_batch`) |
| `…InProcessTesting` | 4,065 / 0 | Sending the batch to QC (creates the sample set) | 1 request | **live-ready** (`mfx.send_to_qc`) |
| `…ExecutePackagingPhase` | 4,314 / 0 | Starting packaging | 1 request | **live-ready** (stage of `mfx.packout`, sent when the bulk product line is not started) |
| `…PackoutRequested` | 5,953 / 0 | Asking for a packout | 1 request | **live-ready** (stage of `mfx.packout`) |
| `…CreateChangeSet` | 4,249 / 0 | First packout of a pack ticket | 1 request | **live-ready** (stage of `mfx.packout`, first packout only) |
| `…Packaging` | 5,949 / 44 | Recording packed containers | 1 request | **live-ready** (`mfx.packout`; an unreleased packaging ticket is released first) |
| `…MarkOrderComplete` | 4,462 / 0 | Completing the ticket | 1 request | **live-ready** (`mfx.complete`) |
| `…Unpackaging` / `…ReverseReceipt` | 44 / 0, 22 / 0 | Undoing a packout; undoing the make | 1 request each | **live-ready** (`mfx.undo_packout`, `mfx.undo_make`) |

Failures seen: "Quantity of the item 'X' is negative" (stock at the source
location would go below zero). Insights shows batch sheet, lots, yield and QC
for every ticket, and an **On the floor** card on `/batch-tickets/{ticket}`
for a released bulk ticket: make the batch (quantities actually used per
ingredient), add an ingredient, send for testing, pack out through a packaging
ticket (quantity made and packages filled), complete, undo a packout, undo the
make. Each button only shows when the ticket's state in CMS allows it. Still
client-only: batches packed directly without a packaging ticket, choosing the
lot of each ingredient, signed warnings, and container labels.

## 6. Shipping (Shipping)

| CMS action (program) | Count / failed | What the user is doing | Request sequence | Insights today | Status |
|---|---|---|---|---|---|
| `WaybillDataSet.SaveChanges` (Waybill) | 9,435 / 15 | Shipping one or more assembled orders | **3 + N requests** for N orders: (1) `Insert Waybill`, tracking numbers, address, document, and a to-do row in `WaybillInProcess` per later request; (2) per order: tick its to-do row, `UpdateVersion Waybill`, `Execute CreateShipmentsByOrder`; (3) tick, save shipped pallets; (4) tick, `Update Waybill Status=CMP`. To-do row ids are read back after request 1. An interrupted waybill is finished from the to-do rows (`ProcessOutstandingRequests`) | `/shipping` waybills, assemblies, tracking; **new** `/shipping/new` (ready orders, containers, weights, freight charge), Finish button on an unfinished waybill | **live-ready** (`ship.create_waybill`, `ship.resume_waybill`) |
| `WaybillDataSet.RejectWaybill` | 31 / 0 | Cancelling a waybill | 1 request: `Update Waybill Status=REJ` → `Execute RejectWaybill` | Reject button on a completed waybill | **live-ready** (`ship.reject_waybill`) |
| `PrintShippingLabel.UpdateShippingLabelAddress` | 2,457 / 0 | Saving the address printed on a shipping label | 1 request per container: one `Insert AddressReference` (or `Update` on a reprint; the client's envelope carries no user, workstation or owner, Insights sends its normal one) | "Mark labels printed" on a completed waybill | **live-ready** (`ship.label_address`) |
| `LocationTools.ResetLabelRequired` (PrintContainerLabel) | 4,060 / 0 | Marking a container label as printed | 1 request: `Execute ResetLabelReqd` | **new** `/inventory/labels`: containers with stock that still ask for a label, "Mark labelled" on each. Insights does not print the label | **live-ready** (`inv.reset_label`) |

Print pages (read-only, linked per order from the waybill page): packing slip
`/shipping/packing-slip/{change set}` (header from the `PackingSlipReport`
view; its detail views are empty here, so lines come from `ShipmentDetails`
with the container of each line) and invoice `/shipping/invoice/{trans}`
(`InvoiceReport`, `InvoiceDetailReport`, `InvoiceTaxReport`; needs the
`shipping.invoice` permission because it shows prices). Still client-only:
the shipping label itself, choosing the lot that is picked, picking in a
unit other than the stock unit, and tracking numbers and freight cost per
container.

## 7. Inventory (Inventory)

| CMS action (program) | Count / failed | What the user is doing | Request sequence | Insights today | Status |
|---|---|---|---|---|---|
| `InventoryCountDataSet.SaveChanges` | 156 / 0 | Saving a count sheet | 1 request. New: `Insert InventoryCount` → `Insert InventoryCountDetail` ×N. Change: `UpdateVersion InventoryCount` (or `Update` when the description or date changes) → `Update InventoryCountDetail` per changed line. Delete: `Delete InventoryCountDetail` ×N → `Delete InventoryCount` | **new** `/inventory/counts`: list, new count (item codes and quantities), change quantities, description and date, delete. Adding or removing items on a saved count stays in the client | **live-ready** (`inv.count_create`, `inv.count_edit`, `inv.count_delete`) |
| `InventoryCountDataSet.Post` | 151 / 1 | Posting the count | 1 request, about a second after the save: `UpdateVersion InventoryCount` → `Update InventoryCountDetail` ×N (the adjustment: counted minus on hand) → `Execute CreateChangeSet` (COUNT) → `Execute MoveInventory` ×N → `Update InventoryCount Posted=true` | "Check and post" on a saved count, or "Check, save and post" on a new one (two requests in a row). The review lists what each line adds or removes | **live-ready** (`inv.count_post`, `inv.count_create_post`) |
| `InventoryTools.RecalculatePlanTrace` | 288 / 0 | Recalculating planned dates | 1 request: `Execute CalculateESD` | "Recalculate planned dates" on `/inventory/counts` | **live-ready** (`inv.recalc_plan`) |

Failure seen: posting a count that would take an item negative.

## 8. Master data (Items, Recipes)

| CMS action (program) | Count / failed | What the user is doing | Request sequence | Insights today | Status |
|---|---|---|---|---|---|
| `ItemUpdateDataSet.SaveChanges` (Item Update) | 1,338 / 7 | Creating or editing an item | 1 request: `Item` step, child rows, then `Execute InheritItemsPropertiesByPackagedProducts` and `Execute FixupItemManf` | Item 360 page | researched |
| `ItemNameUpdateDataSet.SaveChanges` (Item Name Update) | 1,100 / 4 | Creating or editing an alias | 1 request: `Insert`/`Update Item` (alias row pointing at the real item) → `Execute FixupItemManf`. An update carries only the changed columns, in the order `ItemCode`, `Description`, `ReplacedBy` | Aliases on the item page; **new** on `/items/view`: add an alias, change its code or description, move it to another item | **live-ready** (`item.alias_create`, `item.alias_edit`); deleting an alias stays in the client |
| `EntityUpdateDataSet.SaveChanges` (Bill To / Ship To / Supplier / Sales Rep Update) | 252 / 1 | Creating or editing a customer, ship-to or supplier | 1 request: `Insert Entity` → document rows (customers) → `Insert AddressRef` | Names on orders | researched |
| `PriceVersionUpdateDataSet.SaveChanges` | 180 / 0 | New price list version | 1 request: `Insert PriceVersion` → `PriceDetail` rows | Prices on orders | researched |

Failures seen: duplicate item code, duplicate entity code.

## 9. Not planned

| CMS action | Count | Why |
|---|---|---|
| `Shell.Login`, `Shell.Logout`, `Login.LicenseUnavailable`, `Shell.SaveGridLayout` | 1,526 / 1,412 / 86 / 1 | Client session bookkeeping, not business writes |
| `*SetViewerDataSet.ExportToExcel`, `*.ExportToExcel` | ~300 | Log entries for exports; Insights has its own exports |
| `UserUpdateDataSet`, `RoleUpdateDataSet`, `TaxRuleUpdateDataSet`, `CountryUpdateDataSet` | 1 / 3 / 3 / 1 | CMS administration, out of scope |

## Parity-plus (one confirmed click from a screen that already knows the answer)

| Insights screen | Action to add | Depends on | Status |
|---|---|---|---|
| QC queue | Enter results, approve, reject | QC flow | **live-ready** |
| Need to Order | Create PO for a vendor group | PO create | **live-ready** (grouped view: "Start a PO" opens the new-PO form filled in with the group's items in the supplier's ordering units; the user checks it, then confirms) |
| Scheduling board | Create and release ticket for a run | Ticket create + release | **live-ready** ("Cut ticket…" on each suggested run opens the new-ticket form filled in with the bulk item, pounds, the day as planned start and required date, the run's packaging split in pounds, and release ticked; the user checks it, then confirms) |
| Order Readiness | Release / complete an order that is fully covered | Sales order edit | flow built (buttons are on the sales order page; not yet on Order Readiness) |
