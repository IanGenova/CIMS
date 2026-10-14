<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt printing settings for the till.
 *
 * A browser cannot choose a printer on its own -- that is a deliberate limit of
 * the platform, not something the application can code around. What it can do is
 * lay the slip out for the paper that is actually loaded, print without making
 * the cashier click through a dialog, and hand the job to a local print agent
 * when one is installed. These settings cover all three.
 */
class AddPrintingToPosSettings extends Migration
{
    public function up()
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            // 58mm and 80mm are the common thermal rolls; a4 for a laser printer.
            $table->string('paper_size', 8)->default('80mm')->after('show_address');
            // Print as soon as the sale is banked. Defaults ON because that is
            // what the till already did unconditionally -- this setting exposes
            // control over it without changing what happens today.
            $table->boolean('auto_print')->default(true)->after('paper_size');
            // Only a local print agent can honour this; the browser cannot.
            $table->string('printer_name', 192)->nullable()->after('auto_print');
            // ESC/POS drawer kick -- likewise agent-only.
            $table->boolean('open_cash_drawer')->default(false)->after('printer_name');
        });
    }

    public function down()
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            $table->dropColumn(['paper_size', 'auto_print', 'printer_name', 'open_cash_drawer']);
        });
    }
}
