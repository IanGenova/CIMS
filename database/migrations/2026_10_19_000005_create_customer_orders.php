<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomerOrders extends Migration
{
    public function up()
    {
        Schema::create('customer_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('Ref', 40)->unique();
            $table->integer('client_id');
            $table->integer('warehouse_id')->index();
            $table->string('source_channel', 30)->default('staff');
            $table->string('external_order_id', 100)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->string('status', 24)->default('pending_stock');
            $table->date('expected_supply_date')->nullable();
            $table->date('promise_date')->nullable();
            $table->decimal('deposit_amount', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('sale_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['source_channel', 'external_order_id'], 'customer_order_external_unique');
        });
        Schema::create('customer_order_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_order_id')->index();
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 20, 6); // base units
            $table->string('status', 24)->default('pending_stock');
            $table->timestamps();
        });
        Schema::table('sale_details', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_order_line_id')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('customer_order_line_id');
        });
        Schema::dropIfExists('customer_order_lines');
        Schema::dropIfExists('customer_orders');
    }
}
