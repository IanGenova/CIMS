<?php

namespace App\Http\Controllers;

use App\Exports\ProductsExport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\product_warehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use App\utils\ForeignKeyInput;
use App\utils\helpers;
use App\utils\VatService;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use \Gumlet\ImageResize;

class ProductsController extends BaseController
{

    //------------ Get ALL Products --------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();
        // Filter fields With Params to retrieve
        $columns = array(0 => 'name', 1 => 'category_id', 2 => 'brand_id', 3 => 'code');
        $param = array(0 => 'like', 1 => '=', 2 => '=', 3 => 'like');
        $data = array();

        $products = Product::with('unit', 'category', 'brand')
            ->where('deleted_at', '=', null);

        //Multiple Filter
        $Filtred = $helpers->filter($products, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('products.name', 'LIKE', "%{$request->search}%")
                        ->orWhere('products.code', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('category', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('brand', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });
        $totalRows = $Filtred->count();
        $products = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($products as $product) {
            $item['id'] = $product->id;
            $item['code'] = $product->code;
            $item['name'] = $product->name;
            $item['category'] = $product['category']->name;
            $item['brand'] = $product['brand'] ? $product['brand']->name : 'N/D';
            $item['unit'] = $product['unit']->ShortName;
            $item['price'] = $product->price;

            $product_warehouse_data = product_warehouse::where('product_id', $product->id)
                ->where('deleted_at', '=', null)
                ->get();
            $total_qty = 0;
            foreach ($product_warehouse_data as $product_warehouse) {
                $total_qty += $product_warehouse->qte;
                $item['quantity'] = $total_qty;
            }

            $firstimage = explode(',', $product->image);
            $item['image'] = $firstimage[0];

            $data[] = $item;
        }

        $warehouses = Warehouse::where('deleted_at', null)->get(['id', 'name']);
        $categories = Category::where('deleted_at', null)->get(['id', 'name']);
        $brands = Brand::where('deleted_at', null)->get(['id', 'name']);

        return response()->json([
            'warehouses' => $warehouses,
            'categories' => $categories,
            'brands' => $brands,
            'products' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    //-------------- Store new  Product  ---------------\\

    /**
     * What a product's numbers may be (DATATEST K50): a price or cost of 0 or
     * more that the column can hold, and a tax rate between 0 and 100. A
     * negative cost, a price of "abc" and a 250% tax were all saved.
     */
    /**
     * The product row as it stands, as a short fingerprint. Any change to it --
     * another edit, or a receipt moving the average cost -- gives a new one.
     */
    public static function rowVersion($id)
    {
        return sha1(json_encode((array) DB::table('products')->where('id', $id)->first()));
    }

    private static function bounds()
    {
        return [
            'code' => 'required|string|max:192',
            'name' => 'required|string|max:192',
            'price' => 'required|numeric|min:0|max:9999999999999999',
            'cost' => 'required|numeric|min:0|max:9999999999999999',
            'TaxNet' => 'nullable|numeric|min:0|max:100',
            'stock_alert' => 'nullable|numeric|min:0|max:99999999999999',
        ];
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Product::class);

        try {
            $this->validate($request, [
                'code' => 'required|unique:products',
                'code' => Rule::unique('products')->where(function ($query) {
                    return $query->where('deleted_at', '=', null);
                }),
                'name' => 'required',
                'Type_barcode' => 'required',
                'price' => 'required',
                'category_id' => 'required',
                'cost' => 'required',
                'unit_id' => 'required',
            ], [
                'code.unique' => 'This code already used. Generate Now',
                'code.required' => 'This field is required',
            ]);
            $this->validate($request, self::bounds());

            \DB::transaction(function () use ($request) {

                //-- Create New Product
                $Product = new Product;

                //-- Field Required
                $Product->name = $request['name'];
                $Product->code = $request['code'];
                $Product->Type_barcode = $request['Type_barcode'];
                $Product->price = $request['price'];
                $Product->category_id = $request['category_id'];
                $Product->brand_id = $request['brand_id'];
                $Product->TaxNet = $request['TaxNet'] ? $request['TaxNet'] : 0;
                $Product->tax_group_id = ForeignKeyInput::id($request['tax_group_id']);
                $Product->tax_method = $request['tax_method'];
                // Same defaults as update(): a product created by an older
                // client is vatable and SC/PWD eligible, as it was before these
                // columns existed.
                $Product->vat_class = in_array($request['vat_class'], VatService::classes(), true)
                    ? $request['vat_class'] : 'vatable';
                $Product->sc_pwd_eligible = $request->has('sc_pwd_eligible')
                    ? filter_var($request['sc_pwd_eligible'], FILTER_VALIDATE_BOOLEAN) : true;
                $Product->note = $request['note'];
                $Product->cost = $request['cost'];
                $Product->unit_id = $request['unit_id'];
                $Product->unit_sale_id = $request['unit_sale_id'];
                $Product->unit_purchase_id = $request['unit_purchase_id'];
                $Product->stock_alert = $request['stock_alert'] ? $request['stock_alert'] : 0;
                $Product->is_variant = $request['is_variant'] == 'true' ? 1 : 0;
                $Product->has_batches = filter_var($request['has_batches'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $Product->requires_qc = filter_var($request['requires_qc'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $Product->web_published = filter_var($request['web_published'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $Product->expiry_alert_days = is_numeric($request['expiry_alert_days'])
                    ? (int) $request['expiry_alert_days'] : 30;
                self::applyKind($Product, $request);

                if ($request['images']) {
                    $files = $request['images'];
                    foreach ($files as $file) {
                        $fileData = ImageResize::createFromString(base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $file['path'])));
                        $fileData->resize(200, 200);
                        $name = rand(11111111, 99999999) . $file['name'];
                        $path = public_path() . '/images/products/';
                        $success = file_put_contents($path . $name, $fileData);
                        $images[] = $name;
                    }
                    $filename = implode(",", $images);
                } else {
                    $filename = 'no-image.png';
                }

                $Product->image = $filename;
                $Product->save();

                // Store Variants Product
                if ($request['is_variant'] == 'true') {
                    foreach ($request['variants'] as $variant) {
                        $Product_variants_data[] = [
                            'product_id' => $Product->id,
                            'name' => $variant,
                        ];
                    }
                    ProductVariant::insert($Product_variants_data);
                }

                //--Store Product Warehouse
                // Every branch gets a stock row, whoever creates the product.
                $warehouses = Warehouse::withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class)
                    ->where('deleted_at', null)->pluck('id')->toArray();
                if ($warehouses) {
                    $Product_variants = ProductVariant::where('product_id', $Product->id)
                        ->where('deleted_at', null)
                        ->get();
                    foreach ($warehouses as $warehouse) {
                        if ($request['is_variant'] == 'true') {
                            foreach ($Product_variants as $product_variant) {

                                $product_warehouse[] = [
                                    'product_id' => $Product->id,
                                    'warehouse_id' => $warehouse,
                                    'product_variant_id' => $product_variant->id,
                                ];
                            }
                        } else {
                            $product_warehouse[] = [
                                'product_id' => $Product->id,
                                'warehouse_id' => $warehouse,
                            ];
                        }
                    }
                    product_warehouse::insert($product_warehouse);
                }

            }, 10);

            return response()->json(['success' => true]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

    }

    /**
     * Service or goods, and whether each unit carries a serial number
     * (App\utils\ProductKind). Absent fields keep a product as it was, so an
     * older client cannot change its kind by saving it.
     */
    private static function applyKind(Product $Product, Request $request)
    {
        $flag = function ($key, $current) use ($request) {
            return $request->has($key) ? (filter_var($request[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int) $current;
        };
        $isService = $flag('is_service', $Product->is_service);
        $hasSerials = $flag('has_serials', $Product->has_serials);

        if ($Product->exists && ($isService !== (int) $Product->is_service || ($hasSerials && ! $Product->has_serials))) {
            // Units already on the shelf would have no serials, or a service
            // would hide stock that exists. Bring it to zero first.
            $onHand = (float) product_warehouse::where('product_id', $Product->id)->whereNull('deleted_at')->sum('qte');
            if ($onHand > 0) {
                throw ValidationException::withMessages(['is_service' => [sprintf(
                    'This product still has %s in stock. Bring its stock to zero (adjustment or transfer) before changing it to %s.',
                    rtrim(rtrim(number_format($onHand, 2, '.', ''), '0'), '.'),
                    $isService ? 'a service' : 'serial-number tracking'
                )]]);
            }
        }

        if ($isService) {
            // Never stocked, so never low, never in a batch, never serialised.
            $Product->is_service = 1;
            $Product->has_batches = 0;
            $Product->has_serials = 0;
            $Product->requires_qc = 0;
            $Product->stock_alert = 0;
        } else {
            $Product->is_service = 0;
            if ($hasSerials && $Product->has_batches) {
                throw ValidationException::withMessages(['has_serials' => [
                    'Choose batch tracking or serial numbers for this product, not both.',
                ]]);
            }
            $Product->has_serials = $hasSerials;
        }

        if ($request->has('warranty_months')) {
            $months = $request['warranty_months'];
            $Product->warranty_months = is_numeric($months) && (int) $months > 0 ? min((int) $months, 600) : null;
        }

        \App\utils\ProductKind::forget($Product->id);
    }

    //-------------- Update Product  ---------------\\
    //-----------------------------------------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);
        try {
            $this->validate($request, [
                'code' => 'required|unique:products',
                'code' => Rule::unique('products')->ignore($id)->where(function ($query) {
                    return $query->where('deleted_at', '=', null);
                }),
                'name' => 'required',
                'Type_barcode' => 'required',
                'price' => 'required',
                'category_id' => 'required',
                'cost' => 'required',
                'unit_id' => 'required',
            ], [
                'code.unique' => 'This code already used. Generate Now',
                'code.required' => 'This field is required',
            ]);
            $this->validate($request, self::bounds());

            \DB::transaction(function () use ($request, $id) {

                // Locked, and refused if it changed since this form loaded it:
                // two screens saving at once each kept only their own change
                // (DATATEST K79). A form that sends no version is let through.
                $Product = Product::where('id', $id)
                    ->where('deleted_at', '=', null)
                    ->lockForUpdate()
                    ->first();
                if ($Product && DB::table('goods_receipt_details')
                    ->where('product_id', $Product->id)->where('qc_status', 'pending')->exists()) {
                    foreach (['has_batches', 'has_serials', 'is_service'] as $trackingField) {
                        if ($request->has($trackingField)
                            && (bool) filter_var($request[$trackingField], FILTER_VALIDATE_BOOLEAN) !== (bool) $Product->$trackingField) {
                            throw ValidationException::withMessages([
                                $trackingField => ['Finish QC on this product’s held receipts before changing its stock tracking or kind.'],
                            ]);
                        }
                    }
                }
                if ($Product && $request->filled('version') && ! hash_equals(self::rowVersion($id), (string) $request['version'])) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                        'message' => 'Someone else changed this product while you had it open. Reload it, then make your change again.',
                    ], 409));
                }

                //-- Update Product
                $Product->name = $request['name'];
                $Product->code = $request['code'];
                $Product->Type_barcode = $request['Type_barcode'];
                $Product->price = $request['price'];
                $Product->category_id = $request['category_id'];
                $Product->brand_id = $request['brand_id'] == 'null' ?Null: $request['brand_id'];
                $Product->TaxNet = $request['TaxNet'];
                // FormData sends an absent tax group as the string "null", which
                // a truthiness check keeps and the foreign key then rejects.
                $Product->tax_group_id = ForeignKeyInput::id($request['tax_group_id']);
                $Product->tax_method = $request['tax_method'];
                // Philippine VAT classification. Absent input keeps the product
                // vatable, which is what it was before the column existed, so an
                // older client cannot silently reclassify it.
                $Product->vat_class = in_array($request['vat_class'], VatService::classes(), true)
                    ? $request['vat_class'] : 'vatable';
                $Product->sc_pwd_eligible = $request->has('sc_pwd_eligible')
                    ? filter_var($request['sc_pwd_eligible'], FILTER_VALIDATE_BOOLEAN) : true;
                $Product->note = $request['note'];
                $Product->cost = $request['cost'];
                // The base unit is what every stock figure is counted in: 70
                // bags would silently become 70 kilograms (DATATEST K61).
                if ((int) $request['unit_id'] !== (int) $Product->unit_id) {
                    $onHand = (float) DB::table('product_warehouse')->whereNull('deleted_at')
                        ->where('product_id', $Product->id)->sum('qte');
                    if (abs($onHand) > 0.000001) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['unit_id' => [
                            'The base unit cannot change while ' . rtrim(rtrim(number_format($onHand, 6, '.', ''), '0'), '.')
                            . ' are on hand: they would be restated in the new unit. Bring the stock to zero first.',
                        ]]);
                    }
                }
                $Product->unit_id = $request['unit_id'];
                $Product->unit_sale_id = $request['unit_sale_id'] ? $request['unit_sale_id'] : $request['unit_id'];
                $Product->unit_purchase_id = $request['unit_purchase_id'] ? $request['unit_purchase_id'] : $request['unit_id'];
                $Product->stock_alert = $request['stock_alert'];
                $Product->is_variant = $request['is_variant'] == 'true' ? 1 : 0;
                // Only when the form sent it, and only as the tracking switch
                // allows; an absent field switched tracking off (DATATEST K22).
                if ($request->has('has_batches')) {
                    $wantBatches = filter_var($request['has_batches'], FILTER_VALIDATE_BOOLEAN);
                    \App\utils\BatchService::assertCanSetTracking($Product, $wantBatches);
                    $Product->has_batches = $wantBatches ? 1 : 0;
                    \App\utils\BatchService::forget($Product->id);
                }
                if ($request->has('requires_qc')) {
                    $Product->requires_qc = filter_var($request['requires_qc'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }
                if ($request->has('web_published')) {
                    $Product->web_published = filter_var($request['web_published'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }
                // Only meaningful while batches are tracked, but kept either way
                // so turning tracking off and on again does not lose the setting.
                $Product->expiry_alert_days = is_numeric($request['expiry_alert_days'])
                    ? (int) $request['expiry_alert_days'] : 30;
                self::applyKind($Product, $request);

                // Store Variants Product
                $oldVariants = ProductVariant::where('product_id', $id)
                    ->where('deleted_at', null)
                    ->get();

                // Every branch gets a stock row, whoever edits the product.
                $warehouses = Warehouse::withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class)
                    ->where('deleted_at', null)
                    ->pluck('id')
                    ->toArray();


                if ($request['is_variant'] == 'true') {

                    if ($oldVariants->isNotEmpty()) {
                        $new_variants_id = [];
                        $var = 'id';

                        foreach ($request['variants'] as $new_id) {
                            if (array_key_exists($var, $new_id)) {
                                $new_variants_id[] = $new_id['id'];
                            } else {
                                $new_variants_id[] = 0;
                            }
                        }

                        foreach ($oldVariants as $key => $value) {
                            $old_variants_id[] = $value->id;

                            // Delete Variant
                            if (!in_array($old_variants_id[$key], $new_variants_id)) {
                                $ProductVariant = ProductVariant::findOrFail($value->id);
                                $ProductVariant->deleted_at = Carbon::now();
                                $ProductVariant->save();

                                $ProductWarehouse = product_warehouse::where('product_variant_id', $value->id)
                                    ->update(['deleted_at' => Carbon::now()]);
                            }
                        }

                        foreach ($request['variants'] as $key => $variant) {
                            if (array_key_exists($var, $variant)) {

                                $ProductVariantDT = new ProductVariant;

                                //-- Field Required
                                $ProductVariantDT->product_id = $variant['product_id'];
                                $ProductVariantDT->name = $variant['text'];
                                $ProductVariantDT->qty = $variant['qty'];
                                $ProductVariantUP['product_id'] = $variant['product_id'];
                                $ProductVariantUP['name'] = $variant['text'];
                                $ProductVariantUP['qty'] = $variant['qty'];

                            } else {
                                $ProductVariantDT = new ProductVariant;

                                //-- Field Required
                                $ProductVariantDT->product_id = $id;
                                $ProductVariantDT->name = $variant['text'];
                                $ProductVariantDT->qty = 0.00;
                                $ProductVariantUP['product_id'] = $id;
                                $ProductVariantUP['name'] = $variant['text'];
                                $ProductVariantUP['qty'] = 0.00;
                            }

                            if (!in_array($new_variants_id[$key], $old_variants_id)) {
                                $ProductVariantDT->save();

                                //--Store Product warehouse
                                if ($warehouses) {
                                    $product_warehouse= [];
                                    foreach ($warehouses as $warehouse) {

                                        $product_warehouse[] = [
                                            'product_id' => $id,
                                            'warehouse_id' => $warehouse,
                                            'product_variant_id' => $ProductVariantDT->id,
                                        ];

                                    }
                                    product_warehouse::insert($product_warehouse);
                                }
                            } else {
                                ProductVariant::where('id', $variant['id'])->update($ProductVariantUP);
                            }
                        }

                    } else {
                        $ProducttWarehouse = product_warehouse::where('product_id', $id)
                            ->update([
                                'deleted_at' => Carbon::now(),
                            ]);

                        foreach ($request['variants'] as $variant) {
                            $product_warehouse_DT = [];
                            $ProductVarDT = new ProductVariant;

                            //-- Field Required
                            $ProductVarDT->product_id = $id;
                            $ProductVarDT->name = $variant['text'];
                            $ProductVarDT->save();

                            //-- Store Product warehouse
                            if ($warehouses) {
                                foreach ($warehouses as $warehouse) {

                                    $product_warehouse_DT[] = [
                                        'product_id' => $id,
                                        'warehouse_id' => $warehouse,
                                        'product_variant_id' => $ProductVarDT->id,
                                    ];
                                }

                                product_warehouse::insert($product_warehouse_DT);
                            }
                        }

                    }
                } else {
                    if ($oldVariants->isNotEmpty()) {
                        foreach ($oldVariants as $old_var) {
                            $var_old = ProductVariant::where('product_id', $old_var['product_id'])
                                ->where('deleted_at', null)
                                ->first();
                            $var_old->deleted_at = Carbon::now();
                            $var_old->save();

                            $ProducttWarehouse = product_warehouse::where('product_variant_id', $old_var['id'])
                                ->update([
                                    'deleted_at' => Carbon::now(),
                                ]);
                        }

                        if ($warehouses) {
                            foreach ($warehouses as $warehouse) {

                                $product_warehouse[] = [
                                    'product_id' => $id,
                                    'warehouse_id' => $warehouse,
                                    'product_variant_id' => null,
                                ];

                            }
                            product_warehouse::insert($product_warehouse);
                        }
                    }
                }

                if ($request['images'] === null) {

                    if ($Product->image !== null) {
                        foreach (explode(',', $Product->image) as $img) {
                            $pathIMG = public_path() . '/images/products/' . $img;
                            if (file_exists($pathIMG)) {
                                if ($img != 'no-image.png') {
                                    @unlink($pathIMG);
                                }
                            }
                        }
                    }
                    $filename = 'no-image.png';
                } else {
                    // An image the product already has is kept as it is; only a
                    // new upload is resized and written. Every save used to
                    // delete them all and write renamed copies (DATATEST K94).
                    $path = public_path() . '/images/products/';
                    $current = $Product->image ? explode(',', $Product->image) : [];
                    $images = [];
                    foreach ($request['images'] as $file) {
                        $given = basename((string) ($file['name'] ?? ''));
                        if ($given !== '' && in_array($given, $current, true) && file_exists($path . $given)) {
                            $images[] = $given;
                            continue;
                        }
                        $fileData = ImageResize::createFromString(base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $file['path'])));
                        $fileData->resize(200, 200);
                        $name = rand(11111111, 99999999) . $given;
                        file_put_contents($path . $name, $fileData);
                        $images[] = $name;
                    }
                    // Images taken off the product go.
                    foreach (array_diff($current, $images) as $img) {
                        if ($img !== 'no-image.png' && $img !== '' && file_exists($path . $img)) {
                            @unlink($path . $img);
                        }
                    }
                    $filename = $images ? implode(",", $images) : 'no-image.png';
                }

                $Product->image = $filename;
                $Product->save();

            }, 10);

            return response()->json(['success' => true]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'msg' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

    }

    //-------------- Remove Product  ---------------\\
    //-----------------------------------------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Product::class);
        // Not while it has stock: the 70 bags on hand vanished with no
        // movement (DATATEST K36).
        if ($reason = \App\utils\DeleteGuard::product($id)) {
            return response()->json(['message' => $reason], 422);
        }

        \DB::transaction(function () use ($id) {

            $Product = Product::findOrFail($id);
            $Product->deleted_at = Carbon::now();
            $Product->save();

            foreach (explode(',', $Product->image) as $img) {
                $pathIMG = public_path() . '/images/products/' . $img;
                if (file_exists($pathIMG)) {
                    if ($img != 'no-image.png') {
                        @unlink($pathIMG);
                    }
                }
            }

            product_warehouse::where('product_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            ProductVariant::where('product_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);

    }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Product::class);
        if ($reason = \App\utils\DeleteGuard::firstReason('product', (array) $request->selectedIds)) {
            return response()->json(['message' => $reason], 422);
        }

        \DB::transaction(function () use ($request) {
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $product_id) {

                $Product = Product::findOrFail($product_id);
                $Product->deleted_at = Carbon::now();
                $Product->save();

                foreach (explode(',', $Product->image) as $img) {
                    $pathIMG = public_path() . '/images/products/' . $img;
                    if (file_exists($pathIMG)) {
                        if ($img != 'no-image.png') {
                            @unlink($pathIMG);
                        }
                    }
                }

                product_warehouse::where('product_id', $product_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);

                ProductVariant::where('product_id', $product_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);

    }

    //-------------- Export All Product to EXCEL  ---------------\\

    public function export_Excel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        return Excel::download(new ProductsExport, 'List_Products.xlsx');
    }

    //--------------  Show Product Details ---------------\\

    public function Get_Products_Details(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $Product = Product::where('deleted_at', '=', null)->findOrFail($id);
        // Stock in every branch, even ones the viewer is not on: seeing it is
        // how a stockman knows where to ask for a transfer (WarehouseAccessTest).
        $warehouses = Warehouse::withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class)
            ->where('deleted_at', '=', null)->get();

        $item['id'] = $Product->id;
        $item['code'] = $Product->code;
        $item['Type_barcode'] = $Product->Type_barcode;
        $item['name'] = $Product->name;
        $item['category'] = $Product['category']->name;
        $item['brand'] = $Product['brand'] ? $Product['brand']->name : 'N/D';
        $item['unit'] = $Product['unit']->ShortName;
        $item['price'] = $Product->price;
        $item['cost'] = $Product->cost;
        $item['stock_alert'] = $Product->stock_alert;
        $item['taxe'] = $Product->TaxNet;
        $item['tax_method'] = $Product->tax_method == '1' ? 'Exclusive' : 'Inclusive';

        if ($Product->is_variant) {
            $item['is_variant'] = 'yes';
            $productsVariants = ProductVariant::where('product_id', $id)
                ->where('deleted_at', null)
                ->get();
            foreach ($productsVariants as $variant) {
                $item['ProductVariant'][] = $variant->name;

                foreach ($warehouses as $warehouse) {
                    $product_warehouse = DB::table('product_warehouse')
                        ->where('product_id', $id)
                        ->where('deleted_at', '=', null)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('product_variant_id', $variant->id)
                        ->select(DB::raw('SUM(product_warehouse.qte) AS sum'))
                        ->first();

                    $war_var['mag'] = $warehouse->name;
                    $war_var['variant'] = $variant->name;
                    $war_var['qte'] = $product_warehouse->sum;
                    $item['CountQTY_variants'][] = $war_var;
                }

            }
        } else {
            $item['is_variant'] = 'no';
            $item['CountQTY_variants'] = [];
        }

        foreach ($warehouses as $warehouse) {
            $product_warehouse_data = DB::table('product_warehouse')
                ->where('deleted_at', '=', null)
                ->where('product_id', $id)
                ->where('warehouse_id', $warehouse->id)
                ->select(DB::raw('SUM(product_warehouse.qte) AS sum'))
                ->first();

            $war['mag'] = $warehouse->name;
            $war['qte'] = $product_warehouse_data->sum;
            $item['CountQTY'][] = $war;
        }

        if ($Product->image != '') {
            foreach (explode(',', $Product->image) as $img) {
                $item['images'][] = $img;
            }
        }

        $data[] = $item;

        return response()->json($data[0]);

    }

    //------------ Get products By Warehouse -----------------\\

    public function Products_by_Warehouse(request $request, $id)
    {
        $data = [];
        $product_warehouse_data = product_warehouse::with('warehouse', 'product', 'productVariant')
            ->where('warehouse_id', $id)
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                if ($request->stock == '1') {
                    // A service has no stock and is never out of it.
                    return $query->where('qte', '>', 0)
                        ->orWhereHas('product', function ($p) { $p->where('is_service', true); });
                }
            })->get();

        foreach ($product_warehouse_data as $product_warehouse) {

            if ($product_warehouse->product_variant_id) {
                $item['product_variant_id'] = $product_warehouse->product_variant_id;
                $item['code'] = $product_warehouse['productVariant']->name . '-' . $product_warehouse['product']->code;
                $item['Variant'] = $product_warehouse['productVariant']->name;
            } else {
                $item['product_variant_id'] = null;
                $item['Variant'] = null;
                $item['code'] = $product_warehouse['product']->code;
            }

            $item['id'] = $product_warehouse->product_id;
            $item['name'] = $product_warehouse['product']->name;
            $item['barcode'] = $product_warehouse['product']->code;
            $item['Type_barcode'] = $product_warehouse['product']->Type_barcode;
            $firstimage = explode(',', $product_warehouse['product']->image);
            $item['image'] = $firstimage[0];

            if ($product_warehouse['product']['unitSale']->operator == '/') {
                $item['qte_sale'] = $product_warehouse->qte * $product_warehouse['product']['unitSale']->operator_value;
                $price = $product_warehouse['product']->price / $product_warehouse['product']['unitSale']->operator_value;
            } else {
                $item['qte_sale'] = $product_warehouse->qte / $product_warehouse['product']['unitSale']->operator_value;
                $price = $product_warehouse['product']->price * $product_warehouse['product']['unitSale']->operator_value;
            }

            if ($product_warehouse['product']['unitPurchase']->operator == '/') {
                $item['qte_purchase'] = round($product_warehouse->qte * $product_warehouse['product']['unitPurchase']->operator_value, 5);
            } else {
                $item['qte_purchase'] = round($product_warehouse->qte / $product_warehouse['product']['unitPurchase']->operator_value, 5);
            }

            $item['qte'] = $product_warehouse->qte;
            $item['unitSale'] = $product_warehouse['product']['unitSale']->ShortName;
            $item['unitPurchase'] = $product_warehouse['product']['unitPurchase']->ShortName;
            // Screens skip stock limits for a service and ask for serials for these.
            $item['is_service'] = (bool) $product_warehouse['product']->is_service;
            $item['has_serials'] = (bool) $product_warehouse['product']->has_serials;
            if ($item['is_service']) {
                $item['qte'] = $item['qte_sale'] = $item['qte_purchase'] = \App\utils\ProductKind::SERVICE_STOCK;
            }

            if ($product_warehouse['product']->TaxNet !== 0.0) {
                //Exclusive
                if ($product_warehouse['product']->tax_method == '1') {
                    $tax_price = $price * $product_warehouse['product']->TaxNet / 100;
                    $item['Net_price'] = $price + $tax_price;
                    // Inxclusive
                } else {
                    $item['Net_price'] = $price;
                }
            } else {
                $item['Net_price'] = $price;
            }

            $data[] = $item;
        }

        return response()->json($data);
    }

    //------------ Get product By ID -----------------\\

    public function show($id)
    {

        $Product_data = Product::with('unit')
            ->where('id', $id)
            ->where('deleted_at', '=', null)
            ->first();

        $data = [];
        $item['id'] = $Product_data['id'];
        $item['name'] = $Product_data['name'];
        $item['Type_barcode'] = $Product_data['Type_barcode'];
        $item['unit_id'] = $Product_data['unit']->id;
        $item['unit'] = $Product_data['unit']->ShortName;
        $item['purchase_unit_id'] = $Product_data['unitPurchase']->id;
        $item['unitPurchase'] = $Product_data['unitPurchase']->ShortName;
        $item['sale_unit_id'] = $Product_data['unitSale']->id;
        $item['unitSale'] = $Product_data['unitSale']->ShortName;
        // Base units in one sale / purchase unit: a box of 2 phones needs 2 serials.
        $factor = function ($unit) {
            return $unit->operator == '/' ? 1 / $unit->operator_value : (float) $unit->operator_value;
        };
        $item['sale_unit_factor'] = $factor($Product_data['unitSale']);
        $item['purchase_unit_factor'] = $factor($Product_data['unitPurchase']);
        $item['tax_method'] = $Product_data['tax_method'];
        $item['tax_percent'] = $Product_data['TaxNet'];
        // The till mirrors the server's VAT and SC/PWD arithmetic per line;
        // without these it assumed "vatable, eligible" and showed a Senior
        // Citizen a total the server would not charge (ProductsControllerVatTest).
        $item['vat_class'] = $Product_data['vat_class'] ?: 'vatable';
        $item['sc_pwd_eligible'] = (bool) $Product_data['sc_pwd_eligible'];

        if ($Product_data['unitSale']->operator == '/') {
            $price = $Product_data['price'] / $Product_data['unitSale']->operator_value;

        } else {
            $price = $Product_data['price'] * $Product_data['unitSale']->operator_value;
        }

        if ($Product_data['unitPurchase']->operator == '/') {
            $cost = $Product_data['cost'] / $Product_data['unitPurchase']->operator_value;
        } else {
            $cost = $Product_data['cost'] * $Product_data['unitPurchase']->operator_value;
        }

        $item['Unit_cost'] = $cost;
        $item['fix_cost'] = $Product_data['cost'];
        $item['Unit_price'] = $price;
        $item['fix_price'] = $Product_data['price'];

        if ($Product_data->TaxNet !== 0.0) {
            //Exclusive
            if ($Product_data['tax_method'] == '1') {
                $tax_price = $price * $Product_data['TaxNet'] / 100;
                $tax_cost = $cost * $Product_data['TaxNet'] / 100;

                $item['Total_cost'] = $cost + $tax_cost;
                $item['Total_price'] = $price + $tax_price;
                $item['Net_cost'] = $cost;
                $item['Net_price'] = $price;
                $item['tax_price'] = $tax_price;
                $item['tax_cost'] = $tax_cost;

                // Inxclusive
            } else {
                $item['Total_cost'] = $cost;
                $item['Total_price'] = $price;
                $item['Net_cost'] = $cost / (($Product_data['TaxNet'] / 100) + 1);
                $item['Net_price'] = $price / (($Product_data['TaxNet'] / 100) + 1);
                $item['tax_cost'] = $item['Total_cost'] - $item['Net_cost'];
                $item['tax_price'] = $item['Total_price'] - $item['Net_price'];
            }
        } else {
            $item['Total_cost'] = $cost;
            $item['Total_price'] = $price;
            $item['Net_cost'] = $cost;
            $item['Net_price'] = $price;
            $item['tax_price'] = 0;
            $item['tax_cost'] = 0;
        }

        // Cost is for people who buy, move or account for stock, not for the
        // till: a cashier read the cement's 230 cost here (DATATEST K56).
        if (! \App\utils\DocumentAccess::hasAny(['Reports_profit', 'Purchases_add', 'Purchases_edit',
            'Purchase_Returns_add', 'Purchase_Returns_edit', 'transfer_add', 'transfer_edit',
            'adjustment_add', 'adjustment_edit', 'products_add', 'products_edit', 'goods_receipt_add'])) {
            unset($item['Unit_cost'], $item['fix_cost'], $item['Total_cost'], $item['Net_cost'], $item['tax_cost']);
        }

        $data[] = $item;

        return response()->json($data[0]);
    }

    //-------------- Filter products by warehouse and text ---------------\\

    public function Filter_Products(Request $request, $id, $input)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $search = trim(urldecode((string) $input));
        $stocks = product_warehouse::with(['product.unitSale', 'productVariant'])
            ->where('warehouse_id', $id)
            ->whereNull('deleted_at')
            ->whereHas('product', function ($query) use ($search) {
                $query->whereNull('deleted_at')
                    ->where(function ($query) use ($search) {
                        $query->where('name', 'LIKE', '%' . $search . '%')
                            ->orWhere('code', 'LIKE', '%' . $search . '%');
                    });
            })
            ->limit(25)
            ->get();

        return response()->json($stocks);
    }

    //--------------  Product Quantity Alerts ---------------\\

    public function Products_Alert(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Stock_Alerts', Product::class);

        $product_warehouse_data = product_warehouse::with('warehouse', 'product', 'productVariant')
            ->join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->whereRaw('qte <= stock_alert')
            // A service is never stocked, so never low; a deleted product is
            // not shown either -- the same rule as the badge (LowStock, K83).
            ->where('products.is_service', false)
            ->whereNull('products.deleted_at')
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('warehouse'), function ($query) use ($request) {
                    return $query->where('warehouse_id', $request->warehouse);
                });
            })->where('product_warehouse.deleted_at', null)->get();

