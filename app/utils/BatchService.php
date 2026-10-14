<?php

namespace App\utils;

use App\Models\BatchMovement;
use App\Models\BatchStock;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\product_warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All batch-aware stock movement lives here.
 *
 * The invariant this class exists to hold:
 *
 *     product_warehouse.qte  ==  SUM(batch_stock.qte)
 *
 * for every batch-tracked product in every warehouse. product_warehouse stays
 * authoritative because every existing report reads it; batch_stock records
 * which lots make up that number. Both are written together, inside the
 * caller's transaction, or neither is.
 *
 * Quantities crossing this boundary are always BASE units — callers apply
 * their own unit conversion first, exactly as they already do for
 * product_warehouse.
 */
class BatchService
{
    /**
     * Set while this service is writing the derived warehouse total, so the
     * guard in product_warehouse knows the write is legitimate.
     */
    public static $syncing = false;

    /** product_id => bool. Stock writes are hot; do not re-query per row. */
    private static $trackedCache = [];

    /** Does this product opt in to batch tracking? */
    public static function isTracked($productId)
    {
        if ($productId === null) {
            return false;
        }

        if (! array_key_exists($productId, self::$trackedCache)) {
            $product = Product::find($productId);
            self::$trackedCache[$productId] = $product ? (bool) $product->has_batches : false;
        }

        return self::$trackedCache[$productId];
    }

    /**
     * May batch tracking go from what it is to $enabling? One rule for the
     * tracking switch and the product edit form (DATATEST K22): the form
     * switched it on over untracked stock, and anything could switch it off
     * while lots still held stock -- which strands those lots.
     */
    public static function assertCanSetTracking($product, $enabling)
    {
        if ($enabling && ! $product->has_batches) {
            $existing = (float) DB::table('product_warehouse')->whereNull('deleted_at')
                ->where('product_id', $product->id)->sum('qte');
            if ($existing > 0) {
                throw ValidationException::withMessages(['has_batches' => [sprintf(
                    'Cannot enable batch tracking for "%s": %s units are already in stock and belong to no batch. Clear the stock, or receive it into a batch first.',
                    $product->name, rtrim(rtrim(number_format($existing, 6, '.', ''), '0'), '.')
                )]]);
            }
        }
        if (! $enabling && $product->has_batches) {
            $inLots = (float) DB::table('batch_stock as bs')
                ->join('product_batches as b', 'b.id', '=', 'bs.product_batch_id')
                ->where('b.product_id', $product->id)->sum('bs.qte');
            if ($inLots > 0) {
                throw ValidationException::withMessages(['has_batches' => [sprintf(
                    'Cannot turn off batch tracking for "%s": %s units are still held in lots. Sell, adjust or count them out first.',
                    $product->name, rtrim(rtrim(number_format($inLots, 6, '.', ''), '0'), '.')
                )]]);
            }
        }
    }

    /** Call after toggling has_batches so the cache does not go stale. */
    public static function forget($productId = null)
    {
        if ($productId === null) {
            self::$trackedCache = [];
        } else {
            unset(self::$trackedCache[$productId]);
        }
    }

    /**
     * What to tell someone whose stock movement was refused because the path
     * cannot allocate lots yet.
     */
    public static function untrackedPathMessage($productId)
    {
        $product = Product::find($productId);

        return sprintf(
            '"%s" is batch-tracked, so its stock must move through a document that can allocate lots: a Goods Receipt, Sale, POS transaction, Transfer, Adjustment, Return or Stock Count. A purchase saved directly as Received bypasses the goods receipt step and cannot name a batch, so raise it as Ordered and receive it instead.',
            $product ? $product->name : 'This product'
        );
    }

