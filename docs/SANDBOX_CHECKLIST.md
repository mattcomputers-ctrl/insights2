# Sandbox checklist — before any Insights write goes to the real CMS

Everything in Insights 2 has been built and tested without ever writing to CMS.
This is the list to work through, on a restored copy, before switching live
sending on anywhere that matters.

## 1. What Matt sets up

1. **A restored copy of the CMS database** on a SQL Server that is not production
   (same build as production). Nothing below is to be run against production.
2. **A write login** for Insights on that copy, separate from the read login:
   - `EXECUTE` on `dbo.ProcessXMLRequest`
   - `EXECUTE` on `dbo.GenerateID` (new sales orders take their change number from
     this CMS counter, as the client does)
   - `SELECT` on `dbo.Log`, `dbo.LogRequest`, `dbo.LogResult`, `dbo.LogCompleted`
   - nothing else (no table writes; the procedure does those itself).
3. **An Insights test instance** pointed at the copy for reads too
   (`config/config.local.php`, the usual `cms` block), so previews are built from
   the same data the writes will hit.

## 2. Insights configuration

| Where | Key | Value |
|---|---|---|
| Admin → CMS → Writes, connection form (writes the untracked `config/cms.write.local.php`) | host, port, name, user, password, driver | the write login on the sandbox copy |
| same page, "Test connection" | | must succeed against the copy, not production: check the server and database it names |
| Admin → CMS → Writes, settings | `cms_write.live_enabled` | off until section 3.1 is done, then on |
| | `cms_write.workstation` | `INSIGHTS` (default) |
| | `cms_write.program_mode` | `insights` (default); try `cms` if step 3.2 fails |
| Admin → Users → (user) | CMS user | the person's CMS login name exactly as CMS stores it, e.g. `pic\name` |
| Admin → Roles | `Write to CMS: …` permissions | grant only to the tester for now |

The file `config/cms.write.local.php` is git-ignored. The password is never
stored in MariaDB.

## 3. Foundation checks (once)

1. **Dry run still dry.** With live off, save a QC result. Confirm: the audit row
   says `dry-run`, and `SELECT MAX([Log]) FROM Log` on the copy has not moved.
2. **First live request.** Turn live on. Save one QC result (section 4.1). Confirm
   the audit row shows a CMS log id, and in the copy
   `SELECT Program, Action, [User], Workstation, ResultCode FROM Log WHERE [Log] = <id>`
   shows ResultCode 0, the mapped CMS user and workstation `INSIGHTS`, and a
   `LogCompleted` row exists.
   - If it fails with a message about the program, application or session, set
     `program_mode` to `cms` and repeat. Record the outcome against decisions 59
     and 66 in `docs/DECISIONS.md`.
3. **Stale preview is refused.** Open a sample set in Insights and get to the
   preview, change the same sample in the CMS client and save there, then confirm
   in Insights. Expected: Insights refuses and sends nothing.
4. **CMS failure is reported.** Any failed request must show CMS's message in
   plain words, and the audit row must hold the failed log id and message.
   Check that the log id on the audit row is the row CMS wrote for this
   request (same user, action and time). If Insights says the outcome is
   unknown instead, note whether the call returned no log id: that decides how
   often the fallback lookup is used.
5. **Kill switch.** Turn the CMS kill switch on; confirm a write is refused.
6. **Unmapped user.** A user with no CMS user set cannot write (clear message).
7. **Audit comes first.** Apply migration 022 before testing. Do one dry run
   and one live write and read their `cms_write_audit` rows column by column:
   `cms_user` is the CMS user, `input_json` holds what was entered,
   `request_xml` is the request, `status` is `dry-run` or `committed` (not
   `sending`), `log_id` is the CMS log id on the live row, and `finished_at`
   is filled. While a live request is in flight the row already exists with
   status `sending`. Then make the audit table unwritable for a moment (for
   example rename the `input_json` column on the test database) and confirm a
   write: nothing is sent, `SELECT MAX([Log]) FROM Log` has not moved, and
   Insights says the step was not sent.
8. **One confirmation, one run.** Confirm a write, then go back in the browser
   and press Confirm again (or double-click Confirm). The second press says
   the confirmation page has expired or was already used, and the CMS log
   shows the request once. After a stale-preview warning or a "CMS
   unavailable, nothing was sent" message the same page can still be
   confirmed.

## 4. Per-flow live verification

For each flow: do the action in Insights on the copy, then do the same action in
the CMS client on a comparable record, and compare the two `LogRequest` documents
and the resulting rows. They should differ only in ids, times, user, workstation
and program.

