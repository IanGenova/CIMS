<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Xendit replaces Stripe as the payment gateway (App\utils\XenditCheckout).
 *
 * The keys live in the settings row, encrypted with the app key, instead of
 * being written into .env: the old Stripe screen rewrote .env on every save
 * and left the config cache frozen behind it.
 *
 * A checkout is one request for money on one sale. It becomes a payment only
 * once Xendit says the money moved.
 */
class AddXenditPayments extends Migration
{
    public function up()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('xendit_secret_key')->nullable();
            $table->text('xendit_webhook_token')->nullable();
        });

        Schema::create('xendit_checkouts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->integer('sale_id')->index();
            $table->decimal('amount', 12, 2);
            $table->string('reference_id', 64)->unique();
            $table->string('session_id', 64)->nullable()->unique();
            $table->text('payment_link_url')->nullable();
            // pending, paid, expired, cancelled
            $table->string('status', 20)->default('pending')->index();
            $table->string('payment_id', 64)->nullable()->index();
            // What a refund is made against.
            $table->string('payment_request_id', 64)->nullable();
            $table->string('channel', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->integer('payment_sale_id')->nullable()->index();
            $table->string('refund_id', 64)->nullable();
            $table->string('refund_status', 20)->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('xendit_checkouts');
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['xendit_secret_key', 'xendit_webhook_token']);
        });
    }
}
