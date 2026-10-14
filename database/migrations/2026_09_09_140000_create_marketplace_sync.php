<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling somewhere else, and having the stockroom know about it.
 *
 * Three tables, because there are three separate problems and conflating them is
 * how marketplace integrations usually go wrong.
 *
 * An ACCOUNT is a shop on a marketplace, with its own tokens. A seller may run
 * more than one, and Shopee's tokens expire every few hours, so the tokens
 * belong to the account rather than to the application.
 *
 * A LISTING is the join between something on the marketplace and something on
 * our shelf. Without it an imported order is a row of text with no product
 * behind it, and this mapping is the piece a person has to get right -- so it is
 * a table they can see and correct, not a guess made at import time.
 *
 * An ORDER is what the marketplace says happened, kept exactly as it arrived and
 * separate from the sale it may become. Polling sees the same order again and
 * again; keeping it here with the marketplace's own id is what makes importing
 * it twice impossible rather than merely unlikely.
 */
class CreateMarketplaceSync extends Migration
{
    public function up()
    {
        Schema::create('marketplace_accounts', function (Blueprint $table) {
            $table->increments('id');

            $table->string('channel', 16);                  // shopee | lazada
            $table->string('name', 128);
            $table->string('external_shop_id', 64)->nullable();
            $table->string('region', 8)->default('PH');

            // A seller authorises the app; these are the result. Shopee's access
            // token lasts hours, so the expiry is checked before every call
            // rather than discovered by a failure.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // Orders are pulled from here forward. Kept per account so adding a
            // second shop does not re-import the first one's history.
            $table->timestamp('orders_synced_at')->nullable();

            $table->integer('warehouse_id')->nullable();
            $table->integer('client_id')->nullable();       // who marketplace sales are billed to
            $table->boolean('is_active')->default(1);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['channel', 'external_shop_id'], 'marketplace_account_unique');
        });

        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('marketplace_account_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();

            // What the marketplace calls it. The SKU is what an order line
            // carries, so it is what the mapping is looked up by.
            $table->string('external_item_id', 64)->nullable();
            $table->string('external_sku', 128);

            // What was last pushed, so a push that would change nothing can be
            // skipped -- these APIs are rate limited and most pushes are noise.
            $table->decimal('last_pushed_qty', 20, 4)->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_error', 255)->nullable();

            $table->boolean('push_stock')->default(1);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['marketplace_account_id', 'external_sku'], 'marketplace_listing_unique');
            $table->index('product_id');
        });

        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('marketplace_account_id');

            // Theirs. Unique per account, and the whole reason an order cannot
            // be imported twice however many times polling sees it.
            $table->string('external_order_id', 96);

            $table->string('external_status', 32)->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->decimal('total', 20, 4)->default(0);
            $table->string('currency', 8)->default('PHP');
            $table->string('buyer_name', 192)->nullable();

            // pending | imported | unmapped | ignored | failed | cancelled
            $table->string('state', 16)->default('pending');
            $table->string('state_reason', 255)->nullable();

            // The sale it became, if it became one.
            $table->integer('sale_id')->nullable()->index();

            // Exactly what the marketplace sent. When a customer disputes an
            // order months later this is the only account of it that is not a
            // reconstruction.
            $table->longText('raw')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['marketplace_account_id', 'external_order_id'], 'marketplace_order_unique');
            $table->index(['state', 'ordered_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketplace_orders');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_accounts');
    }
}
