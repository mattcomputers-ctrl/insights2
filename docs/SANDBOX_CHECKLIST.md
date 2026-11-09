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
5. **Kill switch.** Turn the CMS kill switch on; confirm a write is refused.
6. **Unmapped user.** A user with no CMS user set cannot write (clear message).

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
6. **Complete order** on an order with an unshipped line: lines closed,
   `QtyCommitted` cleared, status `CMP`, `DateCompleted` set, reserve released.
7. **Complete sale** on an open order for a sundry or fee item: a shipment and an
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
8. **Close**: a completed ticket (status `CLS`, packaging tickets closed with
   it), and a never-released ticket (cancelled; its open lines closed).
9. **Recipes** (`/recipes`, open a recipe): publish a bulk recipe drafted in
   the client and compare with a client publish (the old version retires, the
   item's costing recipe moves). Retire a recipe and make it active again.

### 4.5 Shipping (built)

Grant `write.ship.*` and `shipping.invoice` to the test user. Assemble two
orders for the same ship-to in the client (one container each is enough), and
a third with two containers.

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
   stops and says which steps are saved. `/shipping/new` now lists the waybill
   under "did not finish"; Finish sends the remaining steps. Compare with the
   client's own prompt to finish outstanding waybills.
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

### 4.6 onward (not built yet)

Batch execution, inventory,
master data and bills are researched (`docs/research/`) with fixtures stored, but
have no templates yet. Each gets its section here when it is built, following
the same pattern: do it in Insights, do it in the client, compare the request
and the rows, then try the known failure (listed per action in `docs/PARITY.md`).

## 5. Before production

- Every flow to be enabled has passed section 4 on the copy.
- The write login on production has the same minimal rights as in section 1.
- Write permissions are granted role by role, not to everyone.
- Start with one flow (QC) and one person for a day; review Admin → CMS → Writes.
