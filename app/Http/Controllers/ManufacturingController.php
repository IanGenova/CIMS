<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\ProductionOrder;
use App\utils\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\utils\DocumentSequence;

class ManufacturingController extends Controller
{
    // ==================== bills of material ====================

    public function boms(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Bom::class);

        $boms = Bom::where('deleted_at', '=', null)
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where('name', 'LIKE', "%{$request->search}%");
            })
            ->with(['product', 'items.product'])
            ->orderByDesc('id')->get();

        $data = [];
        foreach ($boms as $b) {
            $items = [];
            $cost = 0.0;
            foreach ($b->items as $i) {
                $lineCost = round((float) $i->quantity * (1 + ((float) $i->waste_percent / 100))
                    * ($i->product ? (float) $i->product->cost : 0), 4);
                $cost += $lineCost;
                $items[] = [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'product' => $i->product ? $i->product->name : null,
                    'code' => $i->product ? $i->product->code : null,
                    'quantity' => (float) $i->quantity,
                    'waste_percent' => (float) $i->waste_percent,
                    'unit_cost' => $i->product ? (float) $i->product->cost : 0,
                    'line_cost' => $lineCost,
                ];
            }

            $data[] = [
                'id' => $b->id,
                'name' => $b->name,
                'product_id' => $b->product_id,
                'product' => $b->product ? $b->product->name : null,
                'code' => $b->product ? $b->product->code : null,
                'output_quantity' => (float) $b->output_quantity,
                'is_active' => (bool) $b->is_active,
                'notes' => $b->notes,
                'items' => $items,
                'component_cost' => round($cost, 4),
                // What one finished unit costs to build, by the recipe.
                'unit_cost' => $b->output_quantity > 0 ? round($cost / (float) $b->output_quantity, 4) : 0,
            ];
        }

        return response()->json(['boms' => $data, 'totalRows' => count($data)]);
    }

    public function storeBom(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Bom::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'product_id' => 'required|integer|exists:products,id',
            'output_quantity' => 'required|numeric|gt:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        $this->assertNoSelfReference($request->product_id, $request['items']);

        \DB::transaction(function () use ($request) {
            $bom = Bom::create([
                'name' => $request->name,
                'product_id' => $request->product_id,
                'output_quantity' => $request->output_quantity,
                'is_active' => $request['is_active'] === null ? true : (bool) $request['is_active'],
                'notes' => $request['notes'],
            ]);

            $this->saveItems($bom->id, $request['items']);
        });

        return response()->json(['success' => true]);
    }

    public function updateBom(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Bom::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'product_id' => 'required|integer|exists:products,id',
            'output_quantity' => 'required|numeric|gt:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        $this->assertNoSelfReference($request->product_id, $request['items']);

        \DB::transaction(function () use ($request, $id) {
            Bom::whereId($id)->update([
                'name' => $request->name,
                'product_id' => $request->product_id,
                'output_quantity' => $request->output_quantity,
                'is_active' => (bool) $request['is_active'],
                'notes' => $request['notes'],
            ]);

            BomItem::where('bom_id', $id)->delete();
            $this->saveItems($id, $request['items']);
        });

        return response()->json(['success' => true]);
    }

    public function destroyBom(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Bom::class);

        $used = ProductionOrder::where('bom_id', $id)->where('deleted_at', '=', null)->exists();
        if ($used) {
            throw ValidationException::withMessages([
                'bom' => ['This recipe has production orders against it and cannot be deleted.'],
            ]);
        }

        \DB::transaction(function () use ($id) {
            BomItem::where('bom_id', $id)->delete();
            Bom::whereId($id)->update(['deleted_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    // ==================== production orders ====================

    public function orders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Bom::class);

        $rows = \DB::table('production_orders as o')
            ->join('boms as b', 'b.id', '=', 'o.bom_id')
            ->join('products as p', 'p.id', '=', 'o.product_id')
            ->join('warehouses as w', 'w.id', '=', 'o.warehouse_id')
            ->whereNull('o.deleted_at')
            ->when($request->filled('status'), function ($q) use ($request) {
                return $q->where('o.status', $request->status);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where(function ($x) use ($request) {
                    $x->where('o.Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('p.name', 'LIKE', "%{$request->search}%");
                });
            })
            ->orderByDesc('o.id')->limit(200)
            ->select('o.*', 'b.name as bom', 'p.name as product', 'p.code', 'w.name as warehouse')
            ->get();

        return response()->json([
            'orders' => $rows,
            'totalRows' => $rows->count(),
        ]);
    }

    public function storeOrder(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Bom::class);

        $request->validate([
            'bom_id' => 'required|integer|exists:boms,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required|numeric|gt:0',
            'date' => 'required|date',
        ]);

        $bom = Bom::findOrFail($request->bom_id);

        ProductionOrder::create([
            'Ref' => $this->nextRef(),
            'bom_id' => $bom->id,
            'product_id' => $bom->product_id,
            'warehouse_id' => $request->warehouse_id,
            'date' => $request->date,
            'quantity' => $request->quantity,
            'status' => 'planned',
            'notes' => $request['notes'],
            'user_id' => Auth::user()->id,
        ]);

        return response()->json(['success' => true]);
    }

    /** What an order needs and whether the stock is there, before committing. */
    public function requirements(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Bom::class);

        $order = ProductionOrder::findOrFail($id);
        $requirements = ProductionService::requirements($order->bom_id, $order->quantity, $order->warehouse_id);

        $short = array_values(array_filter($requirements, function ($r) {
            return $r['short'] > 0;
        }));

        return response()->json([
            'order' => $order,
            'requirements' => $requirements,
            'shortages' => $short,
            'can_build' => empty($short),
            'estimated_cost' => round(array_sum(array_column($requirements, 'line_cost')), 4),
        ]);
    }

    public function completeOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Bom::class);

        $result = ProductionService::complete($id, [
            'batch_number' => $request['batch_number'],
            'manufacture_date' => $request['manufacture_date'],
            'expiry_date' => $request['expiry_date'],
        ]);

        return response()->json(['success' => true] + $result);
    }

    public function cancelOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Bom::class);

        $order = ProductionOrder::findOrFail($id);

        if ($order->status === 'completed') {
            throw ValidationException::withMessages([
                'status' => ['A completed order cannot be cancelled; its stock has already moved.'],
            ]);
        }

        ProductionOrder::whereId($id)->update(['status' => 'cancelled']);

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------

    private function saveItems($bomId, array $items)
    {
        $seen = [];
        foreach ($items as $i) {
            if (in_array($i['product_id'], $seen)) {
                throw ValidationException::withMessages([
                    'items' => ['The same component is listed twice. Combine the quantities instead.'],
                ]);
            }
            $seen[] = $i['product_id'];

            BomItem::create([
                'bom_id' => $bomId,
                'product_id' => $i['product_id'],
                'quantity' => $i['quantity'],
                'waste_percent' => isset($i['waste_percent']) ? $i['waste_percent'] : 0,
            ]);
        }
    }

    /** A product cannot be a component of itself. */
    private function assertNoSelfReference($productId, array $items)
    {
        // What a build produces lands on the shelf: a service has no shelf, and a
        // BOM names no variant, so a variant product's output would land on a
        // variant-less row (DATATEST K23).
        $output = \DB::table('products')->where('id', $productId)->first(['name', 'is_service', 'is_variant']);
        if ($output && ($output->is_service || $output->is_variant)) {
            throw ValidationException::withMessages([
                'product_id' => [$output->is_service
                    ? "{$output->name} is a service; a recipe must produce stocked goods."
                    : "{$output->name} has variants; a recipe cannot say which variant it produces."],
            ]);
        }

        foreach ($items as $i) {
            if ((int) $i['product_id'] === (int) $productId) {
                throw ValidationException::withMessages([
                    'items' => ['A product cannot be a component of itself.'],
                ]);
            }
        }
    }

    private function nextRef()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('production_orders', 'PRD-', 6, 1);
    }
}
