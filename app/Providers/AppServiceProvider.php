<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Console\ClientCommand;
use Laravel\Passport\Console\InstallCommand;
use Laravel\Passport\Console\KeysCommand;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Schema;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Models whose every change is recorded in audit_logs.
     *
     * Covers anything that moves money, moves stock, or grants access.
     * AuditLog itself is deliberately absent — the log is append-only and
     * must not audit its own writes.
     */
    private $auditedModels = [
        // Money documents
        \App\Models\Sale::class,
        \App\Models\SaleDetail::class,
        \App\Models\Purchase::class,
        \App\Models\PurchaseDetail::class,
        \App\Models\Quotation::class,
        \App\Models\QuotationDetail::class,
        \App\Models\SaleReturn::class,
        \App\Models\SaleReturnDetails::class,
        \App\Models\PurchaseReturn::class,
        \App\Models\PurchaseReturnDetails::class,
        \App\Models\Expense::class,

        // Payments
        \App\Models\PaymentSale::class,
        \App\Models\PaymentPurchase::class,
        \App\Models\PaymentSaleReturns::class,
        \App\Models\PaymentPurchaseReturns::class,

        // Stock
        \App\Models\product_warehouse::class,
        \App\Models\Adjustment::class,
        \App\Models\AdjustmentDetail::class,
        \App\Models\Transfer::class,
        \App\Models\TransferDetail::class,
        \App\Models\Product::class,
        \App\Models\ProductVariant::class,

        // Access and configuration
        \App\Models\User::class,
        \App\Models\Role::class,
        \App\Models\Setting::class,
        \App\Models\Warehouse::class,
        \App\Models\Client::class,
        \App\Models\Provider::class,
        \App\Models\Unit::class,
        \App\Models\Currency::class,
    ];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);
         /*ADD THIS LINES*/
        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        $this->registerAuditing();
    }

    /**
     * Attach the audit observer.
     *
     * Skipped while migrating or seeding so a fresh install does not fill the
     * log with its own scaffolding, and skipped if the table is not there yet.
     */
    private function registerAuditing()
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }
        } catch (\Throwable $e) {
            // No database connection yet (first boot, installer). Nothing to do.
            return;
        }

        foreach ($this->auditedModels as $model) {
            if (class_exists($model)) {
                $model::observe(\App\Observers\AuditObserver::class);
            }
        }
    }
}
