<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateDocumentSequencesAndUniqueRefs extends Migration
{
    /**
     * Tables whose Ref is a document number a person can be handed. Deliveries,
     * journal entries and production orders are internal but are numbered the
     * same way and had the same race, so they get the same protection.
     */
    private $tables = [
        'sales', 'purchases', 'quotations', 'sale_returns', 'purchase_returns',
        'adjustments', 'transfers', 'expenses', 'goods_receipts', 'stock_counts',
        'payment_sales', 'payment_purchases', 'payment_sale_returns', 'payment_purchase_returns',
        'deliveries', 'journal_entries', 'production_orders',
    ];

    public function up()
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->increments('id');
            // "<table>:<prefix>" -- two tables that happen to share a prefix
            // (sale_returns and purchase_returns are both RT_) stay independent.
            $table->string('sequence_key', 128)->unique();
            // The last number issued. The next claim returns one past it.
            $table->unsignedBigInteger('next_number')->default(0);
            $table->timestamps();
        });

        foreach ($this->tables as $t) {
            if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'Ref')) {
                continue;
            }

            // An existing deployment may already have collided -- that is the
            // whole point of this migration. Report those rather than failing
            // the migration and leaving the fix half-applied; they have to be
            // reconciled by a person, because only they know which sale is which.
            $dupes = DB::table($t)
                ->select('Ref', DB::raw('COUNT(*) AS n'))
                ->groupBy('Ref')->havingRaw('COUNT(*) > 1')
                ->get();

            if ($dupes->count() > 0) {
                $list = $dupes->take(5)->map(function ($d) {
                    return $d->Ref . ' (x' . $d->n . ')';
                })->implode(', ');

                echo "  [skipped] {$t}: {$dupes->count()} duplicate reference(s) already present -- {$list}\n"
                   . "            resolve these, then re-run: php artisan migrate\n";

                continue;
            }

            if ($this->hasUniqueRefIndex($t)) {
                continue;
            }

            Schema::table($t, function (Blueprint $table) use ($t) {
                $table->unique('Ref', $t . '_ref_unique');
            });
        }
    }

    public function down()
    {
        foreach ($this->tables as $t) {
            if (Schema::hasTable($t) && $this->hasUniqueRefIndex($t)) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    $table->dropUnique($t . '_ref_unique');
                });
            }
        }

        Schema::dropIfExists('document_sequences');
    }

    private function hasUniqueRefIndex($t)
    {
        $rows = DB::select(
            'SELECT index_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND non_unique = 0',
            [$t, 'Ref']
        );

        return count($rows) > 0;
    }
}
