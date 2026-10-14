<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer groups and price lists.
 *
 * There is one price per product today, so wholesale and retail cannot be
 * told apart. A customer group carries an optional blanket discount; a price
 * list holds per-product overrides, optionally banded by quantity so volume
 * breaks work.
 *
 * Resolution order at sale time, first match wins:
 *   1. price list row for the product with min_quantity <= qty  (highest band)
 *   2. customer group default discount off the list price
 *   3. products.price
 */
class CreateCustomerGroupsAndPriceLists extends Migration
{
    public function up()
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 192);
            $table->decimal('default_discount_percent', 20, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 192);
            $table->unsignedInteger('customer_group_id')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_group_id')->references('id')->on('customer_groups')->onDelete('cascade');
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('price_list_id');
            // products.id is a signed int in this schema, so the FK column must match.
            $table->integer('product_id');
            // The band this row applies from. 0 = any quantity.
            $table->decimal('min_quantity', 20, 6)->default(0);
            // Exactly one of these is used: a fixed price wins over a discount.
            $table->decimal('price', 20, 4)->nullable();
            $table->decimal('discount_percent', 20, 4)->nullable();
            $table->timestamps();

            $table->foreign('price_list_id')->references('id')->on('price_lists')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->index(['price_list_id', 'product_id', 'min_quantity'], 'pli_lookup');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('customer_group_id')->nullable()->after('adresse');
            $table->foreign('customer_group_id')->references('id')->on('customer_groups')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['customer_group_id']);
            $table->dropColumn('customer_group_id');
        });
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('customer_groups');
    }
}
