<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock take / cycle count.
 *
 * Previously the only way to correct a count was a manual Adjustment, which
 * records the correction but not what was counted, by whom, or against what
 * the system believed at the time. A stock count snapshots the system
 * quantity when it is opened, captures the physical count, and applies the
 * variance in one reviewable operation.
 */
class CreateStockCountsTables extends Migration
{
    public function up()
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->string('Ref', 192)->unique();
            $table->date('date');
            $table->integer('warehouse_id')->index('stock_counts_warehouse_id');
            $table->integer('user_id')->index('stock_counts_user_id');

            // draft -> in_progress -> completed (or cancelled)
            $table->string('status', 20)->default('draft')->index();

            // Whole warehouse, or narrowed to one category.
            $table->integer('category_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('completed_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('stock_count_details', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('stock_count_id')->index('scd_stock_count_id');
            $table->integer('product_id')->index('scd_product_id');
            $table->integer('product_variant_id')->nullable();

            // What the system believed when the count was opened.
            $table->decimal('system_qte', 20, 6)->default(0);
            // What was physically counted. Null until someone counts it.
            $table->decimal('counted_qte', 20, 6)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_count_details', function (Blueprint $table) {
            $table->foreign('stock_count_id', 'scd_stock_count_fk')
                  ->references('id')->on('stock_counts')
                  ->onDelete('CASCADE');
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_count_details');
        Schema::dropIfExists('stock_counts');
    }
}
