<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A movement record for every change to stock, and a cost that follows the goods.
 *
 * Until now only batch-tracked products left a trail. Everything else was a bare
 * number on product_warehouse that went up and down with nothing recorded about
 * why, so "why is stock 18?" could only be answered by reconstructing it from
 * sales, purchases, adjustments, transfers and returns.
 *
 * The same table also carries cost. products.cost was a figure somebody typed
 * once that no purchase ever updated, and cost of goods sold was quantity times
 * that stale number -- so margins drifted further from reality with every price
 * change. It now holds a moving weighted average, recomputed whenever goods are
 * received at a known cost, and every movement records the cost it moved at.
 */
class CreateStockMovements extends Migration
{
    public function up()
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('product_id')->index();
            $table->integer('warehouse_id')->index();
            $table->integer('product_variant_id')->nullable();

            // purchase | goods_receipt | sale | pos | sale_return | purchase_return
            // adjustment | transfer_in | transfer_out | stock_count | production
            // opening | unknown
            $table->string('movement_type', 30)->index();

            // Signed: positive into stock, negative out of it.
            $table->decimal('quantity', 20, 6);
            // What the balance became, so a line can be read without summing.
            $table->decimal('balance_after', 20, 6);

            // The cost this movement moved at -- the average at the time for an
            // issue, the purchase cost for a receipt. Recorded here so the
            // valuation of a past movement never changes when the average moves.
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();

            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->integer('user_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'id'], 'sm_product_warehouse_id');
            $table->index(['reference_type', 'reference_id'], 'sm_reference');
            $table->index('created_at');
        });

        Schema::table('products', function (Blueprint $table) {
            // When the moving average last changed, so a stale cost is visible
            // rather than merely wrong.
            $table->timestamp('cost_updated_at')->nullable()->after('cost');
        });

        // The ledger has to start somewhere. Record what is on hand now as an
        // opening balance, so every later movement has a line above it and the
        // running balance reconciles from the first row rather than from thin air.
        if (Schema::hasTable('product_warehouse')) {
            $rows = DB::table('product_warehouse')->whereNull('deleted_at')->get();
            $now = now();

            foreach ($rows as $r) {
                if ((float) $r->qte == 0.0) {
                    continue;
                }

                $cost = DB::table('products')->where('id', $r->product_id)->value('cost');

                DB::table('stock_movements')->insert([
                    'product_id' => $r->product_id,
                    'warehouse_id' => $r->warehouse_id,
                    'product_variant_id' => $r->product_variant_id,
                    'movement_type' => 'opening',
                    'quantity' => $r->qte,
                    'balance_after' => $r->qte,
                    'unit_cost' => $cost,
                    'total_cost' => round((float) $cost * (float) $r->qte, 6),
                    'reference_type' => null,
                    'reference_id' => null,
                    'user_id' => null,
                    'notes' => 'Opening balance recorded when movement tracking was switched on.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('stock_movements');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cost_updated_at');
        });
    }
}
