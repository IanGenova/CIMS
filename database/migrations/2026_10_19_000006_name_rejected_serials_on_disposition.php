<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class NameRejectedSerialsOnDisposition extends Migration
{
    public function up()
    {
        Schema::table('goods_receipt_qc_dispositions', function (Blueprint $table) {
            $table->text('serial_numbers')->nullable();
        });
    }

    public function down()
    {
        Schema::table('goods_receipt_qc_dispositions', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });
    }
}
