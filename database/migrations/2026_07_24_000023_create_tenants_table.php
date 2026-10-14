<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy: one database per tenant.
 *
 * The obvious approach -- a tenant_id column plus an Eloquent global scope --
 * is the wrong one for this codebase. It makes over a hundred raw
 * DB::table() queries, and a global scope does not touch any of them. That
 * would give isolation that looks complete and leaks in the places nobody
 * checks, which is worse than no isolation at all.
 *
 * A database per tenant is fail-closed instead: the middleware repoints the
 * default connection, so Eloquent and raw query builder alike land in the
 * right database with no per-query changes and nothing to forget.
 *
 * This table lives in the landlord database -- the one in .env -- and is the
 * only thing that is shared.
 */
class CreateTenantsTable extends Migration
{
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 192);
            // Used for the subdomain and as the default database suffix.
            $table->string('slug', 64);
            $table->string('database', 128);
            // Optional custom domain, when a tenant does not use a subdomain.
            $table->string('domain', 192)->nullable();
            // active | suspended | provisioning | failed
            $table->string('status', 32)->default('provisioning');
            $table->string('contact_email', 192)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('slug');
            $table->unique('database');
            $table->index('domain');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tenants');
    }
}