### 4.1 QC results, approve, reject (built)

1. Find a batch waiting on QC at `/qc/queue` (if the queue is empty, send a batch
   to QC from the CMS client first).
2. Enter results for two tests, one numeric and one pass/fail-only; save; confirm.
   - Check the `LocationSampleTest` rows: `Result`, `Passed`, `TestedBy`, `TestedTime`.
   - Change one of the two results (value and pass/fail) and save again. Check
     the request holds only `Result` and/or `Passed` for that row, and that
     the row's `TestedBy` and `TestedTime` are still those of the first save.
     Also flip a Pass to Fail on a saved row and check the row shows Fail.
   - Open the same sample in the CMS client: the values show and can be edited.
3. Approve the sublot; confirm.
   - Check: a `Release` row with status Approved; `SampleSet.BeingTested = 0`;
     the sublot is released; the ticket's in-process-test line is complete;
     the CMS client lets the batch move on to packaging.
4. On another batch, reject. Check the `Release` row says Rejected and the batch
   cannot be packed.
5. Compare each Insights request with a client-made one of the same action.

### 4.2 Sales orders (built)

1. **New order.** `/sales-orders` → New order. Pick a customer with a price list,
   enter a PO number, a required date, one line with the price left blank and one
   with a typed price; check; confirm.
   - Check the new `Ordr` row against a client-made order for the same customer:
     `BillTo`, `ShipTo`, `Terms`, `Currency`, `Salesman`, tax columns, `ShipVia`
     and freight columns, `DateRequired` (date chosen + time of entry), `Status`,
     `CreditHold`, `ReserveAmount`, `ChangeNumber`.
   - Check `OrdDetail` + `OrdDetailPricing` per line (`Price`, `ManualPrice`, the
     price-list reference on the blank-price line), the `AddressRef ShipToAddress`
     copy, the `MemoRef` rows, and the tax rows `UpdateOrderTaxes` created.
   - `ChangeNumber`: confirm `GenerateID 'ChangeNumber'` returned the next number
     and that the bounds Insights passes (12750 to 2147483647) are right.
   - Note what status the order lands in. The client usually sends a release
     request seconds later; Insights does not. If the order sits on credit hold,
     use "Release from credit hold" and confirm that is the intended workflow.
   - Open the order in the CMS client: it loads, edits and saves normally.
2. **Duplicate PO.** Repeat with the same PO number: refused until "Duplicate PO
   number is fine" is ticked; then check the request carries the event and saves.
3. **Quote.** Tick "This is a quote": status `QUO`, nothing committed, no credit check.
4. **Change order.** On an open order change the quantity of one line, the price
   of another, add a line, remove a line, change the carrier, the required date
   and a memo, each as its own save; compare each request with a client-made one.
   - Required-date change: the client sometimes also issues a new change number;
     Insights never does. Check nothing downstream (shipping, invoicing) minds.
   - Try to change the quantity of a part-shipped line: refused by Insights.
   - Edit the same order in the client while the Insights confirm page is open,
     then confirm: Insights should refuse because the order changed since the preview.
5. **Release from credit hold** on an order in `CSH`: status `RTS`, `CreditHold` 0.
6. **Edit an order on credit hold** (`CSH`): change a quantity and remove a
   line in one save. The request has the deletes before the update and no
   `CheckOrderCredit` step; the order stays `CSH` until Release from credit
   hold. Note `Ordr.ReserveAmount` before and after the release, and what
   status a held order really carries (`CSH`, or `RTS` with `CreditHold` 1).
7. **Complete an order on credit hold**: the request has no `CheckOrderCredit`
   step; the order becomes `CMP` and `CreditHold` stays 1.
8. **Complete order** on an order with an unshipped line: lines closed,
   `QtyCommitted` cleared, status `CMP`, `DateCompleted` set, reserve released.
9. **Complete sale** on an open order for a sundry or fee item: a shipment and an
   invoice are created and the order completes. Compare with a client-made one.
   Then try an order for a stocked item with no stock: expect the CMS refusal to
   come back in plain words.

### 4.3 Purchasing (built)

Grant `write.po.*` to the test user. Pick a supplier with a current price list.

