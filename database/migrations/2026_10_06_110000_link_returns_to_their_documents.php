<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A return names the document it returns (DATATEST K5, .flow/DATATEST-2026-10-06.md).
 *
 * Returns were free-standing: goods never sold to a customer could be
 * "returned" for credit, and a sale could be voided after part of it came
 * back, restocking those units twice. Returns made before this stay unlinked.
 */
class LinkReturnsToTheirDocuments extends Migration
{
    public function up()
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_id')->nullable()->after('client_id')->index();
        });
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_id')->nullable()->after('provider_id')->index();
        });
    }

    public function down()
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn('sale_id');
        });
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn('purchase_id');
        });
    }
}