    /**
     * Find or create a lot. Batch numbers are unique per product, so receiving
     * the same lot twice adds to it rather than duplicating it.
     */
    public static function resolveBatch(array $data)
    {
        $batch = ProductBatch::where('product_id', $data['product_id'])
            ->where('product_variant_id', $data['product_variant_id'] ?? null)
            ->where('batch_number', $data['batch_number'])
            ->first();

        if ($batch) {
            // Fill in dates if the first receipt did not carry them.
            $fill = [];
            foreach (['expiry_date', 'manufacture_date', 'provider_id', 'cost'] as $field) {
                if (empty($batch->$field) && ! empty($data[$field])) {
                    $fill[$field] = $data[$field];
                }
            }
            if ($fill) {
                $batch->update($fill);
            }

            return $batch;
        }

        return ProductBatch::create([
            'product_id' => $data['product_id'],
            'product_variant_id' => $data['product_variant_id'] ?? null,
            'batch_number' => $data['batch_number'],
            'manufacture_date' => $data['manufacture_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'provider_id' => $data['provider_id'] ?? null,
            'cost' => $data['cost'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Bring base-unit quantity into a lot, and lift the warehouse total by the
     * same amount.
     */
    public static function receive($batchId, $warehouseId, $baseQty, $movementType, $refType = null, $refId = null)
    {
        $baseQty = (float) $baseQty;

        if ($baseQty <= 0) {
            return;
        }

        $batch = ProductBatch::findOrFail($batchId);

        $stock = BatchStock::where('product_batch_id', $batchId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            $stock = new BatchStock;
            $stock->product_batch_id = $batchId;
            $stock->warehouse_id = $warehouseId;
            $stock->qte = 0;
        }

        $stock->qte = (float) $stock->qte + $baseQty;
        $stock->save();

        self::log($batchId, $warehouseId, $baseQty, $movementType, $refType, $refId);
        self::syncWarehouseTotal($batch->product_id, $batch->product_variant_id, $warehouseId);
    }

    /**
     * Take base-unit quantity out, oldest expiry first (FEFO).
     *
     * Expired lots are skipped rather than sold. If the only stock that could
     * satisfy the demand is expired, the caller gets a validation error naming
     * the problem instead of a silent short-pick.
     *
     * @return array<int,array{batch_id:int,batch_number:string,qte:float,expiry_date:?string}>
     */
    public static function allocate($productId, $variantId, $warehouseId, $baseQty, $movementType, $refType = null, $refId = null)
    {
        $baseQty = (float) $baseQty;

        if ($baseQty <= 0) {
            return [];
        }

        $today = Carbon::today()->toDateString();

        $rows = BatchStock::join('product_batches', 'product_batches.id', '=', 'batch_stock.product_batch_id')
            ->where('batch_stock.warehouse_id', $warehouseId)
            ->where('product_batches.product_id', $productId)
            ->where('product_batches.deleted_at', '=', null)
            ->when($variantId !== null, function ($q) use ($variantId) {
                return $q->where('product_batches.product_variant_id', $variantId);
            }, function ($q) {
                return $q->whereNull('product_batches.product_variant_id');
            })
            ->where('batch_stock.qte', '>', 0)
            // FEFO: soonest expiry first. Lots with no expiry go last, since a
            // dated lot should always be cleared before an undated one.
            ->orderByRaw('CASE WHEN product_batches.expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('product_batches.expiry_date', 'asc')
            ->orderBy('product_batches.id', 'asc')
            ->lockForUpdate()
            ->select('batch_stock.*', 'product_batches.batch_number', 'product_batches.expiry_date')
            ->get();

        $remaining = $baseQty;
        $allocations = [];
        $expiredAvailable = 0.0;

        foreach ($rows as $row) {
            if ($remaining <= 0.000001) {
                break;
            }

            // Never pick expired stock.
            if ($row->expiry_date && $row->expiry_date < $today) {
                $expiredAvailable += (float) $row->qte;
                continue;
            }

            $take = min((float) $row->qte, $remaining);

            // A locking read: a plain one inside the transaction returns the
            // snapshot from its first read, so a sale that committed meanwhile
            // was overwritten and the lot lost a deduction (DATATEST K12).
            $stock = BatchStock::where('id', $row->id)->lockForUpdate()->first();
            $stock->qte = (float) $stock->qte - $take;
            $stock->save();

            self::log($row->product_batch_id, $warehouseId, -1 * $take, $movementType, $refType, $refId);

            $allocations[] = [
                'batch_id' => (int) $row->product_batch_id,
                'batch_number' => $row->batch_number,
                'qte' => round($take, 6),
                'expiry_date' => $row->expiry_date,
            ];

            $remaining -= $take;
        }

        if ($remaining > 0.000001) {
            $product = Product::find($productId);
            $name = $product ? $product->name : 'the selected product';

            if ($expiredAvailable > 0) {
                throw ValidationException::withMessages([
                    'batch' => [sprintf(
                        'Not enough usable stock for "%s": %s is short, and %s of the remaining stock has expired and cannot be sold.',
                        $name, rtrim(rtrim(number_format($remaining, 6, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($expiredAvailable, 6, '.', ''), '0'), '.')
                    )],
                ]);
            }

            throw ValidationException::withMessages([
                'batch' => [sprintf(
                    'Not enough batch stock for "%s" in this warehouse: short by %s.',
                    $name, rtrim(rtrim(number_format($remaining, 6, '.', ''), '0'), '.')
                )],
            ]);
        }

        self::syncWarehouseTotal($productId, $variantId, $warehouseId);

        return $allocations;
    }

    /**
     * Take base-unit quantity out of one named lot.
     *
     * Unlike allocate(), this does NOT skip expired stock — writing expired
     * goods off is exactly why someone names a lot explicitly. The caller is
     * making a deliberate choice, so the expiry guard would be in the way.
     */
    public static function allocateFromBatch($batchId, $warehouseId, $baseQty, $movementType, $refType = null, $refId = null)
    {
        $baseQty = (float) $baseQty;

        if ($baseQty <= 0) {
            return;
        }

        $batch = ProductBatch::findOrFail($batchId);

        $stock = BatchStock::where('product_batch_id', $batchId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        $available = $stock ? (float) $stock->qte : 0.0;

        if ($available + 0.000001 < $baseQty) {
            throw ValidationException::withMessages([
                'batch' => [sprintf(
                    'Batch "%s" holds only %s in this warehouse, so %s cannot be taken from it.',
                    $batch->batch_number,
                    rtrim(rtrim(number_format($available, 6, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($baseQty, 6, '.', ''), '0'), '.')
                )],
            ]);
        }

        $stock->qte = $available - $baseQty;
        $stock->save();

        self::log($batchId, $warehouseId, -1 * $baseQty, $movementType, $refType, $refId);
        self::syncWarehouseTotal($batch->product_id, $batch->product_variant_id, $warehouseId);
    }

    /**
     * Move base-unit quantity between warehouses, preserving lot identity.
     *
     * A lot does not become a different lot by changing shelf, so the same
     * batches that leave the source arrive at the destination — expiry dates
     * and traceability travel with the goods. Picking is FEFO out of the
     * source, and expired lots are left behind.
     */
    public static function transferBetweenWarehouses($productId, $variantId, $fromWarehouseId, $toWarehouseId, $baseQty, $refType = null, $refId = null)
    {
        $allocations = self::allocate(
            $productId,
            $variantId,
            $fromWarehouseId,
            $baseQty,
            'transfer_out',
            $refType,
            $refId
        );

        foreach ($allocations as $allocation) {
            self::receive(
                $allocation['batch_id'],
                $toWarehouseId,
                $allocation['qte'],
                'transfer_in',
                $refType,
                $refId
            );
        }

        return $allocations;
    }

    /**
     * Set a lot's quantity in a warehouse to an absolute figure.
     *
     * This is the stock-take case: the physical count is the authority, so the
     * lot is written to what was actually found rather than adjusted by a
     * delta. The difference is still recorded as a movement, so the ledger
     * shows the variance and the count can be reversed like any other document.
     */
    public static function setBatchQuantity($batchId, $warehouseId, $countedQty, $movementType, $refType = null, $refId = null)
    {
        $countedQty = (float) $countedQty;

        if ($countedQty < 0) {
            throw ValidationException::withMessages([
                'batch' => ['A counted quantity cannot be negative.'],
            ]);
        }

        $batch = ProductBatch::findOrFail($batchId);

        $stock = BatchStock::where('product_batch_id', $batchId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            $stock = new BatchStock;
            $stock->product_batch_id = $batchId;
            $stock->warehouse_id = $warehouseId;
            $stock->qte = 0;
        }

        $variance = $countedQty - (float) $stock->qte;

        if (abs($variance) < 0.000001) {
            return 0.0;
        }

        $stock->qte = $countedQty;
        $stock->save();

        self::log($batchId, $warehouseId, $variance, $movementType, $refType, $refId);
        self::syncWarehouseTotal($batch->product_id, $batch->product_variant_id, $warehouseId);

        return round($variance, 6);
    }

    /**
     * Every lot of a product currently held in a warehouse, oldest expiry
     * first. Used to build a count sheet, which must list each lot separately
     * rather than one merged figure per product.
     */
    public static function lotsInWarehouse($productId, $variantId, $warehouseId, $includeEmpty = false)
    {
        return BatchStock::join('product_batches', 'product_batches.id', '=', 'batch_stock.product_batch_id')
            ->where('batch_stock.warehouse_id', $warehouseId)
            ->where('product_batches.product_id', $productId)
            ->where('product_batches.deleted_at', '=', null)
            ->when($variantId !== null, function ($q) use ($variantId) {
                return $q->where('product_batches.product_variant_id', $variantId);
            }, function ($q) {
                return $q->whereNull('product_batches.product_variant_id');
            })
            ->when(! $includeEmpty, function ($q) {
                return $q->where('batch_stock.qte', '>', 0);
            })
            ->orderByRaw('CASE WHEN product_batches.expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('product_batches.expiry_date', 'asc')
            ->orderBy('product_batches.id', 'asc')
            ->select(
                'batch_stock.qte',
                'product_batches.id as batch_id',
                'product_batches.batch_number',
                'product_batches.expiry_date'
            )
            ->get();
    }

    /**
     * Undo everything a document did, by replaying its own ledger entries
     * inverted. Exact by construction: no guessing which lots were used.
     */
    public static function reverse($refType, $refId)
    {
        // What the document still holds in each lot: everything it moved, less
        // whatever was already reversed. Reversing the raw movements every time
        // (as this did) undid an edited or re-deleted document again on every
        // call, and the lots drifted further each time (DATATEST K10).
        $outstanding = BatchMovement::where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->groupBy('product_batch_id', 'warehouse_id')
            ->selectRaw('product_batch_id, warehouse_id, SUM(qte) AS net')
            ->get()
            ->filter(function ($row) {
                return abs((float) $row->net) > 0.000001;
            });

        $touched = [];

        foreach ($outstanding as $row) {
            $stock = BatchStock::where('product_batch_id', $row->product_batch_id)
                ->where('warehouse_id', $row->warehouse_id)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = new BatchStock;
                $stock->product_batch_id = $row->product_batch_id;
                $stock->warehouse_id = $row->warehouse_id;
                $stock->qte = 0;
            }

            $stock->qte = (float) $stock->qte - (float) $row->net;
            $stock->save();

            self::log(
                $row->product_batch_id,
                $row->warehouse_id,
                -1 * (float) $row->net,
                'reversal',
                $refType,
                $refId
            );

            $batch = ProductBatch::withTrashed()->find($row->product_batch_id);
            if ($batch) {
                $touched[$batch->product_id . ':' . $batch->product_variant_id . ':' . $row->warehouse_id] =
                    [$batch->product_id, $batch->product_variant_id, $row->warehouse_id];
            }
        }

        foreach ($touched as [$productId, $variantId, $warehouseId]) {
            self::syncWarehouseTotal($productId, $variantId, $warehouseId);
        }

        return count($outstanding);
    }

    /**
     * Re-derive product_warehouse.qte from the sum of its lots. This is what
     * keeps the two layers honest; every mutation above ends by calling it.
     */
    public static function syncWarehouseTotal($productId, $variantId, $warehouseId)
    {
        $total = BatchStock::join('product_batches', 'product_batches.id', '=', 'batch_stock.product_batch_id')
            ->where('batch_stock.warehouse_id', $warehouseId)
            ->where('product_batches.product_id', $productId)
            ->where('product_batches.deleted_at', '=', null)
            ->when($variantId !== null, function ($q) use ($variantId) {
                return $q->where('product_batches.product_variant_id', $variantId);
            }, function ($q) {
                return $q->whereNull('product_batches.product_variant_id');
            })
            ->sum('batch_stock.qte');

        $warehouseStock = product_warehouse::where('deleted_at', '=', null)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->when($variantId !== null, function ($q) use ($variantId) {
                return $q->where('product_variant_id', $variantId);
            })
            ->lockForUpdate()
            ->first();

        if (! $warehouseStock) {
            $warehouseStock = new product_warehouse;
            $warehouseStock->product_id = $productId;
            $warehouseStock->product_variant_id = $variantId;
            $warehouseStock->warehouse_id = $warehouseId;
        }

        $warehouseStock->qte = round((float) $total, 6);

        // This is the one write the guard in product_warehouse permits.
        self::$syncing = true;
        try {
            $warehouseStock->save();
        } finally {
            self::$syncing = false;
        }

        return (float) $warehouseStock->qte;
    }

    private static function log($batchId, $warehouseId, $qty, $movementType, $refType, $refId)
    {
        BatchMovement::create([
            'product_batch_id' => $batchId,
            'warehouse_id' => $warehouseId,
            'qte' => $qty,
            'movement_type' => $movementType,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'user_id' => Auth::check() ? Auth::user()->id : null,
            'created_at' => Carbon::now(),
        ]);
    }
}