1. **New PO** (`/purchase-orders/new`): one packaged item (price list sells by
   the drum or pail), one unpackaged item, one item not on the price list with
   a typed price. Leave delivery terms, carrier and memo at the supplier's defaults.
   - Compare the request on the confirm page with a client-made PO for the same
     supplier: `Ordr` header, per line `OrdDetail` (`QtyReqd`, `SupQty`,
     `SupUnit`, percent under and over, `ManualPrice`, `Price`) and
     `OrdDetailPricing` (price-list reference, quantity breaks), both address
     copies and the memo.
   - Packaged line: `QtyReqd` is packages times the package size; check the PO
     prints the package count the way the client's does.
   - Memo: check how the supplier's standing memo arrives on the PO and that a
     typed memo replaces it.
   - Open the PO in the CMS client: it loads, edits, prints and saves normally.
2. **Quantity breaks.** Order a tiered item at a quantity in the second break:
   the price is the second break's. Order below the first minimum: Insights
   warns; note what the client does with the same quantity.
3. **From Need to Order.** Group by vendor, "Start a PO" on a group: the form
   opens for that supplier with package counts rounded up. Check two or three
   against the price list by hand.
4. **Change PO.** On an open PO change a quantity, a promised date, add a line,
   remove an unreceived line, change the carrier and the memo, each as its own
   save; compare each request with a client-made one. Edit the PO in the client
   while the Insights confirm page is open, then confirm: Insights should refuse.
   Also blank the promised date of a line that has one and check the save is
   refused with a message to clear it in CMS, and nothing is sent.
5. **Receive** (`/purchase-orders/{po}/receive`): receive part of a line, then
   the rest, then a line of a non-costed item.
   - Check `ChangeSet`, `ChangeSetReceipt`, the inventory movement into the
     receiving location, the lot and sublot created, `OrdDetail.QtyUsed`, the
     inventory cost entry and the bill detail row against a client-made receipt.
   - Does the line close and the PO complete by themselves when everything is in?
     Insights sends nothing for that and expects CMS to do it.
   - Print the receiving labels from the client for the Insights-made receipt.
   - Receive more than the over-receipt allowance: Insights warns; see what CMS says.
6. **Reverse receipt**: reverse one of the receipts above. Stock leaves the
   receiving location, the line reopens, the cost and bill rows are undone.
   Then move a received lot elsewhere and try to reverse it: expect the CMS
   refusal in plain words. Reverse a receipt on a completed PO and note whether
   the PO reopens.
7. **Complete PO** with an unreceived line: lines closed, status `CMP`.

### 4.4 Batch tickets and recipes (built)

Grant `write.mf.*` to the test user. Pick a bulk item with a published recipe
(pounds, no special lines) that has specification tests, and one of its
packaged items.

1. **New ticket, not released** (`/batch-tickets/new`): bulk item, a size the
   recipe scales to cleanly, required date a few days out, no packaging lines.
   - Compare the request with a client-cut ticket of the same recipe and size:
     the `Ordr` header, the batch line (`TotalWeight`, `TotalVolume`), the
     product line, every ingredient (`QtyEntered`, `QtyReqd`, `StdQty`,
     `AdjControlValue`, `ExecOrder`), the instructions and the six-step trailer.
   - `TotalVolume` is computed from specific gravities and may differ from the
     client in the last digits; note whether CMS minds.
   - Open the ticket in the client: it loads, prints and saves normally.
   - The ingredient and instruction lines are sent in the order the recipe
     stores them, not in line-number order. Pick a recipe whose lines were
     renumbered and compare the order with the client's.
2. **New ticket with two packaging lines and release**, one line in units and
   one in pounds of bulk. This is three or four requests in a row.
   - After the first request, check the confirm result names the new ticket
     number (Insights reads the ticket back from CMS between requests; if the
     log result does not carry the new `Ordr`, this is where it shows).
   - Packaging tickets: `OrdDetailCommit` links to the bulk product line, dates
     match the bulk ticket, component quantities match a client-made one.
   - Release: status `RLS`, the in-process-test line and its tests, the lot
     created with today's prefix and on hold; quantities committed.
   - Repeat with an item that has no specification tests: the lot is created
     approved. Compare with a client release of such an item.
3. **Stop part way.** Open the same new ticket in the client between the
   Insights preview and confirm of an "add packaging ticket", so the version
   moves: Insights should stop and say which steps are already saved.
4. **From Scheduling.** "Cut ticket…" on a suggested run: the form opens with
   the run's item, pounds, date and packaging split. Check two by hand.
5. **Change a ticket** (ticket page): required date, planned start, overcommit
   and customer, each as its own save, on an unreleased ticket and then on a
   released one (the released save carries the packaging-lot step; compare with
   a client edit of a released ticket).
6. **Add a packaging ticket** to an unreleased ticket; try one that overdraws
   the batch (Insights refuses).