        $data = [];

        if ($product_warehouse_data->isNotEmpty()) {

            foreach ($product_warehouse_data as $product_warehouse) {
                if ($product_warehouse->qte <= $product_warehouse['product']->stock_alert) {
                    if ($product_warehouse->product_variant_id !== null) {
                        $item['code'] = $product_warehouse['productVariant']->name . '-' . $product_warehouse['product']->code;
                    } else {
                        $item['code'] = $product_warehouse['product']->code;
                    }
                    $item['quantity'] = $product_warehouse->qte;
                    $item['name'] = $product_warehouse['product']->name;
                    $item['warehouse'] = $product_warehouse['warehouse']->name;
                    $item['stock_alert'] = $product_warehouse['product']->stock_alert;
                    $data[] = $item;
                }
            }
        }

        $perPage = \App\utils\ListLimit::of($request->limit); // How many items do you want to display.
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $collection = collect($data);
        // Get only the items you need using array_slice
        $data_collection = $collection->slice($offSet, $perPage)->values();

        $products = new LengthAwarePaginator($data_collection, count($data), $perPage, Paginator::resolveCurrentPage(), array('path' => Paginator::resolveCurrentPath()));
        $warehouses = Warehouse::where('deleted_at', null)->get(['id', 'name']);

        return response()->json([
            'products' => $products,
            'warehouses' => $warehouses,
        ]);
    }

    //---------------- Show Form Create Product ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Product::class);

        $categories = Category::where('deleted_at', null)->get(['id', 'name']);
        $brands = Brand::where('deleted_at', null)->get(['id', 'name']);
        $units = Unit::where('deleted_at', null)->where('base_unit', null)->get();
        return response()->json([
            'categories' => $categories,
            'brands' => $brands,
            'units' => $units,
        ]);

    }

    //---------------- Show Elements Barcode ---------------\\

    public function Get_element_barcode(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'barcode', Product::class);

        $warehouses = Warehouse::where('deleted_at', null)->get(['id', 'name']);
        return response()->json(['warehouses' => $warehouses]);

    }

    //---------------- Show Form Edit Product ---------------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Product::class);

        $Product = Product::where('deleted_at', '=', null)->findOrFail($id);

        $item['id'] = $Product->id;
        $item['code'] = $Product->code;
        $item['Type_barcode'] = $Product->Type_barcode;
        $item['name'] = $Product->name;
        if ($Product->category_id) {
            if (Category::where('id', $Product->category_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['category_id'] = $Product->category_id;
            } else {
                $item['category_id'] = '';
            }
        } else {
            $item['category_id'] = '';
        }

        if ($Product->brand_id) {
            if (Brand::where('id', $Product->brand_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['brand_id'] = $Product->brand_id;
            } else {
                $item['brand_id'] = '';
            }
        } else {
            $item['brand_id'] = '';
        }

        if ($Product->unit_id) {
            if (Unit::where('id', $Product->unit_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['unit_id'] = $Product->unit_id;
            } else {
                $item['unit_id'] = '';
            }

            if (Unit::where('id', $Product->unit_sale_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['unit_sale_id'] = $Product->unit_sale_id;
            } else {
                $item['unit_sale_id'] = '';
            }

            if (Unit::where('id', $Product->unit_purchase_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $item['unit_purchase_id'] = $Product->unit_purchase_id;
            } else {
                $item['unit_purchase_id'] = '';
            }

        } else {
            $item['unit_id'] = '';
        }

        $item['tax_method'] = $Product->tax_method;
        $item['price'] = $Product->price;
        $item['cost'] = $Product->cost;
        $item['stock_alert'] = $Product->stock_alert;
        $item['TaxNet'] = $Product->TaxNet;
        // Everything the edit screen saves must come back, or saving sends its
        // blank defaults and switches these off (ProductsControllerEditFieldsTest).
        $item['has_batches'] = (bool) $Product->has_batches;
        $item['requires_qc'] = (bool) $Product->requires_qc;
        $item['web_published'] = (bool) $Product->web_published;
        $item['expiry_alert_days'] = $Product->expiry_alert_days ?? 30;
        $item['vat_class'] = $Product->vat_class ?: 'vatable';
        $item['sc_pwd_eligible'] = $Product->sc_pwd_eligible === null ? true : (bool) $Product->sc_pwd_eligible;
        $item['is_service'] = (bool) $Product->is_service;
        $item['has_serials'] = (bool) $Product->has_serials;
        $item['warranty_months'] = $Product->warranty_months ?: '';
        $item['note'] = $Product->note ? $Product->note : '';
        // Sent back on save, so a save over someone else's change is refused (K79).
        $item['version'] = self::rowVersion($Product->id);
        $item['images'] = [];
        if ($Product->image != '' && $Product->image != 'no-image.png') {
            foreach (explode(',', $Product->image) as $img) {
                $path = public_path() . '/images/products/' . $img;
                if (file_exists($path)) {
                    $itemImg['name'] = $img;
                    $type = pathinfo($path, PATHINFO_EXTENSION);
                    $data = file_get_contents($path);
                    $itemImg['path'] = 'data:image/' . $type . ';base64,' . base64_encode($data);

                    $item['images'][] = $itemImg;
                }
            }
        } else {
            $item['images'] = [];
        }
        if ($Product->is_variant) {
            $item['is_variant'] = true;
            $productsVariants = ProductVariant::where('product_id', $id)
                ->where('deleted_at', null)
                ->get();
            foreach ($productsVariants as $variant) {
                $variant_item['id'] = $variant->id;
                $variant_item['text'] = $variant->name;
                $variant_item['qty'] = $variant->qty;
                $variant_item['product_id'] = $variant->product_id;
                $item['ProductVariant'][] = $variant_item;
            }
        } else {
            $item['is_variant'] = false;
            $item['ProductVariant'] = [];
        }

        $data = $item;
        $categories = Category::where('deleted_at', null)->get(['id', 'name']);
        $brands = Brand::where('deleted_at', null)->get(['id', 'name']);

        $product_units = Unit::where('id', $Product->unit_id)
                              ->orWhere('base_unit', $Product->unit_id)
                              ->where('deleted_at', null)
                              ->get();

        
        // $product_units[] = Unit::where('id', $Product->unit_id)->where('deleted_at', null)->first();
        // $product_units_child = Unit::where('base_unit', $Product->unit_id)->where('deleted_at', null)->get();

        // if ($product_units_child->isNotEmpty()) {
        //     foreach ($product_units_child as $unit_child) {
        //         $product_units[] = $unit_child;
        //     }
        // }

        $units = Unit::where('deleted_at', null)
            ->where('base_unit', null)
            ->get();

        return response()->json([
            'product' => $data,
            'categories' => $categories,
            'brands' => $brands,
            'units' => $units,
            'units_sub' => $product_units,
        ]);

    }

    // import Products
    public function import_products(Request $request)
    {
        // Importing creates products in bulk: it needs its own permission (DATATEST K21).
        \App\utils\DocumentAccess::need('product_import');

        try {
            \DB::transaction(function () use ($request) {
                $file_upload = $request->file('products');
                $ext = pathinfo($file_upload->getClientOriginalName(), PATHINFO_EXTENSION);
                if ($ext != 'csv') {
                    return response()->json([
                        'msg' => 'must be in csv format',
                        'status' => false,
                    ]);
                } else {
                    $data = array();
                    $rowcount = 0;
                    if (($handle = fopen($file_upload, "r")) !== false) {

                        $max_line_length = defined('MAX_LINE_LENGTH') ? MAX_LINE_LENGTH : 10000;
                        $header = fgetcsv($handle, $max_line_length);
                        $header_colcount = count($header);
                        while (($row = fgetcsv($handle, $max_line_length)) !== false) {
                            $row_colcount = count($row);
                            if ($row_colcount == $header_colcount) {
                                $entry = array_combine($header, $row);
                                $data[] = $entry;
                            } else {
                                return null;
                            }
                            $rowcount++;
                        }
                        fclose($handle);
                    } else {
                        return null;
                    }


                    // Every branch gets a stock row, whoever imports the products.
                    $warehouses = Warehouse::withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class)
                        ->where('deleted_at', null)->pluck('id')->toArray();

                    // Every code must be new, as on the single-product form: the
                    // import created a second "HW-0001" next to the cement (K21/K58).
                    $codes = array_map(function ($row) {
                        return trim((string) ($row['code'] ?? ''));
                    }, $data);
                    $problems = [];
                    foreach ($codes as $i => $code) {
                        if ($code === '') {
                            $problems[] = 'Row ' . ($i + 2) . ': the code is empty.';
                        } elseif (array_search($code, $codes, true) !== $i) {
                            $problems[] = 'Row ' . ($i + 2) . ": code {$code} appears twice in the file.";
                        }
                    }
                    $taken = Product::whereNull('deleted_at')->whereIn('code', array_filter($codes))->pluck('code')->all();
                    foreach ($taken as $code) {
                        $problems[] = "Code {$code} is already used by another product.";
                    }
                    if ($problems) {
                        throw ValidationException::withMessages(['products' => $problems]);
                    }

                    //-- Create New Product
                    foreach ($data as $key => $value) {
                        $category = Category::firstOrCreate(['name' => $value['category']]);
                        $category_id = $category->id;

                        $unit = Unit::where(['ShortName' => $value['unit']])
                            ->orWhere(['name' => $value['unit']])->first();
                        $unit_id = $unit->id;

                        if ($value['brand'] != 'N/A' && $value['brand'] != '') {
                            $brand = Brand::firstOrCreate(['name' => $value['brand']]);
                            $brand_id = $brand->id;
                        } else {
                            $brand_id = null;
                        }
                        $Product = new Product;
                        $Product->name = $value['name'] == '' ? null : $value['name'];
                        $Product->code = $value['code'] == '' ? '11111111' : $value['code'];
                        $Product->Type_barcode = 'CODE128';
                        $Product->price = $value['price'];
                        $Product->cost = $value['cost'];
                        $Product->category_id = $category_id;
                        $Product->brand_id = $brand_id;
                        $Product->TaxNet = 0;
                        $Product->tax_method = 1;
                        $Product->note = $value['note'] ? $value['note'] : '';
                        $Product->unit_id = $unit_id;
                        $Product->unit_sale_id = $unit_id;
                        $Product->unit_purchase_id = $unit_id;
                        $Product->stock_alert = $value['stock_alert'] ? $value['stock_alert'] : 0;
                        $Product->is_variant = 0;
                        $Product->image = 'no-image.png';
                        $Product->save();

                        if ($warehouses) {
                            foreach ($warehouses as $warehouse) {
                                $product_warehouse[] = [
                                    'product_id' => $Product->id,
                                    'warehouse_id' => $warehouse,
                                ];
                            }
                        }
                    }
                    if ($warehouses) {
                        product_warehouse::insert($product_warehouse);
                    }
                }
            }, 10);
            return response()->json([
                'status' => true,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'msg' => 'error',
                'errors' => $e->errors(),
            ]);
        }

    }

    // Generate_random_code
    public function generate_random_code($value_code)
    {
        if($value_code == ''){
            $gen_code = substr(number_format(time() * mt_rand(), 0, '', ''), 0, 8);
            $this->check_code_exist($gen_code);
        }else{
            $this->check_code_exist($value_code);
        }
    }


    // check_code_exist
    public function check_code_exist($code)
    {
        $check_code = Product::where('code', $code)->first();
        if ($check_code) {
            $this->generate_random_code($code);
        } else {
            return $code;
        }



    }




}
