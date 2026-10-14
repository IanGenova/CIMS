<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Warehouse;
use App\utils\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reading the stock ledger.
 *
 * StockLedger writes the movements; this is where a person gets to ask why the
 * stock is what it is, and what it is worth.
 */
class StockMovementController extends Controller
{
    /** The types StockLedger writes, with the words a user would use. */
    public static function types()
    {
        return [
            'opening' => 'Opening balance',
            'goods_receipt' => 'Goods receipt',
            'purchase' => 'Purchase',
            'sale' => 'Sale',
            'pos' => 'POS sale',
            'sale_return' => 'Sale return',
            'purchase_return' => 'Purchase return',
            'transfer' => 'Transfer',
            'transfer_in' => 'Transfer in',
            'transfer_out' => 'Transfer out',
            'void' => 'Void',
            'adjustment' => 'Adjustment',
            'stock_count' => 'Stock count',
            'production' => 'Production',
            'unknown' => 'Unrecorded',
        ];
    }

    private function label($type)
    {
        $types = self::types();

        return $types[$type] ?? ucfirst(str_replace('_', ' ', (string) $type));
    }

    /**
     * The movement ledger: what moved, when, why, and at what cost.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Stock_Alerts', Product::class);

        $perPage = (int) $request->input('limit', 25);
        $perPage = $perPage > 0 && $perPage <= 500 ? $perPage : 25;
        $page = max(1, (int) $request->input('page', 1));

        $q = DB::table('stock_movements as m')
            ->leftJoin('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id');

        if ($request->filled('product_id')) {
            $q->where('m.product_id', $request->product_id);
        }
        if ($request->filled('warehouse_id')) {
            $q->where('m.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('movement_type')) {
            $q->where('m.movement_type', $request->movement_type);
        }
        if ($request->filled('from')) {
            $q->whereDate('m.created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('m.created_at', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($w) use ($s) {
                $w->where('p.name', 'LIKE', "%{$s}%")
                    ->orWhere('p.code', 'LIKE', "%{$s}%")
                    ->orWhere('m.notes', 'LIKE', "%{$s}%");
            });
        }

        $totalRows = (clone $q)->count();

        $rows = $q->select(
            'm.id', 'm.product_id', 'm.created_at', 'm.movement_type', 'm.quantity', 'm.balance_after',
            'm.unit_cost', 'm.total_cost', 'm.reference_type', 'm.reference_id', 'm.notes',
            'p.name as product', 'p.code as code', 'w.name as warehouse', 'u.username as user'
        )
            ->orderByDesc('m.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id' => $r->id,
                'product_id' => $r->product_id,
                'date' => $r->created_at,
                'type' => $r->movement_type,
                'type_label' => $this->label($r->movement_type),
                'product' => $r->product,
                'code' => $r->code,
                'warehouse' => $r->warehouse,
                'quantity' => (float) $r->quantity,
                'balance_after' => (float) $r->balance_after,
                'unit_cost' => $r->unit_cost === null ? null : (float) $r->unit_cost,
                'total_cost' => $r->total_cost === null ? null : (float) $r->total_cost,
                'reference' => $r->reference_type ? $r->reference_type . ' #' . $r->reference_id : null,
                'user' => $r->user,
                'notes' => $r->notes,
            ];
        }

        return response()->json([
            'movements' => $data,
            'totalRows' => $totalRows,
            'types' => self::types(),
            'warehouses' => Warehouse::where('deleted_at', '=', null)->select('id', 'name')->get(),
        ]);
    }

    /**
     * One product's card: every movement against it, and where it stands now.
     */
    public function product(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Stock_Alerts', Product::class);

        $product = DB::table('products')->where('id', $id)->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $warehouseId = $request->filled('warehouse_id') ? $request->warehouse_id : null;
        $limit = (int) $request->input('limit', 200);
        $limit = $limit > 0 && $limit <= 1000 ? $limit : 200;

        $moves = StockLedger::history($id, $warehouseId, $limit);

        $warehouses = DB::table('warehouses')->pluck('name', 'id');
        $users = DB::table('users')->pluck('username', 'id');

        $data = [];
        foreach ($moves as $m) {
            $data[] = [
                'id' => $m->id,
                'date' => $m->created_at,
                'type' => $m->movement_type,
                'type_label' => $this->label($m->movement_type),
                'warehouse' => $warehouses[$m->warehouse_id] ?? null,
                'quantity' => (float) $m->quantity,
                'balance_after' => (float) $m->balance_after,
                'unit_cost' => $m->unit_cost === null ? null : (float) $m->unit_cost,
                'total_cost' => $m->total_cost === null ? null : (float) $m->total_cost,
                'reference' => $m->reference_type ? $m->reference_type . ' #' . $m->reference_id : null,
                'user' => $m->user_id ? ($users[$m->user_id] ?? null) : null,
                'notes' => $m->notes,
            ];
        }

        $stock = DB::table('product_warehouse')->where('product_id', $id)->whereNull('deleted_at')
            ->when($warehouseId, function ($q) use ($warehouseId) {
                return $q->where('warehouse_id', $warehouseId);
            })
            ->sum('qte');

        $average = StockLedger::averageCost($id);

        return response()->json([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'on_hand' => (float) $stock,
                'average_cost' => round((float) $average, 4),
                'value' => round((float) $stock * (float) $average, 2),
                'cost_updated_at' => $product->cost_updated_at ?? null,
            ],
            'movements' => $data,
            'warehouses' => Warehouse::where('deleted_at', '=', null)->select('id', 'name')->get(),
        ]);
    }

    /**
     * What the stock on hand is worth, at the moving average cost.
     */
    public function valuation(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Stock_Alerts', Product::class);

        $q = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->whereNull('pw.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('pw.qte', '>', 0);

        if ($request->filled('warehouse_id')) {
            $q->where('pw.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($w) use ($s) {
                $w->where('p.name', 'LIKE', "%{$s}%")->orWhere('p.code', 'LIKE', "%{$s}%");
            });
        }

        $rows = $q->select('p.id', 'p.name', 'p.code', 'p.cost', 'w.name as warehouse',
            DB::raw('SUM(pw.qte) as qty'))
            ->groupBy('p.id', 'p.name', 'p.code', 'p.cost', 'w.name')
            ->orderBy('p.name')
            ->get();

        $data = [];
        $totalQty = 0.0;
        $totalValue = 0.0;
        foreach ($rows as $r) {
            $qty = (float) $r->qty;
            $cost = (float) $r->cost;
            $value = round($qty * $cost, 2);
            $totalQty += $qty;
            $totalValue += $value;
            $data[] = [
                'id' => $r->id,
                'product' => $r->name,
                'code' => $r->code,
                'warehouse' => $r->warehouse,
                'quantity' => $qty,
                'average_cost' => round($cost, 4),
                'value' => $value,
            ];
        }

        return response()->json([
            'rows' => $data,
            'totalRows' => count($data),
            'total_quantity' => round($totalQty, 4),
            'total_value' => round($totalValue, 2),
            'by_warehouse' => StockLedger::valuation($request->warehouse_id),
            'warehouses' => Warehouse::where('deleted_at', '=', null)->select('id', 'name')->get(),
        ]);
    }
}
