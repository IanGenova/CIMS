# Branch and inventory traceability development guide

**Purpose:** Make CIMS support a central warehouse, a main store, and multiple branches while tracing stock through **Supplier → Purchase Order → Receiving → Quality Check → Warehouse → Branch → Sale**.

**Status (20 October 2026):** A local pilot covers the main supplier-to-branch-to-sale path, reverse branch pull-outs with QC hold, and a CIMS-hosted `/shop` storefront. The new work has not been deployed to the live Hostinger site. The full three-phase program is **not complete**; the open work listed below needs implementation and acceptance testing before a production rollout.

**Confirmed decisions (16 October 2026):** Branches are company-owned locations using one inventory and set of books. Receipt-to-sale source tracing is required for selected products, identified by batch or serial tracking. Other products still require accurate product-and-warehouse movement history.

**Confirmed decisions (19 October 2026):** QC is required per product and release needs a separate QC approver permission. Failed goods need a separate manager approval for supplier return or write-off. Customer reservations persist until staff fulfill or cancel them; they do not expire automatically. Branches may accept pre-orders before stock arrives, clearly marked pending stock.

**Implemented locally:** Location codes and types; separate internal batch identities for repeated printed lot numbers; receipt-line links on new batch and serial units; purchase/receipt source details in batch and serial lookup; an opt-in `requires_qc` product flag; held stock on new flagged receipts; a separate QC approval permission; partial pass/fail decisions with actor, time and reason; and release of passed batch, serial, or ordinary goods into sellable stock. A separate manager can approve a requested supplier return or write-off for failed goods, including named failed serials. Branch requests support separate approval, exact-lot/serial allocation, dispatch, partial branch receipt, discrepancy visibility, and pre-dispatch cancellation with reservation release. Reverse branch pull-outs use the same approval/dispatch/receipt chain and hold QC-marked returns until a separate approver releases them. Customer orders support durable reservations, manual cancellation, clearly marked pending-stock pre-orders, later allocation, exact-reservation sale conversion, and sequentially idempotent web order imports by external ID. The authenticated availability API reports sellable stock net of active reservations. The Trace Explorer searches product, lot, serial, PO, receipt, sale, and branch shipment references; it shows source, QC, movements, current lot locations, reconciliation, reserved/available/in-transit totals, low stock and selected exception lists. Historical tracked stock without a receipt link is shown as source unknown. The `/shop` storefront reads published CIMS products and real available-to-promise stock from a selected fulfillment warehouse, accepts idempotent guest orders, reserves available quantities, permits clearly marked manual-payment pre-orders, offers cash on delivery or pay in store, and protects public order status with a secret receipt link. Xendit payment sessions use the existing account configuration, hosted payment page, server-side capture verification, and payment reconciliation into the eventual sale. Signed customer-order status webhooks are queued for other integrations.

**Verification:** Targeted tests cover QC, receipt provenance, branch allocation/receipt/cancellation, reservations, web retry/pre-orders, network trace, and a single supplier → PO → two receipts → QC → branch → reserved sale → correction scenario. The storefront has tests for catalog publication, reservation, retry, cancellation, pre-order, simulated Xendit capture and staff re-verification. On 20 October, the selected suite passed 26 tests with 331 assertions. A browser check placed and cancelled a local test order, confirmed the receipt/status screens, and checked catalog search. The Vue production bundle compiles with Node 16. The installed `node-sass` version does not support the machine's default Node 24 runtime. Live Xendit payment remains untested until the account key and webhook token are configured by the owner.

**Still required before the full requirement is met:** Pick/pack and carrier handoff evidence; failed return disposition and physical rejected-goods custody; attachments; configurable approval matrices and complete employee activity coverage; a consolidated source-to-sale view that includes every document and customer order in one screen; preorder priority and deposit rules; shipping quotations and fulfillment tracking for the storefront; full refund/return acceptance scenarios; concurrency and failure-retry tests across separate database workers; historical-stock migration/physical count; branch-by-branch permissions and operational acceptance; production backup, rollback and deployment. Existing marketplace integrations require their own live configuration and verification. The production storefront should stay disabled until public catalog, checkout, payment and operational acceptance are signed off.

## What CIMS already provides

