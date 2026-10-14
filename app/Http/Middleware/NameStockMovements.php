<?php

namespace App\Http\Middleware;

use App\utils\StockLedger;
use Closure;

/**
 * Name a stock movement after the request that caused it.
 *
 * Stock changes from every document's store(), update() and destroy(). Threading
 * a context through all of them would be a lot of edits for a small gain, and
 * anything missed would land in the ledger as 'unknown'. The route already says
 * what the user was doing, so read it once here and leave the answer where
 * StockLedger will find it.
 *
 * This is only a fallback. Where a controller sets a context of its own it wins,
 * because it also carries the document reference and the cost -- which this
 * cannot know.
 */
class NameStockMovements
{
    /**
     * API path prefix -> what to call movements made under it.
     *
     * Longest first: both kinds of return live under returns/, so the two-segment
     * prefixes have to be tried before anything shorter.
     */
    private static $map = [
        'manufacturing/orders' => ['production', 'ProductionOrder'],
        'returns/purchase' => ['purchase_return', 'PurchaseReturn'],
        'returns/sale' => ['sale_return', 'SaleReturn'],
        'adjustments' => ['adjustment', 'Adjustment'],
        'goods_receipts' => ['goods_receipt', 'GoodsReceipt'],
        'stock_counts' => ['stock_count', 'StockCount'],
        'purchases' => ['purchase', 'Purchase'],
        'transfers' => ['transfer', 'Transfer'],
        'sales' => ['sale', 'Sale'],
        'pos' => ['pos', null],
        'products' => ['adjustment', null],
    ];

    public function handle($request, Closure $next)
    {
        [$type, $refType, $refId] = $this->contextFor($request);
        StockLedger::fallback($type, $refType, $refId);

        return $next($request);
    }

    /**
     * 'api/sales/12' (edit or delete) -> ['sale', 'Sale', 12]: the movements an
     * edit or delete makes belong to that document, so a later void or undo can
     * find them (DATATEST K1). A path with no id names the type only.
     */
    private function contextFor($request)
    {
        // 'api/returns/purchase/12' -> 'returns/purchase/12'
        $path = strtolower(ltrim($request->path(), '/'));
        if (strpos($path, 'api/') === 0) {
            $path = substr($path, 4);
        }

        foreach (self::$map as $prefix => [$type, $refType]) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                $next = explode('/', substr($path, strlen($prefix) + 1))[0] ?? '';
                $id = ($refType && ctype_digit($next)) ? (int) $next : null;

                return [$type, $id ? $refType : null, $id];
            }
        }

        return [null, null, null];
    }
}
