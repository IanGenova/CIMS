<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\product_warehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{

    //----------- GET ALL  Warehouse --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Warehouse::class);

        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;

        $warehouses = Warehouse::where('deleted_at', '=', null)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%")
                        ->orWhere('mobile', 'LIKE', "%{$request->search}%")
                        ->orWhere('country', 'LIKE', "%{$request->search}%")
                        ->orWhere('city', 'LIKE', "%{$request->search}%")
                        ->orWhere('zip', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $warehouses->count();
        $warehouses = $warehouses->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'warehouses' => $warehouses,
            'totalRows' => $totalRows,
        ]);
    }

    //----------- Store new Warehouse --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Warehouse::class);

        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        request()->validate([
            'name' => 'required',
            'code' => ['nullable', 'regex:/^[A-Z0-9_-]+$/', 'max:32', Rule::unique('warehouses', 'code')],
            'site_type' => 'nullable|in:central_warehouse,main_store,branch,warehouse',
        ]);

        \DB::transaction(function () use ($request) {

            $Warehouse = new Warehouse;
            $Warehouse->name = $request['name'];
            $Warehouse->code = $request->code ?: null;
            $Warehouse->site_type = $request->input('site_type') ?: 'warehouse';
            $Warehouse->mobile = $request['mobile'];
            $Warehouse->country = $request['country'];
            $Warehouse->city = $request['city'];
            $Warehouse->zip = $request['zip'];
            $Warehouse->email = $request['email'];
            $Warehouse->save();

            if (! $Warehouse->code) {
                $baseCode = 'WH-' . $Warehouse->id;
                $code = $baseCode;
                $suffix = 2;
                while (DB::table('warehouses')->where('code', $code)->exists()) {
                    $code = $baseCode . '-' . $suffix++;
                }
                $Warehouse->code = $code;
                $Warehouse->save();
            }

            $products = Product::where('deleted_at', '=', null)
                ->pluck('id')
                ->toArray();

            if ($products) {
                foreach ($products as $product) {
                    $product_warehouse = [];
                    $Product_Variants = ProductVariant::where('product_id', $product)
                        ->where('deleted_at', null)
                        ->get();

                    if ($Product_Variants->isNotEmpty()) {
                        foreach ($Product_Variants as $product_variant) {

                            $product_warehouse[] = [
                                'product_id' => $product,
                                'warehouse_id' => $Warehouse->id,
                                'product_variant_id' => $product_variant->id,
                            ];
                        }
                    } else {
                        $product_warehouse[] = [
                            'product_id' => $product,
                            'warehouse_id' => $Warehouse->id,
                            'product_variant_id' => null,
                        ];
                    }

                    product_warehouse::insert($product_warehouse);
                }
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    //------------ function show -----------\\

    public function show($id){
        //
        
        }

    //-----------Update Warehouse --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Warehouse::class);

        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        request()->validate([
            'name' => 'required',
            'code' => ['nullable', 'regex:/^[A-Z0-9_-]+$/', 'max:32', Rule::unique('warehouses', 'code')->ignore($id)],
            'site_type' => 'nullable|in:central_warehouse,main_store,branch,warehouse',
        ]);

        $warehouse = Warehouse::findOrFail($id);
        $warehouse->fill([
            'name' => $request['name'],
            'code' => $request->code ?: $warehouse->code,
            'site_type' => $request->input('site_type') ?: $warehouse->site_type,
            'mobile' => $request['mobile'],
            'country' => $request['country'],
            'city' => $request['city'],
            'zip' => $request['zip'],
            'email' => $request['email'],
        ]);
        $warehouse->save();
        return response()->json(['success' => true]);
    }

    //----------- Delete  Warehouse --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);
        if (\App\utils\DeleteGuard::missing('warehouses', $id)) {
            return \App\utils\DeleteGuard::notFound();
        }

        // Not while it still holds stock: its stock rows would go with it.
        if ($reason = \App\utils\DeleteGuard::warehouse($id)) {
            return response()->json(['message' => $reason], 422);
        }

        \DB::transaction(function () use ($id) {

            Warehouse::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            product_warehouse::where('warehouse_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);

        // All or nothing: one warehouse still holding stock stops the lot.
        if ($reason = \App\utils\DeleteGuard::firstReason('warehouse', (array) $request->selectedIds)) {
            return response()->json(['message' => $reason], 422);
        }

        \DB::transaction(function () use ($request) {
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $warehouse_id) {
                Warehouse::whereId($warehouse_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);

                product_warehouse::where('warehouse_id', $warehouse_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    //----------- GET ALL  Warehouse --------------\\

    public function Get_Warehouses()
    {
        $Warehouses = Warehouse::where('deleted_at', '=', null)->get();
        return response()->json($Warehouses);
    }

}
