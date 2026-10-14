<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posting to the ledger without being asked.
 *
 * Defaults to on. The state this replaces is not "posts on a different
 * schedule" -- it is "posts when somebody remembers to press a button", which
 * for a system that has to produce Books of Accounts is not a setting worth
 * preserving as the default.
 */
class AddAutomaticLedgerPosting extends Migration
{
    public function up()
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'auto_post_ledger')) {
                $table->boolean('auto_post_ledger')->default(1)->after('tin');
            }
            if (! Schema::hasColumn('settings', 'ledger_posted_at')) {
                $table->timestamp('ledger_posted_at')->nullable()->after('auto_post_ledger');
            }
        });

        // Existing rows predate the column default.
        \DB::table('settings')->update(['auto_post_ledger' => 1]);

        // The sweep looks for documents with no journal entry against them, so
        // it leans on this index every few minutes. It already exists as a
        // plain index from the accounting migration; nothing more is needed
        // here, but the lookup is worth naming so it is not lost later.
    }

    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['auto_post_ledger', 'ledger_posted_at'] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
