<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopSettingsController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $setting = Setting::firstOrFail();
        $warehouses = DB::table('warehouses')->whereNull('deleted_at')
            ->whereIn('site_type', ['central_warehouse', 'main_store', 'branch'])
            ->orderBy('name')->get(['id', 'code', 'name', 'site_type']);
        return response()->json(['shop_enabled' => (bool) $setting->shop_enabled,
            'shop_warehouse_id' => $setting->shop_warehouse_id,
            'shop_name' => $setting->shop_name ?: $setting->CompanyName,
            'warehouses' => $warehouses, 'url' => url('/shop')]);
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $request->validate([
            'shop_enabled' => 'required|boolean',
            'shop_warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'shop_name' => 'required|string|min:2|max:120',
        ]);
        $warehouse = $request->shop_warehouse_id ? DB::table('warehouses')
            ->where('id', $request->shop_warehouse_id)->whereNull('deleted_at')->first() : null;
        if ($request->boolean('shop_enabled') && (! $warehouse
            || ! in_array($warehouse->site_type, ['central_warehouse', 'main_store', 'branch'], true))) {
            throw ValidationException::withMessages([
                'shop_warehouse_id' => ['Choose an active selling or fulfillment location before enabling the shop.'],
            ]);
        }
        $setting = Setting::firstOrFail();
        $setting->shop_enabled = $request->boolean('shop_enabled');
        $setting->shop_warehouse_id = $warehouse ? $warehouse->id : null;
        $setting->shop_name = trim($request->shop_name);
        $setting->save();
        return $this->show($request);
    }
}
