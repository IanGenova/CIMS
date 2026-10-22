<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Warehouse;
use App\utils\DocumentAccess;
use App\utils\ProductCsvImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductImportController extends Controller
{
    public function store(Request $request)
    {
        DocumentAccess::need('product_import');
        $request->validate(['products' => 'required|file|max:1024']);
        $preview = $request->boolean('preview');

        $process = function () use ($request, $preview) {
            $inspection = ProductCsvImport::inspect($request->file('products'));
            $response = [
                'status' => $inspection['error_count'] === 0,
                'preview' => $preview,
                'total_rows' => $inspection['total_rows'],
                'valid_rows' => $inspection['valid_rows'],
                'error_count' => $inspection['error_count'],
                'errors' => $inspection['errors'],
                'preview_rows' => $inspection['preview_rows'],
            ];
            if ($preview || $inspection['error_count']) return $response;

            $warehouses = Warehouse::withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class)
                ->whereNull('deleted_at')->pluck('id')->all();
            $stockRows = [];
            foreach ($inspection['rows'] as $entry) {
                $value = $entry['fields'];
                $product = new Product;
                $product->name = $value['name'];
                $product->code = $value['code'];
                $product->Type_barcode = 'CODE128';
                $product->price = $value['price'];
                $product->cost = $value['cost'];
                $product->category_id = $entry['category_id'];
                $product->brand_id = $entry['brand_id'];
                $product->TaxNet = 0;
                $product->tax_method = 1;
                $product->note = $value['note'] ?? '';
                $product->unit_id = $entry['unit_id'];
                $product->unit_sale_id = $entry['unit_id'];
                $product->unit_purchase_id = $entry['unit_id'];
                $product->stock_alert = ($value['stock_alert'] ?? '') === '' ? 0 : $value['stock_alert'];
                $product->is_variant = 0;
                $product->image = 'no-image.png';
                $product->save();
                foreach ($warehouses as $warehouse) {
                    $stockRows[] = ['product_id' => $product->id, 'warehouse_id' => $warehouse];
                }
            }
            foreach (array_chunk($stockRows, 1000) as $chunk) product_warehouse::insert($chunk);
            $response['imported'] = count($inspection['rows']);
            return $response;
        };

        $result = $preview ? $process() : DB::transaction($process, 3);
        return response()->json($result, ! $preview && $result['error_count'] ? 422 : 200);
    }
}
