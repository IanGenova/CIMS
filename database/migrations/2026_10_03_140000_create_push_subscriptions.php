<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devices that asked for notices when the app is not open (Web Push).
 * One row per browser on a device; see App\utils\WebPush.
 */
class CreatePushSubscriptions extends Migration
{
    public function up()
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->integer('user_id')->index();
            $table->string('endpoint', 500)->unique();
            $table->string('p256dh', 100);
            $table->string('auth', 50);
            $table->timestamps();
        });

        // A notice is pushed once, however many times it is queued.
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->timestamp('pushed_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropColumn('pushed_at');
        });
        Schema::dropIfExists('push_subscriptions');
    }
}
