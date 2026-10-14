<?php

namespace App\utils;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Switching the application between tenant databases.
 *
 * The landlord connection is the one configured in .env and always points at
 * the shared database that holds the tenants table. Every other query runs on
 * the default connection, which this class repoints -- so the 100-plus raw
 * DB::table() calls scattered through the controllers follow the tenant
 * without any of them being touched.
 */
class TenantManager
{
    /** The landlord connection name, cloned from the default at boot. */
    const LANDLORD = 'landlord';

    private static $current = null;

    /** The tenant this request is running as, if any. */
    public static function current()
    {
        return self::$current;
    }

    public static function isMultiTenant()
    {
        return (bool) env('MULTI_TENANT', false);
    }

    /**
     * Make sure a "landlord" connection exists that always points at the
     * database in .env, whatever the default connection has been repointed to.
     */
    public static function ensureLandlordConnection()
    {
        $connections = Config::get('database.connections');

        if (! isset($connections[self::LANDLORD])) {
            $default = Config::get('database.default');
            $landlord = $connections[$default];
            $landlord['database'] = env('DB_DATABASE');

            Config::set('database.connections.' . self::LANDLORD, $landlord);
        }
    }

    /** Query the landlord regardless of which tenant is active. */
    public static function landlord()
    {
        self::ensureLandlordConnection();

        return DB::connection(self::LANDLORD);
    }

    /** Point the default connection at a tenant's database. */
    public static function switchTo($tenant)
    {
        if (is_string($tenant) || is_numeric($tenant)) {
            $tenant = self::find($tenant);
        }

        if (! $tenant) {
            throw ValidationException::withMessages([
                'tenant' => ['That tenant does not exist.'],
            ]);
        }

        if ($tenant->status === 'suspended') {
            throw ValidationException::withMessages([
                'tenant' => ['This account is suspended. Contact support.'],
            ]);
        }

        $default = Config::get('database.default');
        Config::set('database.connections.' . $default . '.database', $tenant->database);

        // Drop the cached PDO so the next query opens against the new database.
        DB::purge($default);
        DB::reconnect($default);

        self::$current = $tenant;

        return $tenant;
    }

    /** Put the default connection back on the landlord database. */
    public static function forget()
    {
        $default = Config::get('database.default');
        Config::set('database.connections.' . $default . '.database', env('DB_DATABASE'));
        DB::purge($default);
        DB::reconnect($default);

        self::$current = null;
    }

    /** Look a tenant up by id, slug or domain. */
    public static function find($key)
    {
        return self::landlord()->table('tenants')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($key) {
                $q->where('slug', $key)->orWhere('domain', $key);
                if (is_numeric($key)) {
                    $q->orWhere('id', $key);
                }
            })
            ->first();
    }

    /** The tenant a request belongs to, from host or explicit header. */
    public static function resolveFromRequest($request)
    {
        $host = $request->getHost();

        // An exact custom domain wins.
        $byDomain = self::landlord()->table('tenants')
            ->whereNull('deleted_at')->where('domain', $host)->first();
        if ($byDomain) {
            return $byDomain;
        }

        // Otherwise the first label of the host, e.g. acme.cims.example.com.
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            $bySlug = self::landlord()->table('tenants')
                ->whereNull('deleted_at')->where('slug', $parts[0])->first();
            if ($bySlug) {
                return $bySlug;
            }
        }

        // A header, for API clients that cannot vary the host.
        $header = $request->header('X-Tenant');
        if ($header) {
            return self::find($header);
        }

        return null;
    }

    /**
     * Create a tenant: its database, its schema, and its seed data.
     *
     * Provisioning is marked failed rather than left half-done, so a broken
     * tenant is visible instead of silently serving an empty database.
     */
    public static function provision($name, $slug, $contactEmail = null, $domain = null)
    {
        $slug = strtolower(preg_replace('/[^a-z0-9\-]/i', '', $slug));

        if ($slug === '') {
            throw ValidationException::withMessages([
                'slug' => ['The slug must contain letters or numbers.'],
            ]);
        }

        if (self::landlord()->table('tenants')->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['That slug is already taken.'],
            ]);
        }

        $database = env('TENANT_DB_PREFIX', 'cims_tenant_') . $slug;

        $id = self::landlord()->table('tenants')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'database' => $database,
            'domain' => $domain,
            'contact_email' => $contactEmail,
            'status' => 'provisioning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            // CREATE DATABASE cannot be parameterised, so the name is built
            // from a slug that has already been stripped to [a-z0-9-].
            self::landlord()->statement(
                'CREATE DATABASE IF NOT EXISTS `' . $database . '` '
                . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            $tenant = self::landlord()->table('tenants')->where('id', $id)->first();
            self::switchTo($tenant);

            Artisan::call('migrate', ['--force' => true]);

            self::forget();

            self::landlord()->table('tenants')->where('id', $id)->update([
                'status' => 'active',
                'provisioned_at' => now(),
                'updated_at' => now(),
            ]);

            return self::landlord()->table('tenants')->where('id', $id)->first();
        } catch (\Throwable $e) {
            self::forget();

            self::landlord()->table('tenants')->where('id', $id)->update([
                'status' => 'failed',
                'notes' => substr($e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    /** Run something with the default connection pointed at each tenant. */
    public static function eachTenant(callable $callback)
    {
        $tenants = self::landlord()->table('tenants')
            ->whereNull('deleted_at')->where('status', 'active')->get();

        $results = [];
        foreach ($tenants as $t) {
            try {
                self::switchTo($t);
                $results[$t->slug] = $callback($t);
            } catch (\Throwable $e) {
                $results[$t->slug] = 'ERROR: ' . $e->getMessage();
            } finally {
                self::forget();
            }
        }

        return $results;
    }
}
