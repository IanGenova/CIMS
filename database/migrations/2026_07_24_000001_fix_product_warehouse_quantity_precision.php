<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixProductWarehouseQuantityPrecision extends Migration
{
    public function up()
    {
        Schema::table('product_warehouse', function (Blueprint $table) {
            // Unit conversions can create fractional base quantities.
            $table->decimal('qte', 20, 6)->default(0)->change();
        });
    }

    public function down()
    {
        Schema::table('product_warehouse', function (Blueprint $table) {
            $table->float('qte', 10, 0)->change();
        });
    }
}
