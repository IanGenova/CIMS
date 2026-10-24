<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShopPaymentSessions extends Migration
{
    public function up()
    {
        Schema::create('shop_payment_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_order_id')->index();
            $table->string('reference_id', 64)->unique();
            $table->string('session_id', 100)->nullable()->unique();
            $table->string('payment_link_url', 500)->nullable();
            $table->decimal('amount', 20, 2);
            $table->string('status', 24)->default('creating');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('shop_payment_sessions');
    }
}
