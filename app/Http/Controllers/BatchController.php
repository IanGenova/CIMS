<?php

namespace App\Http\Controllers;

use App\Models\BatchMovement;
use App\Models\BatchStock;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Warehouse;
use App\utils\BatchService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Batch / lot listing, expiry reporting and traceability.
 *
 * Batches are created by receiving stock, not here — a lot that exists but was
 * never received would be a lot with no provenance. This exposes what is held,
 * what is about to expire, and where any given lot went.
 */
class BatchController extends BaseController
{
    /** Lots currently held, with quantity and expiry status. */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'status' => 'nullable|in:expired,expiring,ok,none',
            'in_stock_only' => 'nullable|boolean',
        ]);

        $perPage = (int) $request->input('limit', 25);

        $query = ProductBatch::with('product')
            ->where('product_batches.deleted_at', '=', null)
            ->when($request->filled('product_id'), function ($q) use ($request) {
                return $q->where('product_id', $request->product_id);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where('batch_number', 'like', '%' . $request->search . '%');
            });

        $batches = $query->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiry_date', 'asc')
            ->get();

        $warehouseId = $request->input('warehouse_id');
        $inStockOnly = $request->boolean('in_stock_only', true);

        $rows = [];
        foreach ($batches as $batch) {
            $qte = BatchStock::where('product_batch_id', $batch->id)
                ->when($warehouseId, function ($q) use ($warehouseId) {
                    return $q->where('warehouse_id', $warehouseId);
                })
                ->sum('qte');

            if ($inStockOnly && (float) $qte <= 0) {
                continue;
            }

            $status = $batch->expiry_status;

            if ($request->filled('status') && $status !== $request->status) {
                continue;
            }

            $rows[] = [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'product_id' => $batch->product_id,
                'product' => $batch->product ? $batch->product->name : '(deleted product)',
                'code' => $batch->product ? $batch->product->code : null,
                'manufacture_date' => optional($batch->manufacture_date)->toDateString(),
                'expiry_date' => optional($batch->expiry_date)->toDateString(),
                'days_to_expiry' => $batch->days_to_expiry,
                'status' => $status,
                'qte' => (float) $qte,
            ];
        }

        $total = count($rows);
        $page = max(1, (int) $request->input('page', 1));
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'batches' => $rows,
            'totalRows' => $total,
            'warehouses' => Warehouse::where('deleted_at', '=', null)->get(['id', 'name']),
        ]);
    }

    /**
     * The expiry report: what has already expired and what is inside its alert
     * window, with the value at risk.
     */
    public function expiring(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $request->validate([
            'days' => 'nullable|integer|min:0|max:3650',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $warehouseId = $request->input('warehouse_id');
        $today = Carbon::today();
        // Explicit window, or fall back to each product's own alert setting.
        $days = $request->filled('days') ? (int) $request->days : null;

        $batches = ProductBatch::with('product')
            ->where('deleted_at', '=', null)
            ->whereNotNull('expiry_date')
            ->orderBy('expiry_date', 'asc')
            ->get();

        $expired = [];
        $expiring = [];
        $valueExpired = 0.0;
        $valueExpiring = 0.0;

        foreach ($batches as $batch) {
            $qte = (float) BatchStock::where('product_batch_id', $batch->id)
                ->when($warehouseId, function ($q) use ($warehouseId) {
                    return $q->where('warehouse_id', $warehouseId);
                })
                ->sum('qte');

            if ($qte <= 0) {
                continue;
            }

            $daysLeft = $today->diffInDays($batch->expiry_date, false);
            $window = $days !== null
                ? $days
                : ($batch->product && $batch->product->expiry_alert_days !== null
                    ? (int) $batch->product->expiry_alert_days
                    : 30);

            $cost = (float) ($batch->cost ?: ($batch->product ? $batch->product->cost : 0));
            $value = round($qte * $cost, 4);

            $row = [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'product_id' => $batch->product_id,
                'product' => $batch->product ? $batch->product->name : '(deleted product)',
                'code' => $batch->product ? $batch->product->code : null,
                'expiry_date' => $batch->expiry_date->toDateString(),
                'days_to_expiry' => $daysLeft,
                'qte' => $qte,
                'unit_cost' => $cost,
                'value' => $value,
            ];

            if ($daysLeft < 0) {
                $expired[] = $row;
                $valueExpired += $value;
            } elseif ($daysLeft <= $window) {
                $expiring[] = $row;
                $valueExpiring += $value;
            }
        }

        return response()->json([
            'expired' => $expired,
            'expiring' => $expiring,
            'summary' => [
                'expired_batches' => count($expired),
                'expiring_batches' => count($expiring),
                'value_expired' => round($valueExpired, 2),
                'value_expiring' => round($valueExpiring, 2),
            ],
            'warehouses' => Warehouse::where('deleted_at', '=', null)->get(['id', 'name']),
        ]);
    }

    /**
     * Traceability: everything that ever happened to one lot, and which
     * documents consumed it. This is what a recall needs.
     */
    public function trace(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $batch = ProductBatch::with(['product', 'provider'])->findOrFail($id);

        $movements = BatchMovement::where('product_batch_id', $id)
            ->orderBy('id', 'asc')
            ->get();

        $history = [];
        foreach ($movements as $movement) {
            $history[] = [
                'id' => $movement->id,
                'date' => optional($movement->created_at)->toDateTimeString(),
                'type' => $movement->movement_type,
                'qte' => (float) $movement->qte,
                'warehouse_id' => $movement->warehouse_id,
                'reference' => $movement->reference_type
                    ? $movement->reference_type . ' #' . $movement->reference_id
                    : null,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
            ];
        }

        // Which documents took stock out of this lot.
        $consumers = BatchMovement::where('product_batch_id', $id)
            ->where('qte', '<', 0)
            ->whereNotNull('reference_id')
            ->select('reference_type', 'reference_id', DB::raw('SUM(qte) as total'))
            ->groupBy('reference_type', 'reference_id')
            ->get()
            ->map(function ($row) {
                return [
                    'reference_type' => $row->reference_type,
                    'reference_id' => $row->reference_id,
                    'qte' => abs((float) $row->total),
                ];
            });

        $stock = BatchStock::where('product_batch_id', $id)
            ->with('warehouse')
            ->get()
            ->map(function ($row) {
                return [
                    'warehouse_id' => $row->warehouse_id,
                    'warehouse' => $row->warehouse ? $row->warehouse->name : '—',
                    'qte' => (float) $row->qte,
                ];
            });

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'product' => $batch->product ? $batch->product->name : '(deleted product)',
                'code' => $batch->product ? $batch->product->code : null,
                'manufacture_date' => optional($batch->manufacture_date)->toDateString(),
                'expiry_date' => optional($batch->expiry_date)->toDateString(),
                'days_to_expiry' => $batch->days_to_expiry,
                'status' => $batch->expiry_status,
                'supplier' => $batch->provider ? $batch->provider->name : null,
                'cost' => $batch->cost === null ? null : (float) $batch->cost,
                'notes' => $batch->notes,
            ],
            'stock_on_hand' => $stock,
            'consumed_by' => $consumers,
            'movements' => $history,
        ]);
    }

    /** Turn batch tracking on or off for a product. */
    public function setTracking(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);

        $request->validate([
            'has_batches' => 'required|boolean',
            'expiry_alert_days' => 'nullable|integer|min:0|max:3650',
        ]);

        $product = Product::findOrFail($id);
        $enabling = $request->boolean('has_batches');

        // Not on over untracked stock (it would belong to no lot), and not off
        // while lots still hold stock (DATATEST K22).
        BatchService::assertCanSetTracking($product, $enabling);

        $product->has_batches = $enabling;
        if ($request->filled('expiry_alert_days')) {
            $product->expiry_alert_days = $request->expiry_alert_days;
        }
        $product->save();

        BatchService::forget($product->id);

        return response()->json(['success' => true, 'has_batches' => (bool) $product->has_batches]);
    }

    /**
     * Integrity check: product_warehouse.qte must equal the sum of the lots
     * beneath it for every tracked product. Reports drift rather than hiding it.
     */
    public function verify(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $tracked = Product::where('has_batches', 1)->where('deleted_at', '=', null)->pluck('id');

        $drift = [];
        $checked = 0;

        foreach ($tracked as $productId) {
            $rows = DB::table('product_warehouse')
                ->whereNull('deleted_at')
                ->where('product_id', $productId)
                ->get();

            foreach ($rows as $row) {
                $checked++;

                $batchTotal = (float) BatchStock::join('product_batches', 'product_batches.id', '=', 'batch_stock.product_batch_id')
                    ->where('batch_stock.warehouse_id', $row->warehouse_id)
                    ->where('product_batches.product_id', $productId)
                    ->where('product_batches.deleted_at', '=', null)
                    ->sum('batch_stock.qte');

                if (abs($batchTotal - (float) $row->qte) > 0.000001) {
                    $drift[] = [
                        'product_id' => $productId,
                        'warehouse_id' => $row->warehouse_id,
                        'warehouse_total' => (float) $row->qte,
                        'batch_total' => $batchTotal,
                        'difference' => round((float) $row->qte - $batchTotal, 6),
                    ];
                }
            }
        }

        return response()->json([
            'tracked_products' => count($tracked),
            'stock_rows_checked' => $checked,
            'in_sync' => empty($drift),
            'drift' => $drift,
        ]);
    }
}
