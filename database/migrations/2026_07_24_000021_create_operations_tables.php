<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Background jobs, in-app notifications, and backup records.
 *
 * QUEUE_CONNECTION was sync, so email, SMS and PDF generation all ran inside
 * the web request -- a slow SMTP host stalled the user's browser. These tables
 * let the queue move to the database driver.
 *
 * The bell in the header had no table behind it: the badge was hard-coded to
 * show "1" and the only thing it could ever link to was the stock alert
 * report. Notifications now exist as records.
 */
class CreateOperationsTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->increments('id');
            // null = everyone with the matching permission sees it
            $table->integer('user_id')->nullable();
            $table->string('type', 64);
            $table->string('title', 192);
            $table->text('body')->nullable();
            // Where clicking it should take you.
            $table->string('link', 255)->nullable();
            $table->string('icon', 64)->nullable();
            // info | success | warning | danger
            $table->string('level', 16)->default('info');
            // Only users holding this permission are shown a broadcast notice.
            $table->string('permission', 64)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index('created_at');
        });

        // Per-user read state for broadcast (user_id null) notifications,
        // because one row is shown to many people.
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('notification_id');
            $table->integer('user_id');
            $table->timestamp('read_at')->nullable();

            $table->foreign('notification_id')->references('id')->on('app_notifications')->onDelete('cascade');
            $table->unique(['notification_id', 'user_id'], 'notification_read_unique');
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('filename', 255);
            $table->string('disk', 64)->default('local');
            $table->string('path', 512)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            // manual | scheduled
            $table->string('trigger', 32)->default('manual');
            // pending | completed | failed
            $table->string('status', 32)->default('pending');
            // Whether a copy reached the offsite disk.
            $table->boolean('offsite')->default(false);
            $table->string('offsite_disk', 64)->nullable();
            $table->text('error')->nullable();
            // Proof the file is intact, checked by the restore test.
            $table->string('checksum', 128)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('backup_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('scheduled_enabled')->default(false);
            // daily | weekly | hourly
            $table->string('frequency', 32)->default('daily');
            $table->string('run_at', 8)->default('02:00');
            // How many to keep before the oldest are pruned.
            $table->unsignedInteger('retention_count')->default(7);
            $table->boolean('offsite_enabled')->default(false);
            $table->string('offsite_disk', 64)->nullable();
            $table->boolean('verify_after_backup')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        \DB::table('backup_settings')->insert([
            'scheduled_enabled' => false,
            'frequency' => 'daily',
            'run_at' => '02:00',
            'retention_count' => 7,
            'offsite_enabled' => false,
            'verify_after_backup' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('backup_settings');
        Schema::dropIfExists('backups');
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
}
