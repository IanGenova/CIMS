<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\utils\SerialService;
use App\utils\WarehouseAccess;
use Illuminate\Http\Request;

/** Finding a unit by its serial number, and the units on a shelf (App\utils\SerialService). */
class SerialController extends Controller
{
    /** Where a serial is, who bought it, and whether it is under warranty. */
    public function lookup(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        $request->validate(['serial' => 'required|string|max:100']);

        return response()->json(['serials' => SerialService::lookup($request->serial)]);
    }

    /** The units of a product on one branch's shelf, for the picker. */
    public function available(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        $request->validate(['product_id' => 'required|integer', 'warehouse_id' => 'required|integer']);

        if (! WarehouseAccess::allows($request->warehouse_id)) {
            return response()->json(['message' => 'You can only work in: ' . implode(', ', WarehouseAccess::names()) . '.'], 403);
        }

        return response()->json(['serials' => SerialService::available(
            (int) $request->product_id, (int) $request->warehouse_id, $request->product_variant_id ?: null
        )]);
    }
}
