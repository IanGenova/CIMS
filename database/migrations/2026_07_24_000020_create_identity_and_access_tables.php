<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identity and access: email verification, two-factor, password policy,
 * and session control.
 *
 * The email verification half is a repair, not a feature. The User model
 * already casts email_verified_at to a datetime, but the column was never in
 * the users migration -- so Laravel's whole verification flow could not work.
 * Existing users are marked verified on the way in, because retro-locking
 * people out of a system they already use would be a hostile change.
 */
class CreateIdentityAndAccessTables extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // The column the model has been casting all along.
            $table->timestamp('email_verified_at')->nullable()->after('email');

            // --- two-factor ---
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // --- password policy ---
            $table->timestamp('password_changed_at')->nullable();
            // Forces a change at next sign-in (new accounts, admin resets).
            $table->boolean('must_change_password')->default(false);

            // --- lockout ---
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });

        // Anyone already using the system is treated as verified.
        DB::table('users')->whereNull('email_verified_at')
            ->update(['email_verified_at' => now(), 'password_changed_at' => now()]);

        // Database sessions, so "sign out my other devices" is possible at all.
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->text('payload');
                $table->integer('last_activity')->index();
            });
        }

        // Passwords may not be reused; keep the hashes of recent ones.
        Schema::create('password_histories', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('password', 191);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });

        // The password rules themselves, so they are configurable rather than
        // hard-coded into a validator.
        Schema::create('security_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('min_length')->default(8);
            $table->boolean('require_uppercase')->default(true);
            $table->boolean('require_lowercase')->default(true);
            $table->boolean('require_number')->default(true);
            $table->boolean('require_symbol')->default(false);
            // 0 = passwords never expire.
            $table->unsignedInteger('expiry_days')->default(0);
            // 0 = never reject a reused password.
            $table->unsignedInteger('history_count')->default(3);
            $table->unsignedInteger('max_failed_attempts')->default(5);
            $table->unsignedInteger('lockout_minutes')->default(15);
            $table->boolean('require_two_factor')->default(false);
            $table->timestamps();
        });

        DB::table('security_settings')->insert([
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_number' => true,
            'require_symbol' => false,
            'expiry_days' => 0,
            'history_count' => 3,
            'max_failed_attempts' => 5,
            'lockout_minutes' => 15,
            'require_two_factor' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('security_settings');
        Schema::dropIfExists('password_histories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_verified_at', 'two_factor_secret', 'two_factor_recovery_codes',
                'two_factor_confirmed_at', 'password_changed_at', 'must_change_password',
                'failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip',
            ]);
        });
    }
}
