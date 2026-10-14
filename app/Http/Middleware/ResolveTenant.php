<?php

namespace App\Http\Middleware;

use App\utils\TenantManager;
use Closure;

/**
 * Points the default database connection at the tenant this request belongs
 * to, before anything else runs.
 *
 * Fail-closed by design: when multi-tenancy is switched on and the host does
 * not resolve to a tenant, the request is refused rather than being served
 * from the landlord database. Serving one tenant another's data is the single
 * worst thing this feature could do, so an unresolved host is an error and
 * never a fallback.
 */
class ResolveTenant
{
    public function handle($request, Closure $next)
    {
        if (! TenantManager::isMultiTenant()) {
            return $next($request);
        }

        TenantManager::ensureLandlordConnection();

        $tenant = TenantManager::resolveFromRequest($request);

        if (! $tenant) {
            return response()->json([
                'message' => 'This address is not linked to an account.',
            ], 404);
        }

        if ($tenant->status === 'suspended') {
            return response()->json([
                'message' => 'This account is suspended.',
            ], 403);
        }

        if ($tenant->status !== 'active') {
            return response()->json([
                'message' => 'This account is still being set up.',
            ], 503);
        }

        TenantManager::switchTo($tenant);

        return $next($request);
    }
}
