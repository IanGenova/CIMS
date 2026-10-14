<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\utils\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenantController extends Controller
{
    /**
     * Tenant administration.
     *
     * Listing and suspending are safe from a web request. Provisioning is not
     * offered here by default: creating a database and running migrations
     * belongs on the command line, where a failure is visible and a timeout
     * does not leave a half-built tenant behind.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        TenantManager::ensureLandlordConnection();

        $rows = TenantManager::landlord()->table('tenants')
            ->whereNull('deleted_at')->orderBy('id')->get();

        return response()->json([
            'tenants' => $rows,
            'enabled' => TenantManager::isMultiTenant(),
            'current' => TenantManager::current(),
            'landlord_database' => env('DB_DATABASE'),
            'hint' => TenantManager::isMultiTenant()
                ? 'Each tenant is served from its own database, chosen by subdomain, custom domain or the X-Tenant header.'
                : 'Multi-tenancy is off. Set MULTI_TENANT=true in .env to serve tenants by hostname.',
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', User::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'slug' => 'required|string|max:64',
            'contact_email' => 'nullable|email|max:192',
            'domain' => 'nullable|string|max:192',
        ]);

        // Provisioning creates a database and runs every migration against it.
        // That is minutes of work in a request that will time out first.
        throw ValidationException::withMessages([
            'tenant' => [
                'Create tenants from the command line, where provisioning can be watched and a failure is visible: '
                . 'php artisan tenant:manage create --name="' . $request->name . '" --slug=' . $request->slug,
            ],
        ]);
    }

    public function suspend(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        TenantManager::landlord()->table('tenants')->where('id', $id)->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function activate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $tenant = TenantManager::landlord()->table('tenants')->where('id', $id)->first();

        if ($tenant && $tenant->status === 'failed') {
            throw ValidationException::withMessages([
                'tenant' => ['This tenant failed to provision. Re-run its migrations before activating it: php artisan tenant:manage migrate --tenant=' . $tenant->slug],
            ]);
        }

        TenantManager::landlord()->table('tenants')->where('id', $id)->update([
            'status' => 'active',
            'suspended_at' => null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }
}
