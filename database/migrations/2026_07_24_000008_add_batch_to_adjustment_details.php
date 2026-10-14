<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An adjustment on a batch-tracked product names the lot it affects.
 *
 * Adding stock has to say which lot it belongs to, and writing stock off is
 * usually about one specific lot — damaged, spoiled, or expired. Transfers do
 * not need this: the lots are chosen FEFO and recorded in batch_movements.
 */
class AddBatchToAdjustmentDetails extends Migration
{
    public function up()
    {
        Schema::table('adjustment_details', function (Blueprint $table) {
            $table->unsignedInteger('product_batch_id')->nullable()->after('product_variant_id');
            $table->index('product_batch_id', 'ad_batch_id');
        });
    }

    public function down()
    {
        Schema::table('adjustment_details', function (Blueprint $table) {
            $table->dropIndex('ad_batch_id');
            $table->dropColumn('product_batch_id');
        });
    }
}
