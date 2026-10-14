<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money taken through a wallet, before it is money on a sale.
 *
 * A card payment is settled by the time the till hears about it. A wallet payment
 * is not: the till asks the provider to create a charge, shows the customer
 * something to scan, and then waits -- for a callback, a poll, or neither, if the
 * customer changes their mind and walks away. That waiting has to live somewhere,
 * and it cannot live on payment_sales, because a row there means the sale has
 * been paid.
 *
 * So a wallet payment is its own thing with its own life -- pending, paid,
 * expired, failed, mismatched -- and only becomes a payment_sales row at the
 * moment the provider confirms the money arrived. Until then it is a question,
 * not an answer.
 */
class CreateWalletPayments extends Migration
{
    public function up()
    {
        Schema::create('wallet_payments', function (Blueprint $table) {
            $table->increments('id');

            // Which provider and which wallet, so a shop can change provider
            // without the history becoming ambiguous.
            $table->string('provider', 32)->default('xendit');
            $table->string('channel', 32);                 // GCASH, PAYMAYA

            // Ours, and unguessable. It is the only identifier a callback can
            // offer us, so it must not be possible to enumerate.
            $table->string('reference_id', 64)->unique();

            // Theirs, once they have issued one.
            $table->string('provider_charge_id', 128)->nullable()->index();

            $table->decimal('amount', 20, 4);
            $table->string('currency', 8)->default('PHP');

            // pending | paid | failed | expired | cancelled | mismatch
            $table->string('status', 16)->default('pending');

            // What the customer is shown: a QR string or a checkout URL.
            $table->string('action_type', 32)->nullable();
            $table->text('action_value')->nullable();

            // Where it belongs, once it belongs anywhere.
            $table->integer('sale_id')->nullable()->index();
            $table->unsignedInteger('till_session_id')->nullable()->index();
            $table->integer('warehouse_id')->nullable();
            $table->integer('user_id')->nullable();

            // The moment it became money, and the moment it stopped being
            // offerable. Separate columns rather than derived from status,
            // because "when" is asked as often as "whether".
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // What the provider actually said, kept verbatim. When a payment is
            // disputed weeks later this is the only account of it that is not a
            // reconstruction.
            $table->text('last_response')->nullable();
            $table->string('failure_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallet_payments');
    }
}
