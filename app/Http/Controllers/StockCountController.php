<?php

namespace App\Http\Controllers;

use App\utils\BatchService;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\StockCount;
use App\Models\StockCountDetail;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\utils\DocumentSequence;

/**
 * Stock take / cycle count.
 *
 * Lifecycle:
 *   draft        created, system quantities snapshotted
 *   in_progress  counts being entered
 *   completed    variances applied to stock, count frozen
 *   cancelled    abandoned, stock untouched
 *
 * Applying the variance sets stock to the counted figure rather than adding a
 * delta, because the physical count is the authority. Every write goes through
 * the product_warehouse model, so the negative-stock guard and the audit trail
 * both still apply.
 */
class StockCountController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StockCount::class);

        $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
            'SortType' => 'nullable|in:asc,desc',
        ]);

        $perPage = (int) $request->input('limit', 10);

        $query = StockCount::where('deleted_at', '=', null)
            ->when($request->filled('status'), function ($q) use ($request) {
                return $q->where('status', $request->status);
            })
            ->when($request->filled('warehouse_id'), function ($q) use ($request) {
                return $q->where('warehouse_id', $request->warehouse_id);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where('Ref', 'like', '%' . $request->search . '%');
            });

        $totalRows = $query->count();

        $counts = $query->with(['warehouse', 'user'])
            ->orderBy('id', $request->input('SortType', 'desc'))
            ->offset(($request->input('page', 1) - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $data = [];
        foreach ($counts as $count) {
            $data[] = [
                'id' => $count->id,
                'Ref' => $count->Ref,
                'date' => $count->date,
                'warehouse' => $count->warehouse ? $count->warehouse->name : '—',
                'user' => $count->user ? $count->user->username : '—',
                'status' => $count->status,
                'lines' => $count->details()->count(),
                'counted' => $count->details()->whereNotNull('counted_qte')->count(),
            ];
        }

        return response()->json([
            'stock_counts' => $data,
            'totalRows' => $totalRows,
            'warehouses' => Warehouse::where('deleted_at', '=', null)->get(['id', 'name']),
            // For narrowing a new count. The screen used to borrow these from
            // Products/create, which needs products_add, so a stockman with
            // only the stock count permissions was refused (StockCountAccessTest).
            'categories' => DB::table('categories')->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Open a count. Snapshots the current system quantity for every product
     * held in the warehouse, so later variance is measured against what the
     * system believed at this moment.
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', StockCount::class);

        $request->validate([
            'date' => 'required|date',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'notes' => 'nullable|string',
        ]);

        // One open count per shelf: two counts of the same warehouse and category
        // would each apply their own variance (DATATEST K26). A whole-warehouse
        // count overlaps every category count of that warehouse.
        $overlap = StockCount::where('warehouse_id', $request->warehouse_id)
            ->whereIn('status', ['draft', 'in_progress'])
            ->where(function ($q) use ($request) {
                $q->whereNull('category_id');
                if ($request->filled('category_id')) {
                    $q->orWhere('category_id', $request->category_id);
                }
            })
            ->first();
        if ($overlap) {
            return response()->json(['message' => "Count {$overlap->Ref} of this warehouse is still open. Complete or cancel it first."], 422);
        }

        $count = \DB::transaction(function () use ($request) {
            $count = StockCount::create([
                'Ref' => $this->getNumberOrder(),
                'date' => $request->date,
                'warehouse_id' => $request->warehouse_id,
                'category_id' => $request->category_id,
                'user_id' => Auth::user()->id,
                'status' => 'draft',
                'notes' => $request->notes,
            ]);

            $stockRows = product_warehouse::where('deleted_at', '=', null)
                ->where('warehouse_id', $request->warehouse_id)
                ->when($request->filled('category_id'), function ($q) use ($request) {
                    return $q->whereIn('product_id', Product::where('category_id', $request->category_id)
                        ->where('deleted_at', '=', null)
                        ->pluck('id'));
                })
                ->get();

            $lines = [];
            foreach ($stockRows as $row) {
                // A batch-tracked product is counted lot by lot. "42 on the
                // shelf" is not auditable when three lots with different
                // expiry dates are sitting there, so each lot gets its own
                // line to be counted against.
                if (BatchService::isTracked($row->product_id)) {
                    $lots = BatchService::lotsInWarehouse(
                        $row->product_id,
                        $row->product_variant_id,
                        $request->warehouse_id
                    );

                    foreach ($lots as $lot) {
                        $lines[] = [
                            'stock_count_id' => $count->id,
                            'product_id' => $row->product_id,
                            'product_variant_id' => $row->product_variant_id,
                            'product_batch_id' => $lot->batch_id,
                            'system_qte' => $lot->qte,
                            'counted_qte' => null,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ];
                    }

                    continue;
                }

                $lines[] = [
                    'stock_count_id' => $count->id,
                    'product_id' => $row->product_id,
                    'product_variant_id' => $row->product_variant_id,
                    'product_batch_id' => null,
                    'system_qte' => $row->qte,
                    'counted_qte' => null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            }

            if (! empty($lines)) {
                StockCountDetail::insert($lines);
            }

            return $count;
        }, 10);

        return response()->json([
            'success' => true,
            'id' => $count->id,
            'Ref' => $count->Ref,
            'lines' => $count->details()->count(),
        ]);
    }

    /** The count sheet: every line with system, counted and variance. */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StockCount::class);

        $count = StockCount::with(['warehouse', 'user'])->findOrFail($id);

        $lines = [];
        foreach ($count->details()->with(['product', 'batch'])->get() as $line) {
            $lines[] = [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_variant_id' => $line->product_variant_id,
                'code' => $line->product ? $line->product->code : null,
                'name' => $line->product ? $line->product->name : '(deleted product)',
                // Null for products that are not batch-tracked.
                'batch_id' => $line->product_batch_id,
                'batch_number' => $line->batch ? $line->batch->batch_number : null,
                'expiry_date' => $line->batch ? optional($line->batch->expiry_date)->toDateString() : null,
                'expiry_status' => $line->batch ? $line->batch->expiry_status : null,
                'system_qte' => (float) $line->system_qte,
                'counted_qte' => $line->counted_qte === null ? null : (float) $line->counted_qte,
                'variance' => $line->variance,
                'notes' => $line->notes,
            ];
        }

        return response()->json([
            'stock_count' => [
                'id' => $count->id,
                'Ref' => $count->Ref,
                'date' => $count->date,
                'status' => $count->status,
                'warehouse' => $count->warehouse ? $count->warehouse->name : '—',
                'warehouse_id' => $count->warehouse_id,
                'user' => $count->user ? $count->user->username : '—',
                'notes' => $count->notes,
            ],
            'details' => $lines,
        ]);
    }

    /** Record physical counts. Allowed only before the count is completed. */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', StockCount::class);

        $request->validate([
            'details' => 'required|array|min:1',
            'details.*.id' => 'required|integer',
            'details.*.counted_qte' => 'nullable|numeric|min:0',
            'details.*.notes' => 'nullable|string',
        ]);

        $count = StockCount::findOrFail($id);

        if (in_array($count->status, ['completed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'This count is ' . $count->status . ' and can no longer be edited.',
            ], 422);
        }

        // A line from another count is refused, not silently ignored (DATATEST K75).
        $ids = collect($request['details'])->pluck('id')->map(fn ($v) => (int) $v)->unique();
        $own = StockCountDetail::where('stock_count_id', $count->id)->whereIn('id', $ids)->count();
        if ($own !== $ids->count()) {
            return response()->json(['message' => 'One or more lines do not belong to this count.'], 422);
        }

        \DB::transaction(function () use ($request, $count) {
            foreach ($request['details'] as $line) {
                $values = [
                    'counted_qte' => $line['counted_qte'],
                    'notes' => $line['notes'] ?? null,
                    'updated_at' => Carbon::now(),
                ];
                // What the system showed when this line was counted: completion
                // applies (counted - this) to whatever the shelf holds by then, so
                // sales made while the count is open stay sold (DATATEST K26).
                if ($line['counted_qte'] !== null) {
                    $detail = StockCountDetail::where('id', $line['id'])->where('stock_count_id', $count->id)->first();
                    if ($detail) {
                        $values['system_qte'] = $this->onHand($detail, $count->warehouse_id);
                    }
                }
                StockCountDetail::where('id', $line['id'])
                    ->where('stock_count_id', $count->id)
                    ->update($values);
            }

            if ($count->status === 'draft') {
                $count->update(['status' => 'in_progress']);
            }
        }, 10);

        return response()->json(['success' => true]);
    }

    /**
     * Apply the variances and freeze the count.
     *
     * Uncounted lines are left alone — not treated as zero — because "nobody
     * counted this" and "there are none" are different statements.
     */
    public function complete(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', StockCount::class);

        $count = StockCount::findOrFail($id);

        if ($count->status === 'completed') {
            return response()->json(['message' => 'This count is already completed.'], 422);
        }

        if ($count->status === 'cancelled') {
            return response()->json(['message' => 'This count was cancelled.'], 422);
        }

        $applied = \DB::transaction(function () use ($count) {
            // Re-read under a lock: a cancel racing this completion must lose
            // (DATATEST K25: both succeeded, and a cancelled count still moved stock).
            $locked = StockCount::where('id', $count->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'completed' || $locked->status === 'cancelled') {
                throw \Illuminate\Validation\ValidationException::withMessages(['status' => ['This count is already ' . ($locked->status ?? 'gone') . '.']]);
            }

            $applied = 0;

            $lines = StockCountDetail::where('stock_count_id', $count->id)
                ->whereNotNull('counted_qte')
                ->get();

            // A count cannot say which serial-numbered unit is missing or found.
            \App\utils\SerialService::refuseIfTracked($lines->pluck('product_id')->all(), 'a stock count',
                'Record a missing or found unit with an adjustment, which names the serial number.');

            foreach ($lines as $line) {
                // Batch-tracked lines are counted against one lot, so the
                // count is written to that lot and the warehouse total is
                // re-derived from its lots.
                // The difference found, applied to the shelf as it is now.
                $found = (float) $line->counted_qte - (float) $line->system_qte;

                if ($line->product_batch_id) {
                    $lotNow = (float) \DB::table('batch_stock')->where('product_batch_id', $line->product_batch_id)
                        ->where('warehouse_id', $count->warehouse_id)->lockForUpdate()->value('qte');
                    $variance = BatchService::setBatchQuantity(
                        $line->product_batch_id,
                        $count->warehouse_id,
                        max(0, $lotNow + $found),
                        'stock_count',
                        'StockCount',
                        $count->id
                    );

                    if ($variance != 0.0) {
                        $applied++;
                    }

                    continue;
                }

                $stock = product_warehouse::where('deleted_at', '=', null)
                    ->where('warehouse_id', $count->warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->when($line->product_variant_id !== null, function ($q) use ($line) {
                        return $q->where('product_variant_id', $line->product_variant_id);
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    continue;
                }

                if (abs($found) < 0.000001) {
                    continue;
                }

                // The physical count is the authority for what was missing or
                // found; sales since the line was counted stay sold.
                $stock->qte = max(0, (float) $stock->qte + $found);
                $stock->save();
                $applied++;
            }

            $count->update([
                'status' => 'completed',
                'completed_at' => Carbon::now(),
                'completed_by' => Auth::user()->id,
            ]);

            // News to the managers only when the count changed the shelf.
            \App\utils\ActivityNotifier::stockCountCompleted($count->id, $applied);

            return $applied;
        }, 10);

        return response()->json([
            'success' => true,
            'adjusted_lines' => $applied,
        ]);
    }

    /** Abandon a count without touching stock. */
    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', StockCount::class);

        StockCount::findOrFail($id);

        // Under a lock, re-checked inside the transaction: a completion racing
        // this cancel must not leave a "cancelled" count whose variance was
        // applied (DATATEST K25).
        $refused = \DB::transaction(function () use ($id) {
            $count = StockCount::where('id', $id)->lockForUpdate()->first();
            if ($count->status === 'completed') {
                return 'A completed count cannot be cancelled; its adjustments are already applied.';
            }
            $count->update(['status' => 'cancelled']);

            return null;
        });

        if ($refused) {
            return response()->json(['message' => $refused], 422);
        }

        return response()->json(['success' => true]);
    }

    /** What the system holds for a count line right now (its lot, or the shelf). */
    private function onHand(StockCountDetail $line, $warehouseId)
    {
        if ($line->product_batch_id) {
            return (float) \DB::table('batch_stock')->where('product_batch_id', $line->product_batch_id)
                ->where('warehouse_id', $warehouseId)->value('qte');
        }

        return (float) product_warehouse::where('deleted_at', '=', null)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $line->product_id)
            ->when($line->product_variant_id !== null, function ($q) use ($line) {
                return $q->where('product_variant_id', $line->product_variant_id);
            })
            ->value('qte');
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', StockCount::class);

        $count = StockCount::findOrFail($id);

        if ($count->status === 'completed') {
            return response()->json([
                'message' => 'A completed count is part of the stock history and cannot be deleted.',
            ], 422);
        }

        $count->delete();

        return response()->json(['success' => true]);
    }

    /** Sequential reference, matching the convention used elsewhere. */
    public function getNumberOrder()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('stock_counts', 'SC_', 0, 1111);
    }
}