7. **Release** an unreleased ticket from its page. Then one with overcommit off
   and short stock: expect the CMS refusal in plain words; switch overcommit on
   and release again.
   - Release an item whose specification tests are not in alphabetical order
     by line: the tests are written in test-name order, as the client writes
     them. An item with two test names that first differ at a character other
     than a capital, a digit or a space is refused by Insights; release it in
     the client and note the order it writes.
8. **Close**: a completed ticket (status `CLS`, packaging tickets closed with
   it), and a never-released ticket (cancelled; its open lines closed).
   - Cancel an unreleased ordinary ticket and an unreleased repack ticket, in
     the client and in Insights, and compare which lines get
     `QtyCommitted="0"`. Insights zeroes only the repack line and sends it
     last. A ticket with two open repack lines is refused.
9. **Recipes** (`/recipes`, open a recipe): publish a bulk recipe drafted in
   the client and compare with a client publish (the old version retires, the
   item's costing recipe moves). Retire a recipe and make it active again.
   - The client's publish often has a fifth step that rewrites the batch
     line's `TotalWeight`; Insights never sends it. Note when the client sends
     it, and compare the batch line's weight after a client publish and after
     an Insights publish.

### 4.5 Shipping (built)

Grant `write.ship.*` and `shipping.invoice` to the test user. Assemble two
orders for the same ship-to in the client (one container each is enough), and
a third with two containers. (Assembling in Insights is checked in 4.8.)

1. **Ready list** (`/shipping/new`): the three orders show with the right
   container counts. This list comes from CMS's shipping-label view; if an
   assembled order is missing or a shipped one shows, note it (the view is the
   one unverified source in this flow).
2. **One order, one container.** Tick it, Continue: the container, its contents
   and the weight CMS worked out are shown. Type a freight charge. Review:
   four requests are listed (waybill, ship and invoice, pallets, complete).
   - Compare request 1 with a client waybill for a similar order: the `Waybill`
     row (ship via, freight service and option, PO, date shipped), one
     `WaybillTrackingNumber` per container (`ActWeightLb`, `ActWeightKg`), the
     `AddressRef` copy of the order's ship-to (its `TableID` is sent as `0`;
     check CMS fills in the waybill), and the to-do rows.
   - Confirm. The result names the new waybill; the order is shipped and
     invoiced, the freight charge is on the invoice, the waybill is `CMP`.
   - Open the waybill in the client: it loads and prints normally.
3. **Two orders on one waybill**, then the order with two containers: five
   requests for the first. Check the container order across orders against a
   client waybill of two orders.
4. **Different ship-to**: tick two orders for different customers: refused.
5. **Stop part way.** Start a waybill and, between requests, break the
   connection (or open the order in the client so its version moves). Insights
   stops and says which steps are saved and their log ids. It never says
   nothing was sent, and the confirmation page cannot be confirmed again. Try
   both connections: with the read connection down the next step is reported
   as not sent; with the write connection down before a call the step is
   reported as not sent; with the write connection cut during a call the step
   is reported as unknown and must be checked in the CMS log. `/shipping/new`
   now lists the waybill under "did not finish"; Finish sends the remaining
   steps. Compare with the client's own prompt to finish outstanding waybills.
6. **Labels.** On the completed waybill, "Mark labels printed": one request per
   container. Print the same labels from the client for another waybill and
   compare the `AddressReference` rows. Press it again: refused, already
   recorded.
7. **Reject.** Reject the waybill from step 2: status `REJ`, the invoice is
   reversed, the stock is back in its container and the order is ready to ship
   again (it reappears on `/shipping/new`). Reject it again: refused.
8. **Print pages.** From a waybill, open Packing slip and Invoice for an order
   and compare with the client's printouts: addresses, quantities ordered and
   shipped, prices, freight, taxes and totals. Open the invoice of the rejected
   waybill: it is marked reversed. A user without `shipping.invoice` sees no
   Invoice button and gets the usual not-found page on the address.
9. **Delivery confirmation.** Ship an order that has a delivery confirmation:
   the waybill carries `DeliveryConfirmation` directly before `PoNumber`. An
   order with a delivery confirmation and a freight account or freight service
   is refused by Insights ("Ship it in CMS"); if one is ever made in the
   sandbox, capture the client's waybill request to settle the attribute
   order.

### 4.6 Batch execution (built)

Grant `write.mfx.*` to the test user. Cut and release a small bulk ticket with
two packaging tickets (flow 4.4), and have the client's floor screen open on a
second, identical ticket to compare against.

