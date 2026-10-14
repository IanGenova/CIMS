<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer credit control, and per-supplier cost and lead time.
 *
 * Sales on account could previously run unbounded: there was no ceiling, no
 * opening balance to carry a migrated debt, and no ageing. And cost was a
 * single field on the product, so two suppliers for the same item could not
 * be compared and reordering had no lead time to plan against.
 */
class CreateCreditAndSupplierPricing extends Migration
{
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // 0 means "no ceiling", which is how every existing customer starts.
            $table->decimal('credit_limit', 20, 4)->default(0)->after('tax_number');
            // A debt carried in from whatever system this replaced.
            $table->decimal('opening_balance', 20, 4)->default(0)->after('credit_limit');
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->increments('id');
            // providers.id and products.id are signed ints in this schema.
            $table->integer('provider_id');
            $table->integer('product_id');
            $table->string('supplier_sku', 192)->nullable();
            $table->decimal('cost', 20, 4)->default(0);
            $table->integer('lead_time_days')->default(0);
            $table->decimal('min_order_qty', 20, 6)->default(0);
            // The supplier to draw on when reordering this product.
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('provider_id')->references('id')->on('providers')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['provider_id', 'product_id'], 'supplier_product_unique');
            $table->index('product_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('supplier_products');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'opening_balance']);
        });
    }
}
