<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer and supplier codes had the same race as the document references, and
 * nothing in the schema stopped two records sharing one. A code identifies a
 * trading partner on statements and in imports, so a duplicate quietly merges
 * two businesses in the operator's head.
 */
class UniqueCustomerAndSupplierCodes extends Migration
{
    private $tables = ['clients', 'providers'];

    public function up()
    {
        foreach ($this->tables as $t) {
            if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'code')) {
                continue;
            }

            // Soft-deleted rows keep their code, so they are counted here too --
            // reissuing a deleted customer's code would be its own confusion.
            $dupes = DB::table($t)
                ->select('code', DB::raw('COUNT(*) AS n'))
                ->groupBy('code')->havingRaw('COUNT(*) > 1')
                ->get();

            if ($dupes->count() > 0) {
                $list = $dupes->take(5)->map(function ($d) {
                    return $d->code . ' (x' . $d->n . ')';
                })->implode(', ');

                echo "  [skipped] {$t}: {$dupes->count()} duplicate code(s) already present -- {$list}\n"
                   . "            reconcile these, then re-run: php artisan migrate\n";

                continue;
            }

            if ($this->hasUniqueCodeIndex($t)) {
                continue;
            }

            Schema::table($t, function (Blueprint $table) use ($t) {
                $table->unique('code', $t . '_code_unique');
            });
        }
    }

    public function down()
    {
        foreach ($this->tables as $t) {
            if (Schema::hasTable($t) && $this->hasUniqueCodeIndex($t)) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    $table->dropUnique($t . '_code_unique');
                });
            }
        }
    }

    private function hasUniqueCodeIndex($t)
    {
        $rows = DB::select(
            'SELECT index_name FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND non_unique = 0',
            [$t, 'code']
        );

        return count($rows) > 0;
    }
}
