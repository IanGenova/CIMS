<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Working multi-currency.
 *
 * currencies held only code, name and symbol, so you could change the symbol
 * on screen but not transact in a second currency. Each currency now carries
 * a rate against the base currency, and every order document snapshots the
 * currency and the rate it was written at -- a later rate change must not
 * silently restate historical documents.
 *
 * Stored money stays in the document's own currency. base_amount carries the
 * converted figure so existing reports, which sum GrandTotal, keep working
 * for single-currency deployments and gain a correct total for mixed ones.
 */
class AddExchangeRatesToCurrencies extends Migration
{
    public function up()
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('is_base')->default(false);
            $table->timestamp('rate_updated_at')->nullable();
        });

        // Whatever the settings row points at is the existing base currency.
        $baseId = DB::table('settings')->value('currency_id');
        if ($baseId) {
            DB::table('currencies')->where('id', $baseId)
                ->update(['is_base' => true, 'exchange_rate' => 1]);
        }

        foreach (['sales', 'purchases', 'quotations', 'sale_returns', 'purchase_returns'] as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedInteger('currency_id')->nullable()->after('GrandTotal');
                $table->decimal('exchange_rate', 20, 8)->default(1)->after('currency_id');
                $table->decimal('base_amount', 20, 4)->nullable()->after('exchange_rate');
            });
        }
    }

    public function down()
    {
        foreach (['sales', 'purchases', 'quotations', 'sale_returns', 'purchase_returns'] as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['currency_id', 'exchange_rate', 'base_amount']);
            });
        }
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'is_base', 'rate_updated_at']);
        });
    }
}
