<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quotation becomes one sale, once (DATATEST K39).
 *
 * Nothing recorded the conversion, so the same quotation could be turned into
 * any number of sales, each taking its goods off the shelf.
 */
class LinkQuotationsToSales extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('quotations', 'sale_id')) {
            Schema::table('quotations', function (Blueprint $t) {
                $t->unsignedBigInteger('sale_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('sales', 'quotation_id')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->unsignedBigInteger('quotation_id')->nullable()->index();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('quotations', 'sale_id')) {
            Schema::table('quotations', function (Blueprint $t) {
                $t->dropIndex(['sale_id']);
                $t->dropColumn('sale_id');
            });
        }
        if (Schema::hasColumn('sales', 'quotation_id')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->dropIndex(['quotation_id']);
                $t->dropColumn('quotation_id');
            });
        }
    }
}
