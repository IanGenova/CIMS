<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The till session: a cashier's shift at a drawer.
 *
 * Everything at the till hangs off this. Without it there is no answer to "does
 * the drawer balance", because there is no float to start from and no moment to
 * count against -- and no Z-reading, which is the thing the BIR actually asks a
 * point of sale to produce at the end of a day's trading.
 *
 * Two numbers on the session exist only for that reading. The Z-counter is a
 * per-terminal sequence that must never repeat or go backwards, and the
 * accumulated grand total is a running lifetime figure that only ever increases.
 * A reading is verifiable because the difference between two consecutive
 * accumulated totals is the sales between them.
 */
class CreateTillSessions extends Migration
{
    public function up()
    {
        Schema::create('till_sessions', function (Blueprint $table) {
            $table->increments('id');

            // Which drawer. CIMS has no terminal record, so a till is a named
            // drawer within a warehouse -- one warehouse can run several.
            $table->integer('warehouse_id');
            $table->string('terminal', 64)->default('MAIN');

            $table->integer('user_id');                       // opened the till
            $table->integer('closed_by')->nullable();         // counted it down

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->decimal('opening_float', 20, 4)->default(0);
            $table->decimal('counted_cash', 20, 4)->nullable();
            $table->decimal('expected_cash', 20, 4)->nullable();
            $table->decimal('variance', 20, 4)->nullable();

            $table->string('status', 16)->default('open');    // open | closed

            // Assigned at close, never reused, never rolled back.
            $table->unsignedInteger('z_counter')->nullable();
            $table->decimal('grand_total_before', 20, 4)->nullable();
            $table->decimal('grand_total_after', 20, 4)->nullable();

            // The reading as it stood at the moment of closing, kept verbatim so
            // a re-print years later shows what was signed off, not what a
            // recomputation would say today.
            $table->text('reading')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'terminal', 'status']);
            $table->index('user_id');
            $table->unique(['warehouse_id', 'terminal', 'z_counter'], 'till_z_counter_unique');
        });

        // Cash that moves in or out of the drawer without a sale behind it.
        Schema::create('till_movements', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('till_session_id');
            $table->string('type', 16);                       // cash_in | cash_out
            $table->decimal('amount', 20, 4);
            $table->string('reason', 255)->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('till_session_id');
        });

        // A POS sale belongs to the session that was open when it was rung up.
        if (! Schema::hasColumn('sales', 'till_session_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedInteger('till_session_id')->nullable()->after('warehouse_id');
                $table->index('till_session_id');
            });
        }

        // Off by default: turning it on stops the till selling until a session is
        // open, which is correct but is not a change to spring on a running shop.
        if (! Schema::hasColumn('pos_settings', 'require_till_session')) {
            Schema::table('pos_settings', function (Blueprint $table) {
                $table->boolean('require_till_session')->default(0)->after('auto_cut');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('pos_settings', 'require_till_session')) {
            Schema::table('pos_settings', function (Blueprint $table) {
                $table->dropColumn('require_till_session');
            });
        }
        if (Schema::hasColumn('sales', 'till_session_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['till_session_id']);
                $table->dropColumn('till_session_id');
            });
        }
        Schema::dropIfExists('till_movements');
        Schema::dropIfExists('till_sessions');
    }
}
