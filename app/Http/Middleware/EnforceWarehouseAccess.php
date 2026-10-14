<?php

namespace App\Http\Middleware;

use App\utils\WarehouseAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * No request may name a warehouse its sender is not on.
 *
 * The forms only offer the person's own warehouses, but a request can name
 * any id. Every save names its branch in one of a few places -- warehouse_id
 * on almost everything, transfer.from_warehouse on a transfer (its
 * destination may be any branch), and the till's product list carries it in
 * the address -- so they are checked here once, for every endpoint, rather
 * than in each of thirty controllers. Runs after auth:api. Does nothing for
 * anyone on all warehouses. See WarehouseAccessTest.
 */
class EnforceWarehouseAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! WarehouseAccess::isRestricted()) {
            return $next($request);
        }

        $named = [];

        if ($request->filled('warehouse_id')) {
            $named[] = $request->input('warehouse_id');
        }

        $transfer = $request->input('transfer');
        if (is_array($transfer) && isset($transfer['from_warehouse']) && $transfer['from_warehouse'] !== '') {
            $named[] = $transfer['from_warehouse'];
        }

        if ($request->is('api/Products/Warehouse/*')) {
            $named[] = $request->route('id');
        }

        foreach ($named as $id) {
            if (is_scalar($id) && ! WarehouseAccess::allows($id)) {
                return response()->json([
                    'message' => 'You can only work in: ' . WarehouseAccess::names() . '.',
                ], 403);
            }
        }

        return $next($request);
    }
}
