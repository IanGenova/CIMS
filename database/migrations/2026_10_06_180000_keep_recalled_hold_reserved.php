<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who recalled a held basket, and when (DATATEST K77).
 *
 * Recalling gave the goods back to the shelf at once, while the basket was
 * still being rung up, so another till could sell them first. The goods now
 * stay set aside for the person who recalled the basket until their sale
 * takes them (or the hold is forgotten and released on the usual timer).
 */
class KeepRecalledHoldReserved extends Migration
{
    public function up()
    {
        Schema::table('pos_holds', function (Blueprint $t) {
            if (! Schema::hasColumn('pos_holds', 'recalled_by')) {
                $t->unsignedInteger('recalled_by')->nullable()->index();
            }
            if (! Schema::hasColumn('pos_holds', 'recalled_at')) {
                $t->timestamp('recalled_at')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('pos_holds', function (Blueprint $t) {
            if (Schema::hasColumn('pos_holds', 'recalled_by')) {
                $t->dropIndex(['recalled_by']);
                $t->dropColumn('recalled_by');
            }
            if (Schema::hasColumn('pos_holds', 'recalled_at')) {
                $t->dropColumn('recalled_at');
            }
        });
    }
}
