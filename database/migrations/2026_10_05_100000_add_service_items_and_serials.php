<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of product a general shop needs (App\utils\ProductKind, SerialService).
 *
 *   is_service       labour, repairs, installation, delivery: sold and priced
 *                    like a product, but never stocked -- its shelf count
 *                    never moves and it is never "out of stock"
 *   has_serials      appliances, electronics, phones: every unit carries its
 *                    own serial number (or IMEI) from receiving to sale, so a
 *                    warranty claim can be traced to the sale it came from
 *   warranty_months  how long a sold serial is covered
 */
class AddServiceItemsAndSerials extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_service')->default(false)->index();
            $table->boolean('has_serials')->default(false);
            $table->unsignedSmallInteger('warranty_months')->nullable();
        });

        Schema::create('product_serials', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable();
            $table->string('serial_number', 100);
            // in_stock, sold, written_off, returned_to_supplier
            $table->string('status', 24)->default('in_stock')->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->timestamp('received_at')->nullable();
            $table->integer('sale_id')->nullable()->index();
            $table->integer('client_id')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->date('warranty_until')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'serial_number']);
        });

        // A serial's life, for the lookup screen and for warranty claims.
        Schema::create('product_serial_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('product_serial_id')->index();
            // received, sold, returned, released, transferred, written_off, returned_to_supplier
            $table->string('event', 24);
            $table->integer('warehouse_id')->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->integer('reference_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_serial_events');
        Schema::dropIfExists('product_serials');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_service', 'has_serials', 'warranty_months']);
        });
    }
}
