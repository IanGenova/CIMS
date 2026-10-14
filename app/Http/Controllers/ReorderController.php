<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Provider;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Replenishment suggestions.
 *
 * Products already carried a stock_alert threshold and there was a report
 * listing what had breached it, but nothing turned that into a buying
 * decision. This proposes what to order, how much, and from whom.
 *
 * Suggested quantity is the product's configured reorder_qte when set;
 * otherwise enough to bring stock back up to the alert level.
 */
class ReorderController extends BaseController
{
    public function suggestions(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);

        $request->validate([
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $warehouseId = $request->input('warehouse_id');

        // Stock per product, either for one warehouse or across all of them.
        $stockQuery = DB::table('product_warehouse')
            ->select('product_id', DB::raw('SUM(qte) as stock'))
            ->whereNull('deleted_at')
            ->when($warehouseId, function ($q) use ($warehouseId) {
                return $q->where('warehouse_id', $warehouseId);
            })
            ->groupBy('product_id');

        $products = Product::where('products.deleted_at', '=', null)
            ->where('products.is_active', 1)
            ->leftJoinSub($stockQuery, 'stock_totals', function ($join) {
                $join->on('products.id', '=', 'stock_totals.product_id');
            })
            ->whereRaw('COALESCE(stock_totals.stock, 0) <= products.stock_alert')
            ->where('products.stock_alert', '>', 0)
            ->select(
                'products.id',
                'products.code',
                'products.name',
                'products.cost',
                'products.stock_alert',
                'products.reorder_qte',
                'products.preferred_provider_id',
                DB::raw('COALESCE(stock_totals.stock, 0) as current_stock')
            )
            ->orderByRaw('COALESCE(stock_totals.stock, 0) - products.stock_alert ASC')
            ->limit((int) $request->input('limit', 200))
            ->get();

        $providerNames = Provider::where('deleted_at', '=', null)->pluck('name', 'id');

        $data = [];
        $estimatedCost = 0;

        foreach ($products as $product) {
            $currentStock = (float) $product->current_stock;
            $alert = (float) $product->stock_alert;
            $configured = (float) $product->reorder_qte;

            // Configured quantity wins; otherwise top back up to the threshold.
            $suggested = $configured > 0 ? $configured : max($alert - $currentStock, 0);

            if ($suggested <= 0) {
                continue;
            }

            $lineCost = $suggested * (float) $product->cost;
            $estimatedCost += $lineCost;

            $data[] = [
                'product_id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'current_stock' => $currentStock,
                'stock_alert' => $alert,
                'shortfall' => round($alert - $currentStock, 6),
                'suggested_qte' => round($suggested, 6),
                'unit_cost' => (float) $product->cost,
                'estimated_cost' => round($lineCost, 4),
                'preferred_provider_id' => $product->preferred_provider_id,
                'preferred_provider' => $product->preferred_provider_id
                    ? ($providerNames[$product->preferred_provider_id] ?? null)
                    : null,
            ];
        }

        return response()->json([
            'suggestions' => $data,
            'totalRows' => count($data),
            'estimated_cost' => round($estimatedCost, 2),
            'warehouses' => Warehouse::where('deleted_at', '=', null)->get(['id', 'name']),
        ]);
    }

    /**
     * Set the reorder quantity and preferred supplier for a product, so the
     * suggestion can be tuned without editing the whole product record.
     */
    public function updateProductSettings(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Product::class);

        $request->validate([
            'reorder_qte' => 'nullable|numeric|min:0',
            'preferred_provider_id' => 'nullable|integer|exists:providers,id',
        ]);

        $product = Product::findOrFail($id);

        $product->update([
            'reorder_qte' => $request->input('reorder_qte', 0),
            'preferred_provider_id' => $request->input('preferred_provider_id'),
        ]);

        return response()->json(['success' => true]);
    }
}
