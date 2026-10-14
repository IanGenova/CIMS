<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bin / rack locations inside a warehouse.
 *
 * A warehouse was a flat container: stock was "in warehouse 2" and nothing
 * more, so there was no pick path and no way to say which rack an item sits on.
 *
 * The design deliberately makes location_stock a SUB-ALLOCATION of
 * product_warehouse.qte rather than a replacement for it. product_warehouse
 * stays the authority on how much exists -- every existing report, the batch
 * ledger and every stock movement path keep working untouched. What is put
 * away into bins is tracked alongside, and whatever has not been put away yet
 * is reported plainly as unallocated rather than being hidden.
 *
 * Opt-in per warehouse, the same way batch tracking is opt-in per product.
 */
class CreateWarehouseLocations extends Migration
{
    public function up()
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('uses_locations')->default(false)->after('country');
        });

        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('warehouse_id');
            // The label a picker reads off the rack, e.g. "A-01-3".
            $table->string('code', 64);
            $table->string('zone', 64)->nullable();
            $table->string('aisle', 64)->nullable();
            $table->string('rack', 64)->nullable();
            $table->string('bin', 64)->nullable();
            // Where put-away goes when nobody chooses a bin.
            $table->boolean('is_receiving')->default(false);
            $table->boolean('is_active')->default(true);
            // Drives pick order; lower is walked first.
            $table->integer('pick_sequence')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->onDelete('cascade');
            $table->unique(['warehouse_id', 'code'], 'wh_location_code_unique');
            $table->index(['warehouse_id', 'pick_sequence']);
        });

        Schema::create('location_stock', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('location_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id');
            $table->decimal('qte', 20, 6)->default(0);
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('warehouse_locations')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['location_id', 'product_id', 'product_variant_id'], 'location_stock_unique');
            $table->index(['warehouse_id', 'product_id']);
        });

        Schema::create('location_movements', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('from_location_id')->nullable();
            $table->unsignedInteger('to_location_id')->nullable();
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id');
            $table->decimal('qte', 20, 6);
            // putaway | move | pick | adjust
            $table->string('reason', 32);
            $table->integer('user_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id']);
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('location_movements');
        Schema::dropIfExists('location_stock');
        Schema::dropIfExists('warehouse_locations');
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('uses_locations');
        });
    }
}
