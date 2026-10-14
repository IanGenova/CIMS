<?php

namespace App\utils;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Gross profit for a period: one calculation, read by the Dashboard and by
 * Profit & Loss, so the two can never disagree.
 *
 *   revenue        completed sales, net of VAT and of every discount (order,
 *                  SC/PWD), in pesos; less customer returns on the same basis
 *   cost of sales  what the stock ledger says the goods sold cost, at the
 *                  moving average, in base units; less the cost of what came
 *                  back (returned goods go back on the shelf)
 *   gross profit   revenue - cost of sales
 *
 * Owner decisions (DATATEST K18/K28): moving-average cost, and revenue net of
 * VAT and discounts. The old version summed line totals (VAT and discounts
 * still in, foreign amounts added as pesos) and costed sale-unit quantities
 * against purchase lines in purchase units, unreceived orders included -- a
 * box of ten bags was costed as one bag.
 *
 * Goods sent back to a supplier are NOT income: the goods were never
 * expensed, they simply leave stock. See tests/Feature/ReportProfitTest.php.
 */
class ProfitCalculator
{
    /**
     * @param  string    $from    Y-m-d
     * @param  string    $to      Y-m-d
     * @param  int|null  $userId  only this user's documents (a user without record_view)
     */
    public static function forPeriod($from, $to, $userId = null)
    {
        $own = function ($query, $column) use ($userId) {
            if ($userId) {
                $query->where($column, '=', $userId);
            }
        };
        // The sales side goes through the Sale model, which keeps a branch-tied
        // person to their branches; the returns below are read directly, so
        // they get the same limit by hand (ReportProfitTest).
        $branches = WarehouseAccess::allowedIds();
        $inBranch = function ($query, $column) use ($branches) {
            if ($branches !== null) {
                $query->whereIn($column, $branches ?: [0]);
            }
        };

        // ---- what was sold (completed sales only: a pending order has not
        //      shipped, so it has neither earned revenue nor consumed stock)
        $sales = Sale::where('sales.deleted_at', '=', null)
            ->where('sales.statut', 'completed')
            ->where(function ($q) use ($own) { $own($q, 'sales.user_id'); })
            ->whereBetween('sales.date', [$from, $to])
            ->get(['sales.id', 'sales.GrandTotal', 'sales.vat_amount', 'sales.TaxNet', 'sales.base_amount']);

        $salesRevenue = 0.0;
        foreach ($sales as $s) {
            $salesRevenue += self::netOf($s->GrandTotal, $s->vat_amount, $s->TaxNet, $s->base_amount);
        }

        $cogs = 0.0;
        $saleIds = $sales->pluck('id')->all();
        if (! empty($saleIds)) {
            // The ledger already holds each sale's cost: base units, moving
            // average at the moment it left. Out is a cost, back in (a void or
            // an edit that took less) is not.
            $fromLedger = DB::table('stock_movements')
                ->where('reference_type', 'Sale')
                ->whereIn('reference_id', $saleIds)
                ->groupBy('reference_id')
                ->select('reference_id', DB::raw('SUM(CASE WHEN quantity < 0 THEN total_cost ELSE -total_cost END) as cost'))
                ->pluck('cost', 'reference_id');
            $cogs += (float) $fromLedger->sum();

            // A sale with no movements at all (imported, or written before the
            // ledger) is costed at the product's average cost, in base units.
            $unledgered = array_values(array_diff($saleIds, $fromLedger->keys()->all()));
            if ($unledgered) {
                $cogs += self::costAtAverage('sale_details', 'sale_id', $unledgered);
            }
        }

        // ---- what came back from customers, on the same basis as the sale
        $returnRows = DB::table('sale_returns as r')
            ->leftJoin('sales as s', 's.id', '=', 'r.sale_id')
            ->whereNull('r.deleted_at')
            ->whereBetween('r.date', [$from, $to])
            ->where(function ($q) use ($own) { $own($q, 'r.user_id'); })
            ->where(function ($q) use ($inBranch) { $inBranch($q, 'r.warehouse_id'); })
            ->get(['r.id', 'r.GrandTotal', 's.GrandTotal as sale_total', 's.vat_amount', 's.TaxNet', 's.base_amount']);

        $returns = 0.0;
        foreach ($returnRows as $r) {
            // A return refunds the price the sale was paid at (K5), so its
            // net share is the sale's own net-to-gross ratio.
            $ratio = ((float) $r->sale_total) > 0
                ? self::netOf($r->sale_total, $r->vat_amount, $r->TaxNet, $r->base_amount) / (float) $r->sale_total
                : 1.0;
            $returns += (float) $r->GrandTotal * $ratio;
        }

        // Its cost leaves cost of sales: the goods are back on the shelf.
        $returnedCost = $returnRows->isEmpty() ? 0.0
            : self::costAtAverage('sale_return_details', 'sale_return_id', $returnRows->pluck('id')->all());

        $revenue = round($salesRevenue - $returns, 2);
        $costOfSales = round($cogs - $returnedCost, 2);

        return [
            'sales_revenue' => round($salesRevenue, 2),
            'returns' => round($returns, 2),
            'revenue' => $revenue,
            'cogs' => round($cogs, 2),
            'returned_cost' => round($returnedCost, 2),
            'cost_of_sales' => $costOfSales,
            'gross_profit' => round($revenue - $costOfSales, 2),
        ];
    }

    /**
     * A document's revenue: its total less output VAT and order tax, in pesos
     * (DATATEST K28/K35). Documents saved before the VAT columns existed carry
     * no VAT figure and count at their total, as they always did.
     */
    public static function netOf($grandTotal, $vatAmount, $orderTax, $baseAmount)
    {
        $total = (float) $grandTotal;
        $net = $total - (float) $vatAmount - (float) $orderTax;
        $rate = ($baseAmount !== null && $total > 0) ? (float) $baseAmount / $total : 1.0;

        return $net * $rate;
    }

    /** Lines costed at the product's average cost, quantities in base units. */
    private static function costAtAverage($table, $fk, array $ids)
    {
        return (float) DB::table("$table as d")
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'd.sale_unit_id')
            ->whereIn("d.$fk", $ids)
            ->sum(DB::raw("d.quantity * (CASE WHEN u.id IS NULL THEN 1 WHEN u.operator = '/' THEN 1 / u.operator_value ELSE u.operator_value END) * p.cost"));
    }
}