1. **Add an ingredient.** On the ticket page, "On the floor": add an item and
   a quantity. Compare the request with the client's `NewIngredient` (the new
   `UI` line, its line number, the status steps). The client asks for a
   signature when the quantity is over the maximum; Insights sends no warning
   event. Check CMS accepts that. Try an alias code, a package and a prototype
   item: each must be refused before anything is sent.
2. **Make the batch.** Change one quantity, clear another, Check the make. The
   review lists what differs from the ticket and an estimated cost.
   - Compare with the client's `BatchExecutionExpress`: the order of the
     status steps (ours differs from the client's), `UnitCostMK` (ours is an
     estimate from replacement costs; note what CMS stores after its own
     costing), `StartDate`/`EndDate`.
   - Confirm. The bulk is in a vessel, the ingredients are consumed, the lot
     has stock. Note the ticket's status and `Version` afterwards.
   - Known failure: make a batch with an ingredient that has no stock:
     "Quantity of the item 'X' is negative" is shown and nothing is saved.
3. **Send for testing.** One request; the sample set appears in the QC queue
   (`/qc`) and results can be entered (flow 4.1).
4. **Pack out, first time.** Pick a packaging ticket, type the quantity made
   and the packages filled. Up to five requests are listed (open the packaging
   phase, release the packaging ticket, ask for the packout, open its
   production record, pack out). Compare each with the client's. Check the
   bulk drawn and the components used against what the client would have
   filled in, their order (item id ascending, the bulk among the components),
   the package count, and the estimated `UnitCostMK`. Then pack once with a
   real package picked (for example the 3.5 gallon pail) and compare with the
   client doing the same: the first `MoveInventory` must name that item, and
   the pail's stock must drop by the package count. Check what CMS does when
   the pail has no stock. A batch packed directly on the bulk ticket must show
   no packout form. A packaging ticket with the same item on two lines is
   refused by Insights; if one exists, pack it in the client and note the order
   of its moves.
5. **Pack out again** on the same packaging ticket: two requests, reusing the
   container and cost record. Then the second packaging ticket.
6. **Undo a packout**, then pack out once more: check which container the
   client reuses after an undone packout and compare.
7. **Undo the make** (after undoing every packout), then make it again: the
   second make reuses the ticket's production record. If the batch had been
   sent for testing, CMS adds a fresh test step.
8. **Complete.** One request. Check whether CMS completes the packaging
   tickets itself. The floor card is gone afterwards; undo on a completed
   ticket is refused by Insights (the client allows some of it).
