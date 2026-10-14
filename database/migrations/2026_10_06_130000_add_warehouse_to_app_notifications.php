<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which warehouse a notification is about (DATATEST K96).
 *
 * A Branch-only manager was told "SL_0123 was voided" about a Main sale, and
 * the link opened a 404. Notifications about one warehouse now go only to
 * people who may use it; null still means everyone.
 */
class AddWarehouseToAppNotifications extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('app_notifications', 'warehouse_id')) {
            Schema::table('app_notifications', function (Blueprint $table) {
                $table->unsignedInteger('warehouse_id')->nullable()->after('permission')->index();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('app_notifications', 'warehouse_id')) {
            Schema::table('app_notifications', function (Blueprint $table) {
                $table->dropIndex(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            });
        }
    }
}
