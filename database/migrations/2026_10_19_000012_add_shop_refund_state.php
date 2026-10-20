<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShopRefundState extends Migration
{
    public function up()
    {
        Schema::table('shop_payment_sessions', function (Blueprint $table) {
            $table->string('refund_id', 100)->nullable();
            $table->string('refund_status', 30)->nullable();
        });
    }

    public function down()
    {
        Schema::table('shop_payment_sessions', function (Blueprint $table) {
            $table->dropColumn(['refund_id', 'refund_status']);
        });
    }
}
