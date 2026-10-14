<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch columns for the last three movement paths.
 *
 * A stock count of a batch-tracked product is counted per lot, not per
 * product: "42 units on the shelf" is not an auditable statement when three
 * lots with different expiry dates are sitting there. So a count line points
 * at a lot.
 *
 * Returns name a lot too. Goods coming back from a customer carry a lot number
 * on the packaging; goods going back to a supplier are usually one specific
 * defective lot.
 */
class AddBatchToCountsAndReturns extends Migration
{
    public function up()
    {
        Schema::table('stock_count_details', function (Blueprint $table) {
            $table->unsignedInteger('product_batch_id')->nullable()->after('product_variant_id');
            $table->index('product_batch_id', 'scd_batch_id');
        });

        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->unsignedInteger('product_batch_id')->nullable()->after('product_variant_id');
            $table->index('product_batch_id', 'srd_batch_id');
        });

        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->unsignedInteger('product_batch_id')->nullable()->after('product_variant_id');
            $table->index('product_batch_id', 'prd_batch_id');
        });
    }

    public function down()
    {
        Schema::table('stock_count_details', function (Blueprint $table) {
            $table->dropIndex('scd_batch_id');
            $table->dropColumn('product_batch_id');
        });

        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->dropIndex('srd_batch_id');
            $table->dropColumn('product_batch_id');
        });

        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->dropIndex('prd_batch_id');
            $table->dropColumn('product_batch_id');
        });
    }
}
