<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A held basket now sets its stock aside (PosHoldController).
 *
 *   stock_held         true while the basket's goods are off the shelf count;
 *                      baskets held before this change stay false and give
 *                      nothing back
 *   reserved           what was taken, in base units, so exactly that returns
 *   stock_released_at  when it went back: discarded, recalled, or forgotten
 */
class AddStockToPosHolds extends Migration
{
    public function up()
    {
        Schema::table('pos_holds', function (Blueprint $table) {
            $table->boolean('stock_held')->default(false);
            $table->longText('reserved')->nullable();
            $table->timestamp('stock_released_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('pos_holds', function (Blueprint $table) {
            $table->dropColumn(['stock_held', 'reserved', 'stock_released_at']);
        });
    }
}
