<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing a purchase order that will never be filled.
 *
 * A purchase's state was derived purely from ordered against received, so a
 * line short by a rejection, a discontinued product or a supplier who stopped
 * answering kept the order open for ever. It sat in the goods-awaiting queue
 * indefinitely, and every figure built on that queue counted stock nobody was
 * ever going to deliver.
 *
 * Closing short does not rewrite what was received -- the line still says 60 of
 * 100, because that is what happened. It records a decision: the balance is not
 * coming, this is who decided that, and this is why. Keeping the reason is the
 * point; an order that simply vanished from the queue would be worse than one
 * that never left it.
 */
class AddShortCloseToPurchases extends Migration
{
    public function up()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->timestamp('short_closed_at')->nullable()->after('rejection_reason');
            $table->integer('short_closed_by')->nullable()->after('short_closed_at');
            $table->string('short_close_reason', 255)->nullable()->after('short_closed_by');
        });
    }

    public function down()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['short_closed_at', 'short_closed_by', 'short_close_reason']);
        });
    }
}
