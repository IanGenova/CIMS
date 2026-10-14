<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connecting a Shopee or Lazada shop.
 *
 * The accounts table already had somewhere to keep the tokens; nothing could
 * ever fill it. This adds what the connect step needs: a single-use, expiring
 * state for each Connect press (only its hash is stored, bound to the shop and
 * to the person who pressed it), and on the account, when the renewal token
 * runs out and why the last connect or renewal failed.
 */
class AddMarketplaceConnect extends Migration
{
    public function up()
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->timestamp('refresh_expires_at')->nullable()->after('token_expires_at');
            $table->timestamp('connected_at')->nullable()->after('refresh_expires_at');
            $table->string('auth_error', 255)->nullable()->after('connected_at');
        });

        Schema::create('marketplace_auth_states', function (Blueprint $table) {
            $table->increments('id');
            $table->char('state_hash', 64)->unique();
            $table->integer('marketplace_account_id');
            $table->integer('user_id');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketplace_auth_states');

        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn(['refresh_expires_at', 'connected_at', 'auth_error']);
        });
    }
}
