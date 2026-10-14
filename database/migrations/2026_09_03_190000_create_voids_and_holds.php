<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voids, and baskets put to one side.
 *
 * A void is not a delete. The sale stays, marked, with a reason and the person
 * who authorised it -- precisely so the gap in the invoice numbers is explained
 * rather than merely present. A registered till has to be able to show why
 * SL_1109 exists and SL_1110 does not sit between two live invoices.
 *
 * A hold is the opposite: nothing has happened yet. No invoice number is spent,
 * no stock moves, no money is taken. It is a basket parked on the counter while
 * the customer goes back for the thing they forgot, so it is stored as the cart
 * itself rather than as a half-made sale.
 */
class CreateVoidsAndHolds extends Migration
{
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('statut');
                $table->integer('voided_by')->nullable()->after('voided_at');
                $table->string('void_reason', 255)->nullable()->after('voided_by');
                $table->index('voided_at');
            }
        });

        Schema::create('pos_holds', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('user_id');
            $table->integer('warehouse_id');
            $table->unsignedInteger('till_session_id')->nullable();
            $table->integer('client_id')->nullable();

            // What the cashier will recognise it by when they come back to it.
            $table->string('label', 64);
            $table->string('notes', 255)->nullable();

            // The basket, verbatim. A hold is not a sale, so it is kept as the
            // cart it is rather than forced into the sale tables, where it would
            // consume an invoice number and have to be excluded from every
            // report that counts sales.
            $table->longText('cart');

            // Kept alongside so the list of holds can be shown without decoding
            // every basket to find out what is in it.
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total', 20, 4)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'user_id']);
            $table->index('till_session_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_holds');

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'voided_at')) {
                $table->dropIndex(['voided_at']);
                $table->dropColumn(['voided_at', 'voided_by', 'void_reason']);
            }
        });
    }
}
