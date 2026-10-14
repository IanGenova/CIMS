<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch / lot and expiry tracking.
 *
 * Stock was a single number per product per warehouse, with no way to say
 * which lot it came from or when it expires. That makes recall, FEFO picking
 * and expiry control impossible.
 *
 * Design constraints that shaped this:
 *
 *  - Tracking is OPT-IN per product (products.has_batches). A product that
 *    does not opt in behaves exactly as before, so the 172 existing stock
 *    write sites across nine controllers keep working untouched.
 *
 *  - product_warehouse.qte remains the authoritative total. Every existing
 *    report, dashboard query and stock check reads it and must keep working.
 *    batch_stock sits underneath and is kept summing to it.
 *
 *  - batch_movements is an append-only ledger. It gives traceability (which
 *    sale consumed which lot) and makes reversal exact: undoing a document
 *    replays its own movements rather than guessing.
 */
class CreateBatchTrackingTables extends Migration
{
    public function up()
    {
        // The lot itself: identity, dates, supplier provenance.
        Schema::create('product_batches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('product_id')->index('pb_product_id');
            $table->integer('product_variant_id')->nullable();

            $table->string('batch_number', 100);
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable()->index('pb_expiry_date');

            // Where it came from, for recall.
            $table->integer('provider_id')->nullable();
            $table->decimal('cost', 20, 4)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One lot number per product. Variants of the same product share
            // the namespace, so the variant is part of the key.
            $table->unique(['product_id', 'product_variant_id', 'batch_number'], 'pb_unique_lot');
        });

        // Quantity of a lot held in a warehouse. Mirrors product_warehouse
        // one level down.
        Schema::create('batch_stock', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('product_batch_id')->index('bs_batch_id');
            $table->integer('warehouse_id')->index('bs_warehouse_id');
            $table->decimal('qte', 20, 6)->default(0);
            $table->timestamps();

            $table->unique(['product_batch_id', 'warehouse_id'], 'bs_unique_batch_warehouse');

            $table->foreign('product_batch_id', 'bs_batch_fk')
                  ->references('id')->on('product_batches')
                  ->onDelete('CASCADE');
        });

        // Append-only ledger. qte is signed: positive in, negative out.
        Schema::create('batch_movements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedInteger('product_batch_id')->index('bm_batch_id');
            $table->integer('warehouse_id')->index('bm_warehouse_id');

            $table->decimal('qte', 20, 6);
            $table->string('movement_type', 30)->index();   // receipt|sale|pos|return|adjustment|reversal

            // What caused it, so a document can replay and reverse its own.
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->integer('user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['reference_type', 'reference_id'], 'bm_reference_index');

            $table->foreign('product_batch_id', 'bm_batch_fk')
                  ->references('id')->on('product_batches')
                  ->onDelete('CASCADE');
        });

        Schema::table('products', function (Blueprint $table) {
            // Opt-in. Everything below only engages when this is on.
            $table->boolean('has_batches')->default(0)->after('is_variant')->index();
            // Block sale of stock within N days of expiry. Null = only block
            // once actually expired.
            $table->integer('expiry_alert_days')->nullable()->default(30)->after('has_batches');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['has_batches', 'expiry_alert_days']);
        });

        Schema::dropIfExists('batch_movements');
        Schema::dropIfExists('batch_stock');
        Schema::dropIfExists('product_batches');
    }
}
