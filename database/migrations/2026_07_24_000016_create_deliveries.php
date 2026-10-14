<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch and proof of delivery.
 *
 * A sale was complete the moment it was saved -- there was no dispatch step,
 * no courier, no tracking number and nothing recording that the goods actually
 * arrived. A delivery is a separate document against a sale so the two states
 * ("invoiced" and "delivered") can differ, which is the normal case.
 *
 * Stock is NOT moved here. The sale already moved it; a delivery records the
 * physical handover. Double-decrementing would corrupt the ledger.
 */
class CreateDeliveries extends Migration
{
    public function up()
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Ref', 192);
            $table->integer('sale_id');
            $table->integer('client_id');
            $table->integer('warehouse_id')->nullable();
            $table->date('date');
            // pending | dispatched | in_transit | delivered | failed | returned
            $table->string('status', 32)->default('pending');
            $table->string('courier', 192)->nullable();
            $table->string('tracking_number', 192)->nullable();
            $table->text('shipping_address')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            // Proof of delivery: who signed for it.
            $table->string('received_by', 192)->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('notes')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->unique('Ref');
            $table->index(['status', 'date']);
            $table->index('sale_id');
        });

        Schema::create('delivery_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('delivery_id');
            $table->integer('sale_detail_id')->nullable();
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            // Partial shipments: this can be less than the line was sold for.
            $table->decimal('quantity', 20, 6)->default(0);
            $table->timestamps();

            $table->foreign('delivery_id')->references('id')->on('deliveries')->onDelete('cascade');
            $table->index('product_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('delivery_details');
        Schema::dropIfExists('deliveries');
    }
}
