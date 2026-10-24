<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\utils\DocumentAccess;
use App\utils\CurrencyService;
use App\utils\ProductSalesForecast;
use App\utils\WarehouseAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductSalesAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'Reports_sales', Sale::class);
        $input = $request->validate([
            'history_weeks' => 'nullable|integer|in:4,8,12,26',
            'horizon_weeks' => 'nullable|integer|in:2,4,8',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $historyWeeks = (int) ($input['history_weeks'] ?? 12);
        $horizonWeeks = (int) ($input['horizon_weeks'] ?? 4);
        $warehouseId = isset($input['warehouse_id']) ? (int) $input['warehouse_id'] : null;
        $allowed = WarehouseAccess::allowedIds();
        $ownSalesOnly = ! DocumentAccess::hasAny(['record_view']);
        if ($warehouseId !== null) {
            WarehouseAccess::assertAllows($warehouseId);
        }

        // The current week is unfinished and would pull every slope downward.
        $end = Carbon::today()->startOfWeek();
        $start = $end->copy()->subWeeks($historyWeeks);
        $warehouses = DB::table('warehouses')->select('id', 'name')
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')->get();

        // Match the Dashboard's sale-unit conversion so a box of ten does not
        // become one individual item in the demand forecast.
        $factor = function ($alias) {
            return "COALESCE(CASE WHEN {$alias}.operator = '/' THEN 1.0 / NULLIF({$alias}.operator_value, 0) " .
                "WHEN {$alias}.operator_value > 0 THEN {$alias}.operator_value ELSE 1 END, 1)";
        };
        $quantity = 'sale_details.quantity * ' . $factor('line_unit') . ' / ' . $factor('sale_unit');

        $rows = DB::table('sale_details')
            ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->leftJoin('units as line_unit', 'line_unit.id', '=', 'sale_details.sale_unit_id')
            ->leftJoin('units as sale_unit', 'sale_unit.id', '=', 'products.unit_sale_id')
            ->where('sales.statut', 'completed')
            ->whereNull('sales.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('sales.date', '>=', $start->toDateString())
            ->where('sales.date', '<', $end->toDateString())
            ->when($allowed !== null, fn ($query) => $query->whereIn('sales.warehouse_id', $allowed))
            ->when($warehouseId !== null, fn ($query) => $query->where('sales.warehouse_id', $warehouseId))
            ->when($ownSalesOnly, fn ($query) => $query->where('sales.user_id', $user->id))
            ->select('products.id as product_id', 'products.code', 'products.name', 'sale_unit.ShortName as unit')
            ->selectRaw('DATE(sales.date) as sale_day')
            ->selectRaw('SUM(' . $quantity . ') as units_sold')
            // Compare product values in the base currency, including sales
            // originally entered in a different currency.
            ->selectRaw('SUM(sale_details.total / CASE WHEN sales.exchange_rate > 0 THEN sales.exchange_rate ELSE 1 END) as sales_value')
            ->groupBy('products.id', 'products.code', 'products.name', 'sale_unit.ShortName')
            ->groupByRaw('DATE(sales.date)')
            ->get();

        $products = [];
        foreach ($rows as $row) {
            $id = (int) $row->product_id;
            if (! isset($products[$id])) {
                $products[$id] = [
                    'product_id' => $id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'unit' => $row->unit ?: 'unit',
                    'units' => array_fill(0, $historyWeeks, 0.0),
                    'revenue' => array_fill(0, $historyWeeks, 0.0),
                ];
            }
            $week = $start->diffInWeeks(Carbon::parse($row->sale_day)->startOfWeek());
            if ($week >= 0 && $week < $historyWeeks) {
                $products[$id]['units'][$week] += (float) $row->units_sold;
                $products[$id]['revenue'][$week] += (float) $row->sales_value;
            }
        }

        $results = [];
        foreach ($products as $product) {
            $metrics = ProductSalesForecast::analyze($product['units'], $product['revenue'], $horizonWeeks);
            unset($product['units'], $product['revenue']);
            $results[] = array_merge($product, $metrics);
        }
        usort($results, fn ($a, $b) => $b['predicted_revenue'] <=> $a['predicted_revenue'] ?: strcmp($a['code'], $b['code']));

        // Sparse sales history is still useful to inspect, but its fitted
        // number should not be ranked as a dependable prediction.
        $forecastable = array_values(array_filter($results, fn ($row) => $row['trend'] !== 'limited'));
        $bottom = $forecastable;
        usort($bottom, fn ($a, $b) => $a['predicted_revenue'] <=> $b['predicted_revenue'] ?: strcmp($a['code'], $b['code']));
        $trending = array_values(array_filter($results, fn ($row) => $row['trend'] === 'rising'));
        usort($trending, fn ($a, $b) => $b['unit_slope_per_week'] <=> $a['unit_slope_per_week']);

        return response()->json([
            'period' => [
                'history_from' => $start->toDateString(),
                'history_through' => $end->copy()->subDay()->toDateString(),
                'forecast_from' => $end->toDateString(),
                'forecast_through' => $end->copy()->addWeeks($horizonWeeks)->subDay()->toDateString(),
                'history_weeks' => $historyWeeks,
                'horizon_weeks' => $horizonWeeks,
            ],
            'warehouses' => $warehouses,
            'currency_symbol' => optional(CurrencyService::base())->symbol ?: '₱',
            'scope' => ['own_sales_only' => $ownSalesOnly],
            'summary' => [
                'products_with_sales' => count($results),
                'trending_products' => count($trending),
                'limited_data_products' => count(array_filter($results, fn ($row) => $row['trend'] === 'limited')),
                'forecastable_products' => count($forecastable),
            ],
            'top' => array_slice($forecastable, 0, 10),
            'bottom' => array_slice($bottom, 0, 10),
            'trending' => array_slice($trending, 0, 10),
            'products' => $results,
        ]);
    }
}
