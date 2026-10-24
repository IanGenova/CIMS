<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendShopPaymentReconciliation extends Migration
{
    public function up()
    {
        Schema::table('shop_payment_sessions', function (Blueprint $table) {
            $table->string('payment_id', 100)->nullable();
            $table->string('payment_request_id', 100)->nullable();
            $table->integer('payment_sale_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('shop_payment_sessions', function (Blueprint $table) {
            $table->dropColumn(['payment_id', 'payment_request_id', 'payment_sale_id']);
        });
    }
}
