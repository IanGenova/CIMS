<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStorefrontFields extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('web_published')->default(false)->index();
        });
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('shop_enabled')->default(false);
            $table->integer('shop_warehouse_id')->nullable();
            $table->string('shop_name', 120)->nullable();
        });
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->char('public_token_hash', 64)->nullable()->unique();
            $table->decimal('quoted_total', 20, 2)->nullable();
            $table->string('checkout_payment_method', 24)->nullable();
        });
        Schema::table('customer_order_lines', function (Blueprint $table) {
            $table->decimal('unit_price', 20, 4)->nullable();
        });
    }

    public function down()
    {
        Schema::table('customer_order_lines', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->dropColumn(['public_token_hash', 'quoted_total', 'checkout_payment_method']);
        });
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['shop_enabled', 'shop_warehouse_id', 'shop_name']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('web_published');
        });
    }
}
