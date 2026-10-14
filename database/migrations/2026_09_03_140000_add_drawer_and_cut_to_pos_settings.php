<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash drawer and paper cutter details.
 *
 * Drawers hang off the printer's RJ11 port and are fired by an ESC/POS pulse.
 * Which pin carries it is not standardised -- most are pin 2, some are pin 5 --
 * so it has to be a setting rather than an assumption, or half the tills in the
 * field simply never open.
 */
class AddDrawerAndCutToPosSettings extends Migration
{
    public function up()
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            // 0 = pin 2 (the common one), 1 = pin 5.
            $table->unsignedTinyInteger('drawer_pin')->default(0)->after('open_cash_drawer');
            // Guillotine cut after each receipt, where the printer has one.
            $table->boolean('auto_cut')->default(true)->after('drawer_pin');
        });
    }

    public function down()
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            $table->dropColumn(['drawer_pin', 'auto_cut']);
        });
    }
}
