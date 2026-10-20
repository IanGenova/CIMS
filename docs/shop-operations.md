# CIMS online shop operations

The storefront runs inside CIMS at `/shop`. It reads the selected warehouse's current available-to-promise balance from the same database used by POS and branch orders. It does not maintain a second inventory copy.

## Turn on the shop

1. Run the pending Laravel migrations and deploy the built frontend and `public/css/shop.css` and `public/js/shop.js` with the PHP source.
   `public/js/bundle/` is ignored by Git, so the deploy process must build or upload those generated files separately; cloning the repository alone does not provide the staff interface bundles.
2. In **Settings → Online Shop**, enter the shop name, choose the selling and fulfillment warehouse, and save. Keep **Accept online orders** off while validating products and staff workflow.
3. In each approved product's edit screen, enable **Publish on online shop**. Publication is off by default. Check sale price, sale unit, variants, images, VAT classification, and stock in the selected warehouse before publishing.
4. Preview `/shop`, place and cancel a test order, and verify the warehouse's available stock returns to its prior figure.
5. Turn on **Accept online orders** only after staff can monitor and fulfill customer orders. The public page shows a preparation notice when the shop is off; checkout and catalog API are unavailable.

The local development database currently has the shop enabled for Main Warehouse with eight sample hardware products published for browser QA. These local settings are not part of the Git source and do not configure production.

## Process an order

- **Sales → Customer Orders & Pre-orders** shows orders from the website with their quoted amount, payment choice, reservation state, and Xendit status if applicable.
- An in-stock web checkout creates one customer order, reserves its exact stock sources, and lowers available-to-promise immediately. The guest receives an unguessable status link. Duplicate checkout submissions with the same key return the existing order.
- An allowed pre-order is marked **Pending stock** and has no fictitious stock reservation. Staff allocate it after goods arrive. This preference stays active until staff act; there is no automatic expiration.
- Staff fulfill a reserved order using **Fulfill as sale**. CIMS verifies product, variant, quantity, quoted sale unit and price. Conversion consumes that order's reservation, records the sale, and preserves traceability.
- Cash on delivery and pay in store are manual collection choices. Staff record actual payment when due. The web checkout's amount excludes any delivery charge; arrange and disclose delivery charges separately before dispatch.
- Customer cancellation releases active reservations once. A fulfilled order uses the existing sale return workflow. Orders with a payment in progress or already paid cannot be cancelled directly; resolve/refund the payment first.

## Xendit

In **Settings → System Settings → Payment Gateway**, add the Xendit secret key and webhook token. Register `https://YOUR-CIMS-HOST/api/xendit/webhook` in the Xendit dashboard for payment-session callbacks. Use test credentials and test transactions before live credentials. The online option is hidden until a key is configured.

An online order must have all items reserved before its payment page can open. CIMS creates a hosted Xendit Payment Session and checks Xendit's session **and capture** server side before showing **Paid**. An incomplete, unclear, or underpaid result is held for review so a second charge cannot start automatically. When staff fulfill the exact quoted order total, the verified payment is posted to the resulting sale and accounts journal once. Xendit-backed sale voids and deletions are blocked while money has not been refunded. A refund may go through Xendit where supported or be recorded as refunded outside Xendit after staff return the money.

Staff can use **Verify Xendit payment** in Customer Orders to recheck a known session. If Xendit created a session but CIMS never received its ID, the order remains in review; check the Xendit dashboard using the shown merchant reference and reconcile it before any replacement checkout or stock release. A Xendit order cannot be fulfilled as a sale until the provider capture is verified as paid.

The user chose to configure Xendit credentials later. As of 20 October 2026, only simulated Xendit tests have run locally; no live Xendit charge has been attempted.

## Public data and security

- Only active products explicitly marked for web publication appear. Cost, supplier and internal purchase records are never returned to the public catalog.
- Public order details require a 64-character receipt token; only its hash is stored. Do not post receipt links publicly.
- Checkout uses Laravel CSRF validation, request validation, per-route throttling, an idempotency key, and database stock locks. The selected warehouse and prices come from CIMS, never from browser values.
- Webhooks for `customer_order.status` are signed and queued for optional integrations. The `/shop` site itself uses the same CIMS database and does not need a webhook to synchronize stock.

## Before production rollout

Back up the production database and current release, run migrations in a maintenance window, check the route at `/shop`, verify API authentication and staff permission boundaries, test a full order through sale and return, and inspect payment reconciliation using Xendit test mode. Keep the shop disabled until those checks pass. The broader phase 1–3 gaps remain listed in [branch-traceability-development-guide.md](branch-traceability-development-guide.md).
