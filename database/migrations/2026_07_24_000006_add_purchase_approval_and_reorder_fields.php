<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase approval and reorder planning.
 *
 * Approval: any user holding the purchase permission could previously commit
 * inventory and supplier liability with no second signature. Purchases at or
 * above a configurable threshold now require approval before they can be
 * received.
 *
 * Reorder: products carried a stock_alert threshold but nothing acted on it.
 * These fields let the system propose what to buy and from whom.
 */
class AddPurchaseApprovalAndReorderFields extends Migration
{
    public function up()
    {
        Schema::table('purchases', function (Blueprint $table) {
            // not_required | pending | approved | rejected
            $table->string('approval_status', 20)->default('not_required')->after('statut')->index();
            $table->integer('approved_by')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approved_at');
        });

        Schema::table('products', function (Blueprint $table) {
            // How much to order when stock reaches stock_alert.
            $table->decimal('reorder_qte', 20, 6)->nullable()->default(0)->after('stock_alert');
            // Who to buy it from by default.
            $table->integer('preferred_provider_id')->nullable()->after('reorder_qte');
        });

        Schema::table('settings', function (Blueprint $table) {
            // Purchases at or above this total need approval. 0 = always
            // require approval; null = approval disabled entirely.
            $table->decimal('purchase_approval_threshold', 20, 4)->nullable()->after('warehouse_id');
        });
    }

    public function down()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at', 'rejection_reason']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['reorder_qte', 'preferred_provider_id']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('purchase_approval_threshold');
        });
    }
}
