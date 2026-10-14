<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods receipt (GRN).
 *
 * A purchase saved as "received" still writes stock immediately, so existing
 * behaviour is untouched. What was missing is the ability to raise a purchase
 * as "ordered", then receive it — in full or in part, possibly over several
 * deliveries — with a record of what actually arrived versus what was ordered.
 *
 * purchase_details.received_qte tracks the running total received so a second
 * receipt cannot over-receive the line.
 */
class CreateGoodsReceiptsTables extends Migration
{
    public function up()
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('Ref', 192)->unique();
            $table->date('date');
            $table->integer('purchase_id')->index('grn_purchase_id');
            $table->integer('warehouse_id')->index('grn_warehouse_id');
            $table->integer('user_id')->index('grn_user_id');

            // draft -> received (or cancelled). Stock moves on "received".
            $table->string('status', 20)->default('draft')->index();

            // Supplier's own delivery note number, for reconciliation.
            $table->string('delivery_note', 192)->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('goods_receipt_details', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('goods_receipt_id')->index('grd_receipt_id');
            $table->integer('purchase_detail_id')->index('grd_purchase_detail_id');
            $table->integer('product_id')->index('grd_product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('purchase_unit_id')->nullable();

            $table->decimal('ordered_qte', 20, 6)->default(0);
            $table->decimal('received_qte', 20, 6)->default(0);
            // Delivered but refused (damaged, wrong item). Never enters stock.
            $table->decimal('rejected_qte', 20, 6)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('goods_receipt_details', function (Blueprint $table) {
            $table->foreign('goods_receipt_id', 'grd_receipt_fk')
                  ->references('id')->on('goods_receipts')
                  ->onDelete('CASCADE');
        });

        // Running total received against each ordered line.
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->decimal('received_qte', 20, 6)->default(0)->after('quantity');
        });
    }

    public function down()
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropColumn('received_qte');
        });

        Schema::dropIfExists('goods_receipt_details');
        Schema::dropIfExists('goods_receipts');
    }
}
