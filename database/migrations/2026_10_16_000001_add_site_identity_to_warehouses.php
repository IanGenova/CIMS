<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSiteIdentityToWarehouses extends Migration
{
    public function up()
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->after('id');
            $table->string('site_type', 24)->default('warehouse')->after('name');
        });

        // An existing warehouse keeps its identity; do not guess which one is
        // central or a branch from its name. Staff can classify it in Settings.
        foreach (DB::table('warehouses')->select('id')->get() as $row) {
            DB::table('warehouses')->where('id', $row->id)->update(['code' => 'WH-' . $row->id]);
        }

        Schema::table('warehouses', function (Blueprint $table) {
            $table->unique('code', 'warehouses_code_unique');
        });
    }

    public function down()
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique('warehouses_code_unique');
            $table->dropColumn(['code', 'site_type']);
        });
    }
}
