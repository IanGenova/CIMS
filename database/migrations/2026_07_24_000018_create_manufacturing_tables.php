<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bills of material and production orders.
 *
 * The system could only buy and sell: there was no way to say that one product
 * is assembled from others, so a kit or an assembly had to be faked with
 * adjustments that left no trace of what was consumed.
 *
 * Completing a production order consumes components and produces the finished
 * good through the same stock paths everything else uses, so batch-tracked
 * components are picked oldest-expiry-first and the ledger stays exact.
 */
class CreateManufacturingTables extends Migration
{
    public function up()
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 192);
            // The finished good this recipe produces.
            $table->integer('product_id');
            // How many finished units one run of the recipe yields.
            $table->decimal('output_quantity', 20, 6)->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->index('product_id');
        });

        Schema::create('bom_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('bom_id');
            $table->integer('product_id');
            $table->decimal('quantity', 20, 6)->default(0);
            // Expected loss, added on top of the nominal quantity consumed.
            $table->decimal('waste_percent', 20, 4)->default(0);
            $table->timestamps();

            $table->foreign('bom_id')->references('id')->on('boms')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['bom_id', 'product_id'], 'bom_item_unique');
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Ref', 192);
            $table->unsignedInteger('bom_id');
            $table->integer('product_id');
            $table->integer('warehouse_id');
            $table->date('date');
            // How many finished units this order is for.
            $table->decimal('quantity', 20, 6)->default(0);
            // planned | in_progress | completed | cancelled
            $table->string('status', 32)->default('planned');
            // What the consumed components actually cost, stamped at completion.
            $table->decimal('total_cost', 20, 4)->default(0);
            $table->decimal('unit_cost', 20, 4)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('bom_id')->references('id')->on('boms');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->unique('Ref');
            $table->index(['status', 'date']);
        });

        // What a completed order actually consumed, recorded per component so a
        // later cost query does not have to re-derive it from the recipe.
        Schema::create('production_consumptions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('production_order_id');
            $table->integer('product_id');
            $table->decimal('quantity', 20, 6)->default(0);
            $table->decimal('unit_cost', 20, 4)->default(0);
            $table->decimal('total_cost', 20, 4)->default(0);
            $table->timestamps();

            $table->foreign('production_order_id')->references('id')->on('production_orders')->onDelete('cascade');
            $table->index('product_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('production_consumptions');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('boms');
    }
}
