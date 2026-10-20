<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWebOrderFulfillmentTracking extends Migration
{
    public function up()
    {
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->string('fulfillment_method', 24)->default('delivery');
            $table->string('fulfillment_status', 24)->nullable();
            $table->string('shipping_carrier', 120)->nullable();
            $table->string('tracking_reference', 120)->nullable();
            $table->timestamp('delivered_at')->nullable();
        });
        Schema::create('customer_order_fulfillment_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_order_id')->index();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('shipping_carrier', 120)->nullable();
            $table->string('tracking_reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_order_fulfillment_events');
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_method', 'fulfillment_status',
                'shipping_carrier', 'tracking_reference', 'delivered_at']);
        });
    }
}
