<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who caused a notification, so they are not told about their own action.
 *
 * A purchase raised by the buyer goes to everyone who can approve purchases;
 * if the buyer can also approve, they still should not get "PR_1116 needs
 * approval" for the order they just typed in.
 */
class AddActorToAppNotifications extends Migration
{
    public function up()
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->integer('actor_id')->nullable()->after('permission')->index();
        });
    }

    public function down()
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropColumn('actor_id');
        });
    }
}
