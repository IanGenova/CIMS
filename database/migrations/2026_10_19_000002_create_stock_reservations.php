<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStockReservations extends Migration
{
    public function up()
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('owner_type', 40);
            $table->unsignedBigInteger('owner_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id');
            $table->unsignedInteger('product_batch_id')->nullable();
            $table->unsignedInteger('product_serial_id')->nullable();
            $table->decimal('quantity', 20, 6);
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 100)->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'stock_reservation_owner');
            $table->index(['warehouse_id', 'product_id', 'product_variant_id', 'status'], 'stock_reservation_stock');
            $table->index(['product_batch_id', 'status'], 'stock_reservation_batch');
            $table->index(['product_serial_id', 'status'], 'stock_reservation_serial');
            $table->index(['status', 'expires_at'], 'stock_reservation_expiry');
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_reservations');
    }
}
