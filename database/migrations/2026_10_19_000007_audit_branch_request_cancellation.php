<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AuditBranchRequestCancellation extends Migration
{
    public function up()
    {
        Schema::table('branch_requests', function (Blueprint $table) {
            $table->integer('cancelled_by')->nullable();
            $table->text('cancel_reason')->nullable();
        });
        Schema::table('branch_shipments', function (Blueprint $table) {
            $table->integer('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
        });
    }

    public function down()
    {
        Schema::table('branch_shipments', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by', 'cancelled_at', 'cancel_reason']);
        });
        Schema::table('branch_requests', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by', 'cancel_reason']);
        });
    }
}
