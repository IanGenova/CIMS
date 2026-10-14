<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * One definition of "low stock", for the badge, the bell and the list
 * (DATATEST K83): a shelf (product x warehouse) at or below the product's
 * alert level. Services are never stocked, so never low; deleted products and
 * shelves are not counted.
 *
 * The three used to disagree -- the badge counted services, the bell skipped
 * products whose alert level is zero -- so the bell said 1 while the list
 * showed 2.
 */
class LowStock
{
    /** Shelves that are low, optionally limited to some warehouses. */
    public static function shelves(array $warehouseIds = null)
    {
        return DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->whereNull('pw.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_service', false)
            ->whereColumn('pw.qte', '<=', 'p.stock_alert')
            ->when($warehouseIds !== null, function ($q) use ($warehouseIds) {
                $q->whereIn('pw.warehouse_id', $warehouseIds ?: [0]);
            });
    }
}
