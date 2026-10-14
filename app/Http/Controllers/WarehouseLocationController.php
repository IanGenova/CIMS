<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\utils\LocationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WarehouseLocationController extends Controller
{
    /** The bins in a warehouse, with what each is holding. */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Warehouse::class);

        $warehouseId = $request->warehouse_id;

        $locations = WarehouseLocation::where('deleted_at', '=', null)
            ->when($warehouseId, function ($q) use ($warehouseId) {
                return $q->where('warehouse_id', $warehouseId);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where(function ($w) use ($request) {
                    $w->where('code', 'LIKE', "%{$request->search}%")
                        ->orWhere('zone', 'LIKE', "%{$request->search}%");
                });
            })
            ->orderBy('warehouse_id')->orderBy('pick_sequence')->orderBy('code')
            ->get();

        $ids = $locations->pluck('id')->all();

        // One grouped query rather than a count per bin.
        $held = [];
        if (! empty($ids)) {
            $rows = \DB::table('location_stock')
                ->whereIn('location_id', $ids)
                ->groupBy('location_id')
                ->select('location_id', \DB::raw('SUM(qte) as total'), \DB::raw('COUNT(*) as line_count'))
                ->get();
            foreach ($rows as $r) {
                $held[$r->location_id] = ['qte' => (float) $r->total, 'lines' => (int) $r->line_count];
            }
        }

        $data = [];
        foreach ($locations as $l) {
            $data[] = [
                'id' => $l->id,
                'warehouse_id' => $l->warehouse_id,
                'code' => $l->code,
                'zone' => $l->zone,
                'aisle' => $l->aisle,
                'rack' => $l->rack,
                'bin' => $l->bin,
                'is_receiving' => (bool) $l->is_receiving,
                'is_active' => (bool) $l->is_active,
                'pick_sequence' => $l->pick_sequence,
                'held_qty' => isset($held[$l->id]) ? round($held[$l->id]['qte'], 6) : 0,
                'product_lines' => isset($held[$l->id]) ? $held[$l->id]['lines'] : 0,
            ];
        }

        return response()->json([
            'locations' => $data,
            'totalRows' => count($data),
            'warehouses' => Warehouse::where('deleted_at', '=', null)
                ->orderBy('name')->get(['id', 'name', 'uses_locations']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Warehouse::class);

        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'code' => 'required|string|max:64',
            'pick_sequence' => 'nullable|integer|min:0',
        ]);

        $dupe = WarehouseLocation::where('warehouse_id', $request->warehouse_id)
            ->where('code', $request->code)->where('deleted_at', '=', null)->exists();

        if ($dupe) {
            throw ValidationException::withMessages([
                'code' => ['That bin code already exists in this warehouse.'],
            ]);
        }

        WarehouseLocation::create([
            'warehouse_id' => $request->warehouse_id,
            'code' => $request->code,
            'zone' => $request['zone'],
            'aisle' => $request['aisle'],
            'rack' => $request['rack'],
            'bin' => $request['bin'],
            'is_receiving' => (bool) $request['is_receiving'],
            'is_active' => $request['is_active'] === null ? true : (bool) $request['is_active'],
            'pick_sequence' => $request['pick_sequence'] ?: 0,
        ]);

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Warehouse::class);

        $request->validate([
            'code' => 'required|string|max:64',
            'pick_sequence' => 'nullable|integer|min:0',
        ]);

        WarehouseLocation::whereId($id)->update([
            'code' => $request->code,
            'zone' => $request['zone'],
            'aisle' => $request['aisle'],
            'rack' => $request['rack'],
            'bin' => $request['bin'],
            'is_receiving' => (bool) $request['is_receiving'],
            'is_active' => (bool) $request['is_active'],
            'pick_sequence' => $request['pick_sequence'] ?: 0,
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);

        $holding = \DB::table('location_stock')->where('location_id', $id)
            ->where('qte', '>', 0)->exists();

        if ($holding) {
            throw ValidationException::withMessages([
                'location' => ['That bin still holds stock. Move it out before deleting the bin.'],
            ]);
        }

        WarehouseLocation::whereId($id)->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    /** Turn bin tracking on or off for a warehouse. */
    public function toggleWarehouse(Request $request, $warehouseId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Warehouse::class);

        Warehouse::whereId($warehouseId)->update([
            'uses_locations' => (bool) $request['uses_locations'],
        ]);
        LocationService::forget();

        return response()->json(['success' => true]);
    }

    /**
     * Where a warehouse's stock actually sits, and what has not been put away.
     */
    public function stock(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Warehouse::class);

        $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);
        $warehouseId = $request->warehouse_id;

        // Every product the warehouse holds, whether or not it has been put away.
        $products = \DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->whereNull('pw.deleted_at')->whereNull('p.deleted_at')
            ->where('pw.warehouse_id', $warehouseId)
            ->where('pw.qte', '>', 0)
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where(function ($w) use ($request) {
                    $w->where('p.name', 'LIKE', "%{$request->search}%")
                        ->orWhere('p.code', 'LIKE', "%{$request->search}%");
                });
            })
            ->select('pw.product_id', 'pw.product_variant_id', 'pw.qte', 'p.name', 'p.code')
            ->orderBy('p.name')->get();

        $rows = [];
        $totalUnallocated = 0.0;

        foreach ($products as $p) {
            $allocated = LocationService::allocatedQty($p->product_id, $p->product_variant_id, $warehouseId);
            $unallocated = round((float) $p->qte - $allocated, 6);
            $totalUnallocated += max(0, $unallocated);

            $rows[] = [
                'product_id' => $p->product_id,
                'product_variant_id' => $p->product_variant_id,
                'product' => $p->name,
                'code' => $p->code,
                'warehouse_qty' => round((float) $p->qte, 6),
                'allocated' => round($allocated, 6),
                'unallocated' => $unallocated,
                'bins' => LocationService::pickPath($p->product_id, $p->product_variant_id, $warehouseId),
            ];
        }

        return response()->json([
            'rows' => $rows,
            'total_unallocated' => round($totalUnallocated, 6),
            'uses_locations' => LocationService::isEnabled($warehouseId),
            'locations' => WarehouseLocation::where('deleted_at', '=', null)
                ->where('warehouse_id', $warehouseId)->where('is_active', true)
                ->orderBy('pick_sequence')->orderBy('code')->get(['id', 'code', 'is_receiving']),
        ]);
    }

    public function putAway(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Warehouse::class);

        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'product_id' => 'required|integer|exists:products,id',
            'location_id' => 'required|integer|exists:warehouse_locations,id',
            'quantity' => 'required|numeric|gt:0',
        ]);

        LocationService::putAway(
            $request->product_id, $request['product_variant_id'],
            $request->warehouse_id, $request->location_id,
            $request->quantity, $request['notes']
        );

        return response()->json(['success' => true]);
    }

    public function move(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Warehouse::class);

        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'product_id' => 'required|integer|exists:products,id',
            'from_location_id' => 'required|integer|exists:warehouse_locations,id',
            'to_location_id' => 'required|integer|exists:warehouse_locations,id',
            'quantity' => 'required|numeric|gt:0',
        ]);

        LocationService::move(
            $request->product_id, $request['product_variant_id'],
            $request->warehouse_id, $request->from_location_id, $request->to_location_id,
            $request->quantity, $request['notes']
        );

        return response()->json(['success' => true]);
    }

    /** The recent bin movements, so put-away and picking leave a trail. */
    public function movements(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Warehouse::class);

        $rows = \DB::table('location_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('warehouse_locations as f', 'f.id', '=', 'm.from_location_id')
            ->leftJoin('warehouse_locations as t', 't.id', '=', 'm.to_location_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->when($request->filled('warehouse_id'), function ($q) use ($request) {
                return $q->where('m.warehouse_id', $request->warehouse_id);
            })
            ->orderByDesc('m.id')->limit(200)
            ->select(
                'm.id', 'm.qte', 'm.reason', 'm.notes', 'm.created_at',
                'p.name as product', 'p.code',
                'f.code as from_code', 't.code as to_code', 'u.username'
            )->get();

        return response()->json(['movements' => $rows]);
    }
}
