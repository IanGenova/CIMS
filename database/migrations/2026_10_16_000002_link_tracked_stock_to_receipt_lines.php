<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class LinkTrackedStockToReceiptLines extends Migration
{
    public function up()
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropUnique('pb_unique_lot');
            $table->unsignedInteger('source_receipt_detail_id')->nullable()->unique('pb_source_receipt_detail_unique');
            $table->index(['product_id', 'product_variant_id', 'batch_number'], 'pb_lot_lookup');
        });

        Schema::table('product_serials', function (Blueprint $table) {
            $table->unsignedInteger('source_receipt_detail_id')->nullable()->index('ps_source_receipt_detail_index');
        });
    }

    public function down()
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->dropIndex('ps_source_receipt_detail_index');
            $table->dropColumn('source_receipt_detail_id');
        });

        // Multiple receipts may now carry the same printed lot number. A
        // rollback must retain their distinct stock identities rather than
        // recreate the old uniqueness constraint and merge their histories.
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropIndex('pb_lot_lookup');
            $table->dropUnique('pb_source_receipt_detail_unique');
            $table->dropColumn('source_receipt_detail_id');
        });
    }
}
