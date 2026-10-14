<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records who changed what, when, and from where.
 *
 * The system previously kept no history at all, so a price change, a deleted
 * sale or an inventory adjustment left no trace of its author. Every audited
 * model writes one row here per create / update / delete / restore.
 */
class CreateAuditLogsTable extends Migration
{
    public function up()
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            // Who. user_id is nullable because console commands, seeders and
            // scheduled jobs legitimately act without a signed-in user.
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('user_name', 191)->nullable();

            // What
            $table->string('event', 20)->index();          // created|updated|deleted|restored
            $table->string('auditable_type', 191);         // e.g. App\Models\Sale
            $table->unsignedBigInteger('auditable_id');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Where from
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('url', 255)->nullable();

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['auditable_type', 'auditable_id'], 'audit_auditable_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('audit_logs');
    }
}
