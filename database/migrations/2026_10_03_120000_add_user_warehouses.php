<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff tied to their branch.
 *
 * A user is on all warehouses (the default, so every existing account keeps
 * working exactly as before) or only on the ones listed in user_warehouse.
 * Enforced by App\utils\WarehouseAccess; see WarehouseAccessTest.
 */
class AddUserWarehouses extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_warehouses')->default(1)->after('role_id');
        });

        Schema::create('user_warehouse', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->integer('warehouse_id')->index();
            $table->unique(['user_id', 'warehouse_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_warehouse');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('all_warehouses');
        });
    }
}