9. **Stale page.** Open the ticket in two tabs, make it in one, press Make in
   the other: refused with "has been made already" (or CMS's version error),
   nothing sent twice.
10. **Direct-packed batch.** A bulk ticket packed without a packaging ticket in
    the client shows a note on the card and no packout or undo buttons.

### 4.7 Inventory counts, labels and planned dates (built)

Grant `write.inv.*` to the test user. Pick two or three raw materials with
stock and one with none.

1. **Save a count.** `/inventory/counts`, New count: the item codes and
   quantities, "Check and save". The review shows, per item, what was counted,
   what is on hand and what posting would add or remove. Compare the request
   with the client's count screen saving the same sheet.
   - Refusals to try: an unknown item code, the same item twice, a negative
     quantity, a date in the future.
2. **Change it.** Open the count, change one quantity: only that line is sent
   (`UpdateVersion` on the head). Change the description: the head is an
   `Update` with its `Version`. Change the date alone: the head is an `Update`
   with `EffectiveDate` (as in log 560112). Then change the date and a quantity
   together, and the date and the description together; a date change was only
   ever logged alone (once), so compare these two with what the client sends
   (including the order of `Description` and `EffectiveDate`) and check CMS
   accepts ours.
3. **Post it.** "Check and post". Compare `QtyAdjust` on every line and the
   `Quantity` of every move with the client posting an identical count (the
   adjustment is counted minus on hand, written to the last digit). After the
   post: stock on hand equals the counted quantity, the count is posted, its
   `Version` is 2.
   - Change stock of one item between the review and the confirm (receive some
     in the client): Insights re-reads on confirm and asks for a new review.
   - Post a count whose line is already equal to stock: the move is `-0`.
4. **Save and post in one go** on a new count: two requests in a row. The post
   uses the count and line ids CMS returned from the save and `Version` 1.
   Check both land; if the save's reply does not carry the ids, the sequence
   stops after the save and the count is posted from its own page.
5. **Delete** a count that is not posted: its lines and the head go, stock is
   unchanged.
6. **Mark a container labelled.** `/inventory/labels` lists containers with
   stock whose label is still asked for. "Mark labelled" on one; it leaves the
   list and `Location.LabelReqd` is 0. The client sends this after printing;
   Insights prints nothing.
7. **Recalculate planned dates.** One request; takes several seconds. Compare
   the planned dates on a few open orders before and after with the client's
   own recalculation.

### 4.8 Assembling orders (built)

Grant `write.ship.assemble` (and `write.ship.create_waybill` for the last
step) to the test user. Use a released sales order with two lines that have
stock, and one line whose item has less stock than ordered.

1. **List** (`/shipping/assemble`): orders ready to ship with open lines.
   Open the test order: each line shows ordered, shipped, in containers, left
   and in stock. Compare "in stock" with the client's assembly screen for the
   same item; if the client offers stock Insights does not count (or the
   other way round), note it: Insights reads the stock location only.
2. **New container.** Leave the quantities offered, Into: a new container,
   Review: one request (`UpdateVersion Ordr`, `CreateAssembly`, one
   `MoveInventory` per line). Compare with the client assembling a similar
   order. Confirm, then check in the client: the container exists under the
   receiving location, holds the stock, and the stock is reserved to the
   order line.
   - Back on the page the container is listed with its contents and the lines
     show the quantity under "In containers". **This is the first time the
     contents reader meets a container that holds stock**: if the container
     shows as Empty or the quantities are wrong, stop and report it.
   - Check what CMS did to the line's shipped quantity. Insights works out
     "left" as ordered minus shipped minus in containers; if CMS already
     counts assembled stock as used, "left" is too low and needs a fix.
3. **Add to the container.** Assemble a part quantity of a line first, then
   more of the same line into the same container: the request carries the
   container's `Version`, a `CheckVersion` of the stock location before the
   move, `Quantity` = the amount added and `QtyEntered` = the new total in the
   container. Then add a line the container does not hold yet: that move has
   no `CheckVersion` and carries the typed number as both `Quantity` and
   `QtyEntered`.
4. **Remainder that is not exact.** On a line whose remainder is not an exact
   decimal (for example 163.7 ordered, 80 already assembled), leave the
   offered quantity alone in the client and in Insights and compare: the
   client can send `Quantity="83.699999999999989" QtyEntered="83.7"` (Log
   857769), Insights sends `83.7` in both. Confirm CMS accepts the Insights
   request and the line shows fully assembled.
5. **Refusals to try**: more than is left on the line, more than is in stock,
   zero, a closed line, an order that is not ready to ship, a container of
   another order (edit the form), and an order changed in the client between
   review and confirm.
6. **Take out.** Take part of one line out, then "Take out everything": each
   request has a `CheckVersion` of the stock location before each move and a
   negative `Quantity`. For the part taken out `QtyEntered` is what stays in
   the container; for a whole line it is what came out. The stock is back in
   the stock location and no longer reserved. The emptied container stays in
   CMS, as it does in the client.
   - If the stock location's `Version` has moved since the log was captured,
     CMS may refuse the check; note the message.
   - If a container ever shows one order line as two content rows, note it:
     Insights refuses to add to or take from such a line and asks for it to be
     done in CMS.
7. **Ship it.** Assemble again, "Ship this order": the order is on
   `/shipping/new` with the container, and ships as in 4.5.
8. **Reserved stock.** Assemble an item whose stock is partly reserved to
   another order. Note whether CMS refuses, and with what message: Insights
   only compares against the total on hand.

### 4.9 Item aliases (built)

Grant `write.item.alias` to the test user. Use a stock item that has one or
two aliases, and a second stock item to move an alias to.

1. **Add.** On the item's page (`/items/view?code=…`) type a new alias code,
   leave the description blank, Add alias: one request (`Insert Item` with
   `Context="NAME"` and `ReplacedBy` the item, then `FixupItemManf`). Compare
   with the client adding an alias to the same item. Confirm; the alias is
   listed on the page and in the client, with the item's description.
   - Type the code in lower case: Insights sends it in upper case, as every
     code in CMS is. Note it if the client keeps lower case.
2. **Change.** Change the description of an alias: the request has only
   `Description`. Change the code: only `ItemCode`. Change both and move it
   to the second item in one go: `ItemCode`, `Description`, `ReplacedBy` in
   that order.
3. **Move.** After a move the alias is on the second item's page. Open a
   sales order or price list that used the alias and check it now shows the
   second item, as it does after the same move in the client.
4. **Refusals to try**: a code that an item or another alias already has, a
   code over 30 characters, an item code that does not exist as the item to
   move to, a packaging prototype as the item to move to, and an alias
   changed in the client between review and confirm (CMS refuses on
   `Version`; note the message).

### 4.10 Items: minimum stock, preferred supplier, new item (built)

Grant `write.item.settings` and `write.item.create` to the test user.

1. **Minimum stock, item that has one.** On a raw material's page change
   the minimum, Set minimum: one request (`UpdateVersion Item`, `Update
   ItemEntity` with `MinimumStock`, then the two closing steps). Compare with
   the client changing the minimum of the same item. Confirm; the tile on
   the page, the client and Need to Order show the new minimum.
2. **Minimum stock, item that never had stock settings.** Pick an item with
   no minimum (a new one from step 4 will do): the request has `Insert
   ItemEntity` with `Entity="4" Context="ST"` instead of the update.
3. **Preferred supplier.** On a raw material's page pick another supplier,
   Set supplier: one request (`Update Item` with `Supplier`, then the two
   closing steps). Need to Order proposes the item under the new supplier.
   A pack size of a product has no supplier box.
4. **New item.** Items → New item: kind Raw material, a new code, a
   description. One request: `Insert Item`, `Insert ItemChemical`, `Insert
   ItemCustom`, the two closing steps. Create the same kind of item in the
   client and compare the two requests attribute by attribute (the client
   sometimes sends a `Version` other than 1 on the insert; the new row has
   Version 1 either way). Open the new item in the client: every tab opens,
   and it can be put on a purchase order and received.
   Repeat with kind Packaging (unit `ea`, GL group Packaging). The packaging
   request is golden-tested against log 501743. Insights always sends
   `Treatment="WHS"` and the review says so; in the client the treatment is
   chosen per item and about half of packaging items are `NONE`, so create one
   of each in the client and confirm WHS is the wanted default.
5. **Refusals to try**: a minimum that is not a number, the minimum it
   already has, a code that exists, an inactive supplier (only reachable by
   posting by hand), and an item changed in the client between review and
   confirm (CMS refuses on `Version`; note the message).

### 4.11 Customers: address, carrier and freight account, new ship-to (built)

Grant `write.customer.edit` and `write.customer.create_shipto` to the test
user. The pages are under Sales Orders → Customers.

1. **Address.** Open a ship-to, change the first address line and the zip
   code, Change address: one request (`UpdateVersion Entity`, `Update
   AddressRef` with only the two changed fields). Compare with the client
   making the same change on its Ship To screen. Confirm; the client shows
   the new address, and a new order for the customer picks it up.
2. **Address of a bill-to account.** Change the email of a bill-to: the
   request carries `Program="Bill To Update"` in the logged copy of the
   client; ours is the same two steps.
3. **Removing a field.** Empty the second address line of a customer that
   has one: the field is sent as `AddrLine2=""`. Check that the line is
   gone in the client afterwards.
4. **Carrier and freight account.** Pick another carrier and type an
   account, Change shipping: one request (`Update Entity` with `ShipVia`
   and `FreightAccount`, or only the one that changed). A new order for the
   customer starts with the new carrier.
5. **New ship-to.** Open a bill-to account, New ship-to: code, name,
   address, carrier. One request: `Insert Entity`, six `Insert
   EntityDocument`, `Insert AddressRef`. Create a ship-to the same way in
   the client and compare the two requests attribute by attribute. Open the
   new ship-to in the client: every tab opens. Enter a sales order for it
   in Insights and ship it.
6. **Refusals to try**: a ship-to code that exists (also try the code of a
   supplier), a state CMS does not know, an inactive carrier (only
   reachable by posting by hand), and a customer changed in the client
   between review and confirm (CMS refuses on `Version`; note the message).

### 4.12 Supplier price lists: change a price or code, add an item (built)

Grant `write.price.edit` to the test user. The pages are under Purchase
Orders → Price lists.

1. **Price.** Open a supplier, change the price of one line, Change: one
   request (`UpdateVersion PriceVersion`, `Update PriceDetail` with only
   `Price1`). Compare with the client changing a price on the same list.
   Confirm, then start a new purchase order for the item: it takes the new
   price.
2. **Supplier's code.** Change the code of a line: the same two steps with
   only `EntityItemCode`. Change price and code together: `Price1` then
   `EntityItemCode` in one step. That combination was not seen from the
   client; check that CMS takes it.
3. **Add an item in a package.** Our item code, the supplier's code, a
   package and the quantity in it, a price: one request (`UpdateVersion
   PriceVersion`, `Insert PriceDetail`). Add an item the same way in the
   client and compare attribute by attribute. Open the list in the client:
   the line is there. Order the item on a new purchase order by the
   package.
4. **Add an item sold loose.** No package: `PkgType` and `EntityQuantity`
   are both left out. Leave the supplier's code empty as well and check
   that CMS takes the line.
5. **Refusals to try**: an item and package size already on the list (CMS
   refuses a duplicate itself; ours is refused first), a supplier without a
   price list, a line with prices by quantity (price change refused, code
   change allowed), and a list changed in the client between review and
   confirm (CMS refuses on `Version`; note the message).
6. **A later list.** For a supplier with a price list dated in the future,
   the page says so and the change goes to the list in effect today. Check
   that this is the list the client's purchase order screen uses.

### 4.13 Supplier bills: enter an invoice against received lines (built)

Grant `write.bill.create` to the test user. The page is under Purchase
Orders → Supplier bills. Receive two or three purchase order lines first
(4.3) so that something is waiting.

1. **A plain bill.** Open the supplier, tick one received line, type the
   invoice number, a date and the total shown for the line: one request
   (`Insert Bill`, `Update BillDetail` with no values, `Execute
   UpdateBillCost`). Enter a bill for a similar line in the client and
   compare attribute by attribute, `Terms` and `Amount` above all. Confirm;
   the line leaves the waiting list and the bill shows in the client.
2. **Freight.** Tick a line and give it an extra cost: the line carries
   `AddCost`, and `Amount` first when the received value has part cents.
   Check afterwards that the stock cost of the receipt in CMS went up by
   the extra cost.
3. **Several lines**, one of them with a small negative extra cost to
   match the invoice to the cent. The header `Amount` is each line rounded
   to cents plus its extra cost.
4. **Lines from two purchase orders with different terms**: the review
   says which terms the bill takes. Check what the client does.
5. **Refusals to try**: a total that is off by a cent (the message says by
   how much), an invoice number the supplier already has (CMS refuses it
   too, on its unique index), an invoice dated 22 days back (left to the
   client, which asks for a signature), a future date, and a line billed
   in the client between review and confirm.
6. After each bill, compare the `Bill` and `BillDetail` rows and the
   receipt's stock cost with a bill entered in the client.

### 4.14 Invoice freight: set or change the freight charge (built)

Grant `write.invoice.freight` (and `shipping.invoice`) to the test user.
Ship and invoice two or three orders first (4.5). The form is on the
invoice page, reached from a waybill under Shipping.

1. **Add freight to an invoice that has none.** One request: `UpdateVersion
   Trans`, the reversal (`Insert Trans` with `ReversedTrans`, its lines with
   negative quantities), the new invoice with `FreightCharge`, its lines,
   `Insert MemoRef`, `Execute UpdateInvoiceTax`, `Update ChangeSet`, `Execute
   CheckInvoiceQty`, `Execute UpdateShipmentPoNumber`. Do the same on a
   similar invoice in the client and compare attribute by attribute.
2. **Change the freight on the invoice just corrected.** The reversal now
   carries the old charge negated with four decimals (`-271.1800`). Check
   the shipment record's `Version` in the request against CMS.
3. **An invoice with several lines, one with a part quantity** (98.8 lb):
   every line appears twice, in line order.
4. **An invoice whose note has text of its own** (an export declaration):
   the text is carried over, line breaks included. Compare how the client
   writes the line breaks.
5. After each: the old invoice shows as reversed, the new one has the same
   number, the order's invoiced quantities are unchanged, the shipment
   points at the new invoice, and the invoice prints from the client with
   the new total.
6. **Refusals to try**: a taxed invoice, an invoice with an extra charge
   line, an invoice already reversed (open the old link), the same amount
   again, 0 on an invoice with freight, and an invoice corrected in the
   client between review and confirm (CMS refuses on the version).
7. Ask accounting whether an invoice from a closed month may still be
   corrected; the page only warns after 31 days.

Returns to a supplier stay in the client (four requests ever).

### 4.15 onward (not built yet)

Other invoice corrections and returns to a supplier are researched (`docs/research/`)
but have no templates. Anything built later gets its section here, following
the same pattern: do it in Insights, do it in the client, compare the request
and the rows, then try the known failure (listed per action in `docs/PARITY.md`).

## 5. Before production

- Every flow to be enabled has passed section 4 on the copy.
- The write login on production has the same minimal rights as in section 1.
- Write permissions are granted role by role, not to everyone.
- Start with one flow (QC) and one person for a day; review Admin → CMS → Writes.
