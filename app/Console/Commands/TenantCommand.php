<?php

namespace App\Console\Commands;

use App\utils\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Tenant administration from the command line, because provisioning a
 * database is not something to do from a web request.
 */
class TenantCommand extends Command
{
    protected $signature = 'tenant:manage
                            {action : list|create|migrate|suspend|activate}
                            {--name= : Display name, for create}
                            {--slug= : Subdomain / database suffix, for create}
                            {--email= : Contact email, for create}
                            {--domain= : Custom domain, for create}
                            {--tenant= : Slug or id, for migrate/suspend/activate}';

    protected $description = 'Create, migrate and manage tenant databases';

    public function handle()
    {
        TenantManager::ensureLandlordConnection();

        switch ($this->argument('action')) {
            case 'list':
                return $this->listTenants();
            case 'create':
                return $this->createTenant();
            case 'migrate':
                return $this->migrateTenants();
            case 'suspend':
                return $this->setStatus('suspended');
            case 'activate':
                return $this->setStatus('active');
            default:
                $this->error('Unknown action. Use list, create, migrate, suspend or activate.');

                return 1;
        }
    }

    private function listTenants()
    {
        $rows = TenantManager::landlord()->table('tenants')
            ->whereNull('deleted_at')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->info('No tenants yet. Create one with: php artisan tenant:manage create --name="Acme" --slug=acme');

            return 0;
        }

        $this->table(
            ['ID', 'Name', 'Slug', 'Database', 'Domain', 'Status'],
            $rows->map(function ($t) {
                return [$t->id, $t->name, $t->slug, $t->database, $t->domain ?: '—', $t->status];
            })->all()
        );

        return 0;
    }

    private function createTenant()
    {
        $name = $this->option('name');
        $slug = $this->option('slug');

        if (! $name || ! $slug) {
            $this->error('--name and --slug are both required.');

            return 1;
        }

        $this->info('Creating database and running migrations…');

        try {
            $tenant = TenantManager::provision($name, $slug, $this->option('email'), $this->option('domain'));
        } catch (\Throwable $e) {
            $this->error('Provisioning failed: ' . $e->getMessage());
            $this->warn('The tenant row is marked "failed" so it will not serve traffic.');

            return 1;
        }

        $this->info('Created tenant "' . $tenant->name . '" on database ' . $tenant->database . '.');
        $this->line('Reach it at: ' . ($tenant->domain ?: $tenant->slug . '.<your-domain>'));
        $this->warn('It has no users yet. Seed one, or run your installer against that database.');

        return 0;
    }

    /** Apply pending migrations to every tenant, or to one named tenant. */
    private function migrateTenants()
    {
        $only = $this->option('tenant');

        if ($only) {
            $tenant = TenantManager::find($only);
            if (! $tenant) {
                $this->error('No such tenant: ' . $only);

                return 1;
            }
            $tenants = [$tenant];
        } else {
            $tenants = TenantManager::landlord()->table('tenants')
                ->whereNull('deleted_at')->where('status', 'active')->get()->all();
        }

        if (empty($tenants)) {
            $this->info('Nothing to migrate.');

            return 0;
        }

        $failed = 0;
        foreach ($tenants as $t) {
            $this->line('→ ' . $t->slug);
            try {
                TenantManager::switchTo($t);
                Artisan::call('migrate', ['--force' => true]);
                $this->info('  ' . trim(Artisan::output()));
            } catch (\Throwable $e) {
                // One tenant failing must not stop the rest from migrating.
                $this->error('  failed: ' . $e->getMessage());
                $failed++;
            } finally {
                TenantManager::forget();
            }
        }

        if ($failed) {
            $this->warn($failed . ' tenant(s) failed to migrate.');

            return 1;
        }

        return 0;
    }

    private function setStatus($status)
    {
        $key = $this->option('tenant');
        if (! $key) {
            $this->error('--tenant is required.');

            return 1;
        }

        $tenant = TenantManager::find($key);
        if (! $tenant) {
            $this->error('No such tenant: ' . $key);

            return 1;
        }

        TenantManager::landlord()->table('tenants')->where('id', $tenant->id)->update([
            'status' => $status,
            'suspended_at' => $status === 'suspended' ? now() : null,
            'updated_at' => now(),
        ]);

        $this->info($tenant->slug . ' is now ' . $status . '.');

        return 0;
    }
}
