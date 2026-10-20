<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBranchPulloutsAndReturnQc extends Migration
{
    public function up()
    {
        Schema::table('branch_requests', function (Blueprint $table) {
            $table->string('kind', 24)->default('replenishment')->index();
        });
        Schema::table('branch_shipment_items', function (Blueprint $table) {
            $table->decimal('qc_passed_qte', 20, 6)->default(0);
            $table->decimal('qc_failed_qte', 20, 6)->default(0);
            $table->string('qc_status', 24)->nullable();
        });
        Schema::create('branch_shipment_qc_decisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_shipment_item_id')->index();
            $table->decimal('passed_qte', 20, 6);
            $table->decimal('failed_qte', 20, 6);
            $table->text('notes')->nullable();
            $table->integer('decided_by');
            $table->timestamp('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('branch_shipment_qc_decisions');
        Schema::table('branch_shipment_items', function (Blueprint $table) {
            $table->dropColumn(['qc_passed_qte', 'qc_failed_qte', 'qc_status']);
        });
        Schema::table('branch_requests', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
}
