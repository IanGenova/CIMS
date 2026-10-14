# CIMS Debug Report

## Executive result

The uploaded application is a legacy Laravel 8 + Vue 2 inventory and POS system. The clean patched package fixes multiple route, authentication, installer, stock-integrity, reporting, SMTP, and project-hygiene defects. Static validation passes, but a complete production sign-off still requires a real MySQL database and test credentials for Stripe, Twilio, and SMTP.

## Highest-risk defects corrected

### Stock integrity

The original `product_warehouse.qte` column was a whole-number `FLOAT(10,0)` even though the application performs unit multiplication and division. A new migration changes it to `DECIMAL(20,6)`. The stock model now rejects negative quantities, while POS and standard sales lock stock rows during deduction to reduce overselling from concurrent requests.

### Route and authorization exposure

Invoice, PDF, payment-PDF, POS invoice, and product-filter routes were registered outside the Passport-protected route group. They are now inside `auth:api` + `Is_Active`. API throttling is restored.

### Broken registered endpoints

The permission check used `Route::resource` incorrectly. Several API routes referenced missing methods. Compatible methods were added or routes were corrected so explicit controller actions resolve.

### Inactive-user handling

The original middleware executed the protected action before checking whether the account was active. It now blocks known inactive users before execution and performs a second check after login.

### Installer reliability

The installer called `migrate:fresh --force --seed` as one command string, exposed raw exception text, had stale routes, and used inconsistent environment session keys. These were corrected. The command-line setup in the accompanying guide remains the recommended path.

### Sensitive files

The uploaded archive contained a live `.env`, Passport keys, runtime sessions, application logs, an installed marker, and a SQL dump containing OAuth and SMTP data. These are excluded from the clean fixed package.

## Files of special interest

- `app/Models/product_warehouse.php`
- `database/migrations/2026_07_24_000001_fix_product_warehouse_quantity_precision.php`
- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/SalesController.php`
- `app/Http/Controllers/ReportController.php`
- `app/Http/Middleware/Is_Active.php`
- `routes/api.php`
- `routes/web.php`
- `app/Http/Controllers/SetupController.php`
- `app/Http/Controllers/SettingsController.php`
- `resources/src/views/app/pages/pos.vue`

## Validation evidence

- PHP syntax lint passed for all patched application, route, migration, and seeder files.
- JavaScript syntax checks passed for 110 Vue/JavaScript script sections.
- Static route-to-controller validation found no missing explicit API controller methods.
- The application could not be booted under the review environment's PHP 8.4 because the project's locked Symfony 5.3 components are incompatible with that runtime. Use PHP 8.0 or 8.1 for this exact package.

## Remaining work before production approval

- Test every CRUD module against a cloned database.
- Test simultaneous sales/transfers/adjustments against low stock.
- Reconcile existing negative or duplicate stock rows before production migration.
- Verify PDF layout and printer behavior.
- Test role policies for every menu/action.
- Verify SMTP, Twilio, and Stripe in sandbox accounts.
- Upgrade Laravel 8 and Vue 2 in a separate modernization branch rather than mixing that major upgrade with the immediate bug-fix package.