- Products, suppliers, purchase orders, goods receipts, warehouses, transfers, sales, POS, reports, roles, and permissions.
- Accepted and rejected quantities on a goods receipt. Accepted stock enters the warehouse immediately.
- Warehouse stock balances and a movement history with document references.
- Optional batch and serial tracking. Batch movement history can follow a lot across warehouses and into sales; serial history can follow a named unit.
- Purchase approval based on a configurable amount, temporary POS basket holds, stock counts, and reorder suggestions.
- Audit records for selected business records and integration code for Shopee and Lazada. Neither amounts to a complete franchise workflow or a custom website integration.

## Decisions to settle before building

1. **QC disposition:** Where failed or damaged goods physically wait and which evidence or photos must be kept. Manager approval for supplier return or write-off is confirmed.
2. **Order promises:** Whether reservations may span branches. Reservations are manual-release; branch pre-orders pending stock are allowed.
3. **Returns and pull-outs:** Who authorizes them, where returned goods go, and whether they must pass QC again.
4. **Historical stock:** Decide how to mark stock already on hand whose supplier and receipt cannot be proved. Do not invent a source for an opening balance.

## Phase 1 — Establish trustworthy stock identities and balances

### 1. Branch and warehouse structure

- Classify company-owned locations as a central warehouse, main store, or branch, with a stable code. Add separate receiving and selling warehouses at a location only where operations need them.
- Keep a central warehouse, main store, and each branch distinct in stock, sales, and reports.
- Apply branch access rules to every read, save, export, report, and integration endpoint. Define central-office access explicitly.
- Treat transfers between these locations as movements of the same company's stock, with branch-specific access and reporting.

**Done when:** A branch user can operate only assigned branches; central staff can see the network; a branch's inventory and sales cannot be mistaken for another's.

### 2. Receipt-specific provenance

- Make each selected product's received lot or serial link to its exact purchase-order line, goods-receipt line, supplier, date, and receiving warehouse.
- Keep different receipts distinguishable even when a supplier reuses a printed batch number. The current batch record has one supplier field, so merging the same batch number from multiple sources would make supplier provenance ambiguous.
- Keep ordinary, unmarked goods traceable at product and warehouse movement level; assigning an internal receipt lot to every such unit is outside the confirmed selected-product requirement.
- Preserve the source identity when quantities are split, transferred, returned, or sold. Record the exact quantity from each source on every outbound document line.

**Done when:** From any sale line of a selected tracked product, an authorized user can identify the supplying receipt and purchase-order line; from a receipt, they can list the branches and sales that consumed it.

### 3. Stock-state ledger and reconciliation

- Represent stock states clearly: awaiting QC, sellable, reserved, in transit, damaged/rejected, and written off. Define which states count as physically on hand and which are available to promise.
- Record each state change and stock movement with actor, time, product, source identity, quantity, source/destination, and document reference. Correct mistakes through reversal entries rather than erasing history.
- Reconcile product totals with receipt-lot/serial totals and warehouse/location totals. Alert on differences.
- Mark historical opening balances as **source unknown** until physically counted and assigned an approved opening identity.

**Done when:** Movement and state totals reconcile to each warehouse balance, and a correction leaves its original event visible.

## Phase 2 — Complete the operating workflows

### 4. Receiving and separate QC release

- Keep the existing purchase-order and goods-receipt link and partial-receipt handling.
- Receive goods into a QC hold state when inspection is required. Record inspected quantity, result, reason, photos or attachments if needed, inspector, and decision time.
- Release only passed quantities into sellable warehouse stock. Keep failed goods unavailable and route them to supplier return, disposal, or reinspection.
- Support partial pass/fail decisions and prevent receiving more than was ordered without an authorized exception.

**Done when:** An uninspected or failed item cannot be sold or transferred as sellable stock; the receipt shows exactly who released each accepted quantity.

### 5. Branch requests, fulfillment, and pull-outs

- Let a branch submit a stock request to the central warehouse with required quantities and priority.
- Add review/approval, allocation, pick/pack, dispatch, in-transit, branch receipt, and discrepancy steps. Support partial fulfillment and cancellation.
- Link each request line to its transfer line and the receipt lots or serials actually sent.
- Add a reverse pull-out/return request with reason, approval, shipment, central receipt, and QC where required.

