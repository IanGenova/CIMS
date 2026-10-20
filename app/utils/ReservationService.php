<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Durable promises: stock stays physically on hand but cannot be issued elsewhere. */
class ReservationService
{
    private static function fail($message)
    {
        throw ValidationException::withMessages(['reservation' => [$message]]);
    }

    private static function active($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public static function quantity($warehouseId, $productId, $variantId = null)
    {
        return (float) self::active(DB::table('stock_reservations'))
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->sum('quantity');
    }

    public static function batchQuantity($batchId, $warehouseId)
    {
        return (float) self::active(DB::table('stock_reservations'))
            ->where('product_batch_id', $batchId)
            ->where('warehouse_id', $warehouseId)->sum('quantity');
    }

    public static function serialReserved($serialId)
    {
        return self::active(DB::table('stock_reservations'))
            ->where('product_serial_id', $serialId)->exists();
    }

    public static function available($warehouseId, $productId, $variantId = null)
    {
        $onHand = (float) DB::table('product_warehouse')
            ->whereNull('deleted_at')->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)->where('product_variant_id', $variantId)
            ->sum('qte');
        $unreserved = max(0, $onHand - self::quantity($warehouseId, $productId, $variantId));

        if (BatchService::isTracked($productId)) {
            $lots = DB::table('batch_stock as bs')
                ->join('product_batches as b', 'b.id', '=', 'bs.product_batch_id')
                ->where('bs.warehouse_id', $warehouseId)->where('b.product_id', $productId)
                ->where('b.product_variant_id', $variantId)->whereNull('b.deleted_at')
                ->where(function ($q) {
                    $q->whereNull('b.expiry_date')->orWhereDate('b.expiry_date', '>=', now()->toDateString());
                })->get(['b.id', 'bs.qte']);
            $usable = 0.0;
            foreach ($lots as $lot) {
                $usable += max(0, (float) $lot->qte - self::batchQuantity($lot->id, $warehouseId));
            }
            return min($unreserved, $usable);
        }

        if (SerialService::isTracked($productId)) {
            $serials = DB::table('product_serials')->where('product_id', $productId)
                ->where('product_variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)->where('status', 'in_stock')->pluck('id');
            $usable = 0;
            foreach ($serials as $serialId) {
                if (! self::serialReserved($serialId)) {
                    $usable++;
                }
            }
            return min($unreserved, $usable);
        }

        return $unreserved;
    }

    /** Idempotent for one immutable order/request line. Call before any stock movement. */
    public static function reserve($ownerType, $ownerId, $warehouseId, $productId, $variantId, $quantity, $userId = null)
    {
        if (! in_array($ownerType, ['branch_request_line', 'branch_shipment', 'customer_order_line', 'external_order_line'], true)
            || (int) $ownerId < 1 || (float) $quantity <= 0) {
            self::fail('A reservation needs a valid owner and positive quantity.');
        }
        if (ProductKind::isService($productId)) {
            self::fail('Services do not have stock to reserve.');
        }

        return DB::transaction(function () use ($ownerType, $ownerId, $warehouseId, $productId, $variantId, $quantity, $userId) {
            $stock = DB::table('product_warehouse')->whereNull('deleted_at')
                ->where('warehouse_id', $warehouseId)->where('product_id', $productId)
                ->where('product_variant_id', $variantId)->lockForUpdate()->first();
            if (! $stock) {
                self::fail('This product has no stock at the chosen location.');
            }

            $prior = DB::table('stock_reservations')->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)->lockForUpdate()->get();
            if ($prior->isNotEmpty()) {
                if ($prior->every(fn ($row) => $row->status === 'active')
                    && abs((float) $prior->sum('quantity') - (float) $quantity) < 0.000001
                    && $prior->every(fn ($row) => (int) $row->warehouse_id === (int) $warehouseId
                        && (int) $row->product_id === (int) $productId
                        && ($row->product_variant_id === null ? null : (int) $row->product_variant_id)
                            === ($variantId === null ? null : (int) $variantId))) {
                    return $prior;
                }
                self::fail('This order line already has a different or closed reservation.');
            }

            $requested = (float) $quantity;
            if (self::available($warehouseId, $productId, $variantId) + 0.000001 < $requested) {
                self::fail('Not enough unreserved, sellable stock at this location.');
            }

            $allocations = [];
            if (BatchService::isTracked($productId)) {
                $lots = DB::table('batch_stock as bs')
                    ->join('product_batches as b', 'b.id', '=', 'bs.product_batch_id')
                    ->where('bs.warehouse_id', $warehouseId)->where('b.product_id', $productId)
                    ->where('b.product_variant_id', $variantId)->whereNull('b.deleted_at')
                    ->where('bs.qte', '>', 0)
                    ->where(function ($q) {
                        $q->whereNull('b.expiry_date')->orWhereDate('b.expiry_date', '>=', now()->toDateString());
                    })
                    ->orderByRaw('CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('b.expiry_date')->orderBy('b.id')->lockForUpdate()
                    ->get(['b.id', 'bs.qte']);
                $remaining = $requested;
                foreach ($lots as $lot) {
                    $free = max(0, (float) $lot->qte - self::batchQuantity($lot->id, $warehouseId));
                    $take = min($free, $remaining);
                    if ($take > 0) {
                        $allocations[] = ['product_batch_id' => $lot->id, 'product_serial_id' => null, 'quantity' => $take];
                        $remaining -= $take;
                    }
                    if ($remaining < 0.000001) break;
                }
                if ($remaining > 0.000001) self::fail('The requested quantity cannot be assigned to available lots.');
            } elseif (SerialService::isTracked($productId)) {
                if (abs($requested - round($requested)) > 0.000001) self::fail('Serial-tracked stock needs a whole-unit quantity.');
                $units = DB::table('product_serials')->where('product_id', $productId)
                    ->where('product_variant_id', $variantId)->where('warehouse_id', $warehouseId)
                    ->where('status', 'in_stock')->orderBy('received_at')->orderBy('id')
                    ->lockForUpdate()->get(['id']);
                foreach ($units as $unit) {
                    if (self::serialReserved($unit->id)) continue;
                    $allocations[] = ['product_batch_id' => null, 'product_serial_id' => $unit->id, 'quantity' => 1];
                    if (count($allocations) === (int) round($requested)) break;
                }
                if (count($allocations) !== (int) round($requested)) self::fail('The requested units cannot be assigned to available serial numbers.');
            } else {
                $allocations[] = ['product_batch_id' => null, 'product_serial_id' => null, 'quantity' => $requested];
            }

            foreach ($allocations as $allocation) {
                DB::table('stock_reservations')->insert(array_merge($allocation, [
                    'owner_type' => $ownerType, 'owner_id' => $ownerId,
                    'warehouse_id' => $warehouseId, 'product_id' => $productId,
                    'product_variant_id' => $variantId, 'status' => 'active',
                    'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            return DB::table('stock_reservations')->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)->get();
        }, 5);
    }

    public static function close($ownerType, $ownerId, $status, $userId = null, $reason = null)
    {
        if (! in_array($status, ['released', 'consumed'], true)) self::fail('Invalid reservation closure.');
        return DB::transaction(function () use ($ownerType, $ownerId, $status, $userId, $reason) {
            $rows = DB::table('stock_reservations')->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)->lockForUpdate()->get();
            if ($rows->isEmpty()) self::fail('No reservation exists for this order line.');
            if ($rows->every(fn ($row) => $row->status === $status)) return 0;
            if ($rows->contains(fn ($row) => $row->status !== 'active')) {
                self::fail('This reservation has already been closed.');
            }
            return DB::table('stock_reservations')->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)->where('status', 'active')->update([
                    'status' => $status, 'closed_by' => $userId, 'closed_at' => now(),
                    'close_reason' => $reason, 'updated_at' => now(),
                ]);
        }, 5);
    }
}
