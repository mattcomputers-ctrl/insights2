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

### 4.3 onward (not built yet)

Purchasing, batch tickets, shipping, batch execution, inventory,
master data and bills are researched (`docs/research/`) with fixtures stored, but
have no templates yet. Each gets its section here when it is built, following
the same pattern: do it in Insights, do it in the client, compare the request
and the rows, then try the known failure (listed per action in `docs/PARITY.md`).

## 5. Before production

- Every flow to be enabled has passed section 4 on the copy.
- The write login on production has the same minimal rights as in section 1.
- Write permissions are granted role by role, not to everyone.
- Start with one flow (QC) and one person for a day; review Admin → CMS → Writes.
