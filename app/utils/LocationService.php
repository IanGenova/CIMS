<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bin-level stock inside a warehouse.
 *
 * The deliberate constraint: product_warehouse.qte stays the authority on how
 * much stock exists. What is held here is where that stock physically sits.
 * The two are related by
 *
 *     product_warehouse.qte  >=  SUM(location_stock.qte)
 *
 * and the difference is reported as "unallocated" -- stock that has arrived but
 * has not been put away yet. That is a real warehouse state, so it is surfaced
 * rather than hidden, and it means enabling locations can never make the stock
 * figures the rest of the system relies on disagree with themselves.
 */
class LocationService
{
    private static $enabledCache = [];

    public static function forget()
    {
        self::$enabledCache = [];
    }

    /** Locations are opt-in per warehouse. */
    public static function isEnabled($warehouseId)
    {
        if (! array_key_exists($warehouseId, self::$enabledCache)) {
            self::$enabledCache[$warehouseId] = (bool) DB::table('warehouses')
                ->where('id', $warehouseId)->value('uses_locations');
        }

        return self::$enabledCache[$warehouseId];
    }

    /** Total stock the warehouse says exists for this product. */
    public static function warehouseQty($productId, $variantId, $warehouseId)
    {
        return (float) DB::table('product_warehouse')
            ->whereNull('deleted_at')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId, function ($q) use ($variantId) {
                return $q->where('product_variant_id', $variantId);
            }, function ($q) {
                return $q->whereNull('product_variant_id');
            })
            ->sum('qte');
    }

    /** How much of that is currently sitting in a bin. */
    public static function allocatedQty($productId, $variantId, $warehouseId)
    {
        return (float) DB::table('location_stock as ls')
            ->join('warehouse_locations as l', 'l.id', '=', 'ls.location_id')
            ->whereNull('l.deleted_at')
            ->where('ls.warehouse_id', $warehouseId)
            ->where('ls.product_id', $productId)
            ->when($variantId, function ($q) use ($variantId) {
                return $q->where('ls.product_variant_id', $variantId);
            }, function ($q) {
                return $q->whereNull('ls.product_variant_id');
            })
            ->sum('ls.qte');
    }

    /** Stock that has arrived but has not been put away. */
    public static function unallocatedQty($productId, $variantId, $warehouseId)
    {
        return round(
            self::warehouseQty($productId, $variantId, $warehouseId)
            - self::allocatedQty($productId, $variantId, $warehouseId),
            6
        );
    }

    /**
     * Put stock away into a bin.
     *
     * Refuses to allocate more than the warehouse actually holds, which is the
     * one rule that keeps the sub-allocation honest.
     */
    public static function putAway($productId, $variantId, $warehouseId, $locationId, $qty, $notes = null)
    {
        $qty = (float) $qty;
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Put-away quantity must be greater than zero.'],
            ]);
        }

        self::assertLocationBelongs($locationId, $warehouseId);

        $free = self::unallocatedQty($productId, $variantId, $warehouseId);
        if ($qty > $free + 0.000001) {
            throw ValidationException::withMessages([
                'quantity' => [
                    'Only ' . rtrim(rtrim(number_format($free, 6, '.', ''), '0'), '.')
                    . ' of this product is waiting to be put away in this warehouse.',
                ],
            ]);
        }

        DB::transaction(function () use ($productId, $variantId, $warehouseId, $locationId, $qty, $notes) {
            self::addToLocation($locationId, $productId, $variantId, $warehouseId, $qty);
            self::log(null, $locationId, $productId, $variantId, $warehouseId, $qty, 'putaway', $notes);
        });
    }

    /** Move stock from one bin to another inside the same warehouse. */
    public static function move($productId, $variantId, $warehouseId, $fromLocationId, $toLocationId, $qty, $notes = null)
    {
        $qty = (float) $qty;
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Move quantity must be greater than zero.'],
            ]);
        }

        if ($fromLocationId == $toLocationId) {
            throw ValidationException::withMessages([
                'to_location_id' => ['Pick a different destination bin.'],
            ]);
        }

        self::assertLocationBelongs($fromLocationId, $warehouseId);
        self::assertLocationBelongs($toLocationId, $warehouseId);

        DB::transaction(function () use ($productId, $variantId, $warehouseId, $fromLocationId, $toLocationId, $qty, $notes) {
            $row = DB::table('location_stock')
                ->where('location_id', $fromLocationId)
                ->where('product_id', $productId)
                ->when($variantId, function ($q) use ($variantId) {
                    return $q->where('product_variant_id', $variantId);
                }, function ($q) {
                    return $q->whereNull('product_variant_id');
                })
                ->lockForUpdate()->first();

            if (! $row || (float) $row->qte < $qty - 0.000001) {
                throw ValidationException::withMessages([
                    'quantity' => ['That bin does not hold enough of this product.'],
                ]);
            }

            DB::table('location_stock')->where('id', $row->id)
                ->update(['qte' => (float) $row->qte - $qty, 'updated_at' => now()]);

            self::addToLocation($toLocationId, $productId, $variantId, $warehouseId, $qty);
            self::log($fromLocationId, $toLocationId, $productId, $variantId, $warehouseId, $qty, 'move', $notes);
        });
    }

    /**
     * Take stock out of a bin, e.g. when it has been picked for a sale.
     * This reduces the bin only -- the sale itself already moved the warehouse
     * total, and decrementing that again here would double-count.
     */
    public static function pick($productId, $variantId, $warehouseId, $locationId, $qty, $notes = null)
    {
        $qty = (float) $qty;
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Pick quantity must be greater than zero.'],
            ]);
        }

        DB::transaction(function () use ($productId, $variantId, $warehouseId, $locationId, $qty, $notes) {
            $row = DB::table('location_stock')
                ->where('location_id', $locationId)
                ->where('product_id', $productId)
                ->when($variantId, function ($q) use ($variantId) {
                    return $q->where('product_variant_id', $variantId);
                }, function ($q) {
                    return $q->whereNull('product_variant_id');
                })
                ->lockForUpdate()->first();

            if (! $row || (float) $row->qte < $qty - 0.000001) {
                throw ValidationException::withMessages([
                    'quantity' => ['That bin does not hold enough of this product.'],
                ]);
            }

            DB::table('location_stock')->where('id', $row->id)
                ->update(['qte' => (float) $row->qte - $qty, 'updated_at' => now()]);

            self::log($locationId, null, $productId, $variantId, $warehouseId, $qty, 'pick', $notes);
        });
    }

    /**
     * The bins holding a product, in pick order -- the walking route.
     */
    public static function pickPath($productId, $variantId, $warehouseId)
    {
        return DB::table('location_stock as ls')
            ->join('warehouse_locations as l', 'l.id', '=', 'ls.location_id')
            ->whereNull('l.deleted_at')
            ->where('ls.warehouse_id', $warehouseId)
            ->where('ls.product_id', $productId)
            ->when($variantId, function ($q) use ($variantId) {
                return $q->where('ls.product_variant_id', $variantId);
            }, function ($q) {
                return $q->whereNull('ls.product_variant_id');
            })
            ->where('ls.qte', '>', 0)
            ->orderBy('l.pick_sequence')->orderBy('l.code')
            ->select('l.id as location_id', 'l.code', 'l.zone', 'l.aisle', 'l.rack', 'l.bin', 'ls.qte')
            ->get()->all();
    }

    // ---------------------------------------------------------------

    private static function addToLocation($locationId, $productId, $variantId, $warehouseId, $qty)
    {
        $existing = DB::table('location_stock')
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->when($variantId, function ($q) use ($variantId) {
                return $q->where('product_variant_id', $variantId);
            }, function ($q) {
                return $q->whereNull('product_variant_id');
            })
            ->lockForUpdate()->first();

        if ($existing) {
            DB::table('location_stock')->where('id', $existing->id)
                ->update(['qte' => (float) $existing->qte + $qty, 'updated_at' => now()]);
        } else {
            DB::table('location_stock')->insert([
                'location_id' => $locationId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'qte' => $qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private static function assertLocationBelongs($locationId, $warehouseId)
    {
        $ok = DB::table('warehouse_locations')
            ->whereNull('deleted_at')
            ->where('id', $locationId)
            ->where('warehouse_id', $warehouseId)
            ->exists();

        if (! $ok) {
            throw ValidationException::withMessages([
                'location_id' => ['That bin does not belong to this warehouse.'],
            ]);
        }
    }

    private static function log($from, $to, $productId, $variantId, $warehouseId, $qty, $reason, $notes)
    {
        DB::table('location_movements')->insert([
            'from_location_id' => $from,
            'to_location_id' => $to,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'qte' => $qty,
            'reason' => $reason,
            'user_id' => auth()->check() ? auth()->id() : null,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
