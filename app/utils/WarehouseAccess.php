<?php

namespace App\utils;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Which warehouses the signed-in person may work in.
 *
 * null means "all of them": anyone on all warehouses (the default), and any
 * code running without a signed-in API user -- the scheduler, the marketplace
 * sync, console commands -- which must see the whole business.
 *
 * Used by the model scopes (what lists and forms show), by the
 * EnforceWarehouseAccess middleware (what a save accepts), and by a few
 * controllers that need a deliberate exception. See WarehouseAccessTest.
 */
class WarehouseAccess
{
    /** Per request: user id => allowed ids (or null). */
    private static $cache = [];

    /** Switched off while code reads across branches on purpose. */
    private static $suspended = 0;

    public static function allowedIds()
    {
        if (self::$suspended > 0) {
            return null;
        }

        $user = Auth::guard('api')->check() ? Auth::guard('api')->user() : null;
        if (! $user) {
            return null;
        }

        if (! array_key_exists($user->id, self::$cache)) {
            $row = DB::table('users')->where('id', $user->id)->first(['all_warehouses']);
            self::$cache[$user->id] = (! $row || (int) $row->all_warehouses === 1)
                ? null
                : DB::table('user_warehouse')->where('user_id', $user->id)
                    ->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();
        }

        return self::$cache[$user->id];
    }

    public static function isRestricted()
    {
        return self::allowedIds() !== null;
    }

    public static function allows($warehouseId)
    {
        $ids = self::allowedIds();

        return $ids === null || in_array((int) $warehouseId, $ids, true);
    }

    /**
     * Refuse (403) a record that belongs to a warehouse the user may not use.
     * For things reached by id -- a hold, a till session, the sale behind a
     * wallet payment -- which the request middleware never sees (DATATEST K14).
     */
    public static function assertAllows($warehouseId)
    {
        if (! self::allows($warehouseId)) {
            throw new HttpResponseException(response()->json(['message' => 'That belongs to a warehouse you do not have access to. You can use: ' . self::names() . '.'], 403));
        }
    }

    /** "Main Warehouse, Branch Warehouse" -- for the refusal message. */
    public static function names()
    {
        $ids = self::allowedIds() ?? [];

        return DB::table('warehouses')->whereIn('id', $ids)->orderBy('id')->pluck('name')->implode(', ');
    }

    /** Run something that must see every warehouse, whoever is signed in. */
    public static function unrestricted(callable $fn)
    {
        self::$suspended++;
        try {
            return $fn();
        } finally {
            self::$suspended--;
        }
    }

    /** After a user's assignment changes, or between tests. */
    public static function forget()
    {
        self::$cache = [];
    }
}
