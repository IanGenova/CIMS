<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * What kind of product this is, asked from everywhere stock moves.
 *
 *   service   labour, repairs, delivery: sold, never stocked. The stock row
 *             (product_warehouse) refuses to move for one, so every sale,
 *             return, transfer and adjustment path is right without each
 *             knowing about services (tests/Feature/ProductKindServiceTest).
 *   serials   every unit carries its own serial number (SerialService).
 *
 * Cached per request like BatchService::isTracked(); forget() after a change.
 */
class ProductKind
{
    /** What a product list reports as "on hand" for a service, so no sale screen caps it. */
    const SERVICE_STOCK = 999999;

    private static $cache = [];

    private static function row($productId)
    {
        if ($productId === null) {
            return null;
        }
        if (! array_key_exists($productId, self::$cache)) {
            self::$cache[$productId] = DB::table('products')->where('id', $productId)
                ->first(['is_service', 'has_serials', 'warranty_months', 'name']);
        }

        return self::$cache[$productId];
    }

    public static function isService($productId)
    {
        $row = self::row($productId);

        return $row ? (bool) $row->is_service : false;
    }

    public static function hasSerials($productId)
    {
        $row = self::row($productId);

        return $row ? (bool) $row->has_serials : false;
    }

    public static function warrantyMonths($productId)
    {
        $row = self::row($productId);

        return $row && $row->warranty_months ? (int) $row->warranty_months : 0;
    }

    public static function name($productId)
    {
        $row = self::row($productId);

        return $row ? $row->name : 'the product';
    }

    public static function forget($productId = null)
    {
        if ($productId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$productId]);
        }
    }
}
