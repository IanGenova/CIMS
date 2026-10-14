<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An idempotency key belongs to the user who made it (DATATEST K66).
 *
 * Keys were global: the owner's key swallowed a Branch cashier's offline sale
 * as a "duplicate", and the status call told one user about another's sales.
 * The same table now also carries the keys of online POS and back-office
 * sales (K40), so a lost reply plus a retry cannot ring a sale twice.
 */
class ScopeSaleKeysByUser extends Migration
{
    public function up()
    {
        Schema::table('offline_sale_keys', function (Blueprint $t) {
            $t->dropUnique('offline_sale_keys_idempotency_key_unique');
            $t->unique(['user_id', 'idempotency_key'], 'offline_sale_keys_user_key_unique');
        });
    }

    public function down()
    {
        Schema::table('offline_sale_keys', function (Blueprint $t) {
            $t->dropUnique('offline_sale_keys_user_key_unique');
            $t->unique('idempotency_key', 'offline_sale_keys_idempotency_key_unique');
        });
    }
}