**Done when:** A branch request can be followed from request to approval, dispatched goods, acknowledged branch receipt, and later sale or return; shortages remain visible.

### 6. Reservations and pre-orders

- Add durable reservations tied to a customer order or branch request, product, source identity when picked, warehouse, quantity, owner, expiry, and status.
- Show **available to promise = sellable stock minus active reservations**. Do not count QC-held or in-transit goods as sellable at their destination.
- Convert or release reservations atomically on sale, cancellation, or transfer. They remain active until staff act. Prevent two tills or channels from promising the same last unit.
- Add a pre-order workflow for goods not yet received: expected supply, customer promise date, deposit/payment policy, fulfillment priority, and back-order/shortfall handling. A pre-order must not create fictitious on-hand stock.
- Keep temporary POS basket holds, but make their relationship to durable reservations explicit.

**Done when:** Concurrent orders cannot over-allocate a warehouse; cancelled promises restore availability exactly once.

### 7. Approval rules and staff accountability

- Retain purchase approval and add configurable approvers for QC release, branch requests, transfer dispatch/receipt exceptions, pull-outs, write-offs, and high-value adjustments.
- Separate the requester from the approver where the business requires it. Record decisions and reasons.
- Extend staff activity reporting to cover those workflow actions, stock-state changes, failed attempts, and integration actions, in addition to existing record-change audits.
- Check that every stock-changing path creates a reliable audit event; do not treat a best-effort observer as the only evidence for a critical movement.

**Done when:** The approval history answers who requested, approved, moved, inspected, and corrected each document, with times and reasons.

## Phase 3 — Make the chain visible and connect other channels

### 8. End-to-end trace screen and reports

- Provide backward trace from barcode, batch, serial, sale, or branch stock to supplier, purchase order, receipt, QC decision, and every transfer.
- Provide forward trace from supplier receipt or batch to current locations, reservations, branches, sales, customers, returns, and write-offs.
- Include quantity reconciliation, exceptions, source-unknown stock, expired lots, outstanding QC, in-transit differences, and aged reservations.
- Add branch and network dashboards for on-hand, available, reserved, in transit, low stock, and sales.

**Done when:** A user can run a recall or dispute investigation without stitching together separate screens or spreadsheets.

### 9. Website and e-commerce integration

- Publish controlled product, price, and available-to-promise information from the correct branch or fulfillment warehouse.
- Import orders with an external order ID, reserve stock once, and update fulfillment/cancellation status. Use idempotency and retries so duplicate webhooks cannot duplicate sales or stock movements.
- Define channel ownership, customer data, shipping, taxes, returns, and who may change online listings.
- Test the existing marketplace connections separately from any future custom website integration; code presence alone does not prove a channel is configured or live.

**Done when:** An online and in-store buyer cannot both purchase the same last unit, and each online order is traceable through fulfillment and sale.

## Acceptance scenario for the full requirement

1. Supplier **S** receives purchase order **PO-1** for 10 units of product **P**.
2. Two deliveries arrive against **PO-1**. QC accepts 6 units and rejects 2 in the first receipt; the supplier later replaces the rejected 2 and delivers the 2 originally outstanding. Each receipt/source stays identifiable.
3. Accepted units enter the central warehouse. Rejected or pending-QC units never appear as available to sell.
4. Branch **B** requests 4 units. An approver releases them, the central warehouse dispatches the identified units, and **B** acknowledges receipt of exactly 4.
5. One of those units is reserved, then sold at branch **B**. A second attempted sale cannot take the reserved last unit.
6. Searching the sale shows the precise supplier, PO line, receipt line, QC decision, central and branch movements, user actions, and customer sale. Searching the receipt shows where all accepted units went.
7. A return, cancellation, rejected inspection, and correction each leave an attributable, reversible history and reconciled stock balances.

## Rollout order

Build and verify Phases 1–3 in small changes, starting with the data identity and QC gate. Migrate and label existing balances honestly, then trial the complete acceptance scenario with one central warehouse and one branch. Only after reconciliation and permission tests pass should the workflow be enabled for all branches or connected to an e-commerce channel.
