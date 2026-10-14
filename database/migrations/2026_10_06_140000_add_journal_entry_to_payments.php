<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which journal entry a payment's cash is booked in (DATATEST K27).
 *
 * Only the sale, purchase and expense were ever posted, once, with whatever
 * had been paid by then. A payment taken afterwards, a refund, an edit or a
 * delete never reached the journal. Payments are now posted on their own,
 * and this column says where each one already is, so none is booked twice.
 *
 * Backfill: a payment that existed when its document was posted is already
 * inside that document's entry, and is marked so. A payment made after it was
 * never booked; it stays unmarked and the next posting run books it.
 */
class AddJournalEntryToPayments extends Migration
{
    const TABLES = [
        'payment_sales' => ['sale_id', 'Sale'],
        'payment_purchases' => ['purchase_id', 'Purchase'],
        'payment_sale_returns' => ['sale_return_id', 'SaleReturn'],
        'payment_purchase_returns' => ['purchase_return_id', 'PurchaseReturn'],
    ];

    public function up()
    {
        foreach (self::TABLES as $table => [$fk, $source]) {
            if (! Schema::hasColumn($table, 'journal_entry_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedInteger('journal_entry_id')->nullable()->index();
                });
            }

            // The document's own entry (not a reversal), posted after the payment.
            DB::statement("
                UPDATE {$table} p
                JOIN journal_entries e
                  ON e.source_type = ? AND e.source_id = p.{$fk} AND e.reverses_entry_id IS NULL
                 AND e.created_at >= p.created_at
                SET p.journal_entry_id = e.id
                WHERE p.journal_entry_id IS NULL
            ", [$source]);
        }
    }

    public function down()
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (Schema::hasColumn($table, 'journal_entry_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['journal_entry_id']);
                    $t->dropColumn('journal_entry_id');
                });
            }
        }
    }
}
