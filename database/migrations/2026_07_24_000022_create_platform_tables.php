<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks, and the record of what has been soft-deleted.
 *
 * Soft deletes were already used across the schema, but nothing in the
 * interface let anyone see or restore a deleted record -- so a mistaken
 * delete was, in practice, permanent. The recycle bin does not need its own
 * storage: it reads the deleted_at columns that are already there. What is
 * recorded here is the restore itself, so undeleting is auditable too.
 *
 * Offline POS needs an idempotency key: a sale captured offline and retried on
 * reconnect must not be banked twice.
 */
class CreatePlatformTables extends Migration
{
    public function up()
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 192);
            $table->string('url', 512);
            // Comma-separated event names this endpoint wants.
            $table->text('events');
            // Used to sign the payload so the receiver can verify it came from us.
            $table->string('secret', 128);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('webhook_id');
            $table->string('event', 64);
            $table->longText('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            // pending | delivered | failed
            $table->string('status', 32)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('webhook_id')->references('id')->on('webhooks')->onDelete('cascade');
            $table->index(['status', 'created_at']);
        });

        Schema::create('restore_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('model', 128);
            $table->integer('record_id');
            $table->string('label', 255)->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();

            $table->index(['model', 'record_id']);
        });

        // Offline POS: the client stamps a key, the server refuses a repeat.
        Schema::create('offline_sale_keys', function (Blueprint $table) {
            $table->increments('id');
            $table->string('idempotency_key', 128);
            $table->integer('sale_id')->nullable();
            $table->integer('user_id')->nullable();
            // accepted | rejected
            $table->string('status', 32)->default('accepted');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
        });
    }

    public function down()
    {
        Schema::dropIfExists('offline_sale_keys');
        Schema::dropIfExists('restore_logs');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
}
