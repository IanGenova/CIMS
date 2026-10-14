<?php

namespace App\utils;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every movement of stock, and the cost that moves with it.
 *
 * WHY THIS IS A MODEL HOOK RATHER THAN A CALL IN EACH CONTROLLER
 *
 * Stock is changed from a dozen places -- sales, the till, receipts, returns,
 * adjustments, transfers, counts, production -- and any of them that forgot to
 * log would leave exactly the hole this exists to close. So the recording hangs
 * off product_warehouse itself: if the balance changed, a movement is written,
 * whether or not the code that changed it knew about this class.
 *
 * A movement written without context is still a movement. It is typed "unknown"
 * and carries no document reference, which is worse than a described one but far
 * better than silence -- and it makes an unwired path visible rather than
 * invisible. Callers add meaning with context():
 *
 *     StockLedger::context('sale', 'Sale', $order->id, ['unit_cost' => ...]);
 *     ... change stock ...
 *     StockLedger::clear();
 *
 * COSTING
 *
 * Moving weighted average, held on products.cost. On a receipt at a known cost:
 *
 *     new average = (on hand x old average + received x cost) / (on hand + received)
 *
 * Issues do not change the average; they are valued at it. The average is kept
 * per product rather than per warehouse, so moving stock between warehouses does
 * not revalue it -- a transfer is not a purchase.
 */
class StockLedger
{
    /** Ambient context for whatever is about to touch stock. */
    private static $ctx = null;

    /** Set false to record nothing -- used while migrating or seeding. */
    public static $enabled = true;

    /**
     * What to call a movement when nothing set a context.
     *
     * Stock is changed from more places than are worth threading a context
     * through -- every update() and destroy() of every document, as well as the
     * store(). Middleware reads the route and leaves the answer here, so a
     * movement from an unwired path is still named after the thing that caused
     * it rather than landing as 'unknown'. An explicit context() always wins,
     * because it also carries the document reference and the cost.
     */
    private static $fallbackType = null;
    private static $fallbackRef = [null, null];

    /**
     * The route's own answer: what was done, and -- for an edit, delete or
     * completion of one document -- which document (DATATEST K1). Without the
     * reference, a void after an edit missed the edit's movements and gave back
     * the wrong quantity.
     */
    public static function fallback($type, $refType = null, $refId = null)
    {
        self::$fallbackType = $type;
        self::$fallbackRef = [$refType, $refId];
    }

    public static function context($type, $refType = null, $refId = null, array $extra = [])
    {
        self::$ctx = array_merge([
            'type' => $type,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'unit_cost' => null,
            'notes' => null,
        ], $extra);
    }

    public static function clear()
    {
        self::$ctx = null;
    }

    /** Run a closure with context set, and clear it however the closure ends. */
    public static function during($type, $refType, $refId, callable $fn, array $extra = [])
    {
        $previous = self::$ctx;
        self::context($type, $refType, $refId, $extra);

        try {
            return $fn();
        } finally {
            self::$ctx = $previous;
        }
    }

    /** Amend the current context, e.g. the unit cost of the line being received. */
    public static function withCost($unitCost)
    {
        if (self::$ctx !== null) {
            self::$ctx['unit_cost'] = $unitCost;
        }
    }

    /**
     * Record one change. Called from the product_warehouse model after a save.
     *
     * @param  float  $delta         signed change in quantity
     * @param  float  $balanceAfter  the stored balance after the change
     */
    public static function record($productId, $warehouseId, $variantId, $delta, $balanceAfter)
    {
        if (! self::$enabled || abs((float) $delta) < 0.000001) {
            return;
        }

        $ctx = self::$ctx ?: ['type' => self::$fallbackType ?: 'unknown',
            'reference_type' => self::$fallbackRef[0], 'reference_id' => self::$fallbackRef[1],
            'unit_cost' => null, 'notes' => null];

        if (self::$buffer !== null) {
            self::$buffer[] = [$productId, $warehouseId, $variantId, (float) $delta, (float) $balanceAfter, $ctx];

            return;
        }

        self::write($productId, $warehouseId, $variantId, $delta, $balanceAfter, $ctx);
    }

    /** Collected movements while netted() runs; null when writing directly. */
    private static $buffer = null;

    /**
     * Record a block of work as its net effect per product, warehouse and
     * document (DATATEST K52). An edit that undoes and re-applies a document
     * wrote two cancelling movements per line; completing a "sent" transfer
     * re-took the source it had already left. Only what actually changed is
     * written. Call it inside the transaction, so the rows commit with it.
     */
    public static function netted(callable $fn)
    {
        $outer = self::$buffer;
        self::$buffer = [];
        try {
            $result = $fn();
            $rows = self::$buffer;
        } finally {
            self::$buffer = $outer;
        }

        $groups = [];
        foreach ($rows as [$productId, $warehouseId, $variantId, $delta, $balanceAfter, $ctx]) {
            $key = implode('|', [$productId, $warehouseId, $variantId, $ctx['type'], $ctx['reference_type'], $ctx['reference_id']]);
            if (! isset($groups[$key])) {
                $groups[$key] = ['p' => $productId, 'w' => $warehouseId, 'v' => $variantId, 'net' => 0.0,
                    'balance' => $balanceAfter, 'ctx' => $ctx, 'costQty' => 0.0, 'costSum' => 0.0];
            }
            $g = &$groups[$key];
            $g['net'] += $delta;
            $g['balance'] = $balanceAfter;
            if ($delta > 0 && $ctx['unit_cost'] !== null) {
                $g['costQty'] += $delta;
                $g['costSum'] += $delta * (float) $ctx['unit_cost'];
            }
            unset($g);
        }

        foreach ($groups as $g) {
            if (abs($g['net']) < 0.000001) {
                continue;
            }
            $ctx = $g['ctx'];
            $ctx['unit_cost'] = $g['costQty'] > 0 ? $g['costSum'] / $g['costQty'] : null;
            if (self::$buffer !== null) {
                self::$buffer[] = [$g['p'], $g['w'], $g['v'], $g['net'], $g['balance'], $ctx];
            } else {
                self::write($g['p'], $g['w'], $g['v'], $g['net'], $g['balance'], $ctx);
            }
        }

        return $result;
    }

    private static function write($productId, $warehouseId, $variantId, $delta, $balanceAfter, array $ctx)
    {
        $inbound = (float) $delta > 0;

        // A receipt at a known cost moves the average. Anything else is valued
        // at whatever the average is now.
        if ($inbound && $ctx['unit_cost'] !== null) {
            $unitCost = (float) $ctx['unit_cost'];
            self::applyReceiptToAverage($productId, (float) $delta, $unitCost);
        } else {
            $unitCost = (float) self::averageCost($productId);
        }

        DB::table('stock_movements')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'movement_type' => $ctx['type'],
            'quantity' => round((float) $delta, 6),
            'balance_after' => round((float) $balanceAfter, 6),
            'unit_cost' => round($unitCost, 6),
            'total_cost' => round($unitCost * abs((float) $delta), 6),
            'reference_type' => $ctx['reference_type'],
            'reference_id' => $ctx['reference_id'],
            'user_id' => Auth::check() ? Auth::user()->id : null,
            'notes' => $ctx['notes'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Fold a receipt into the moving average.
     *
     * On hand is read across every warehouse, because the average is a property
     * of the product rather than of where it happens to be sitting.
     */
    /**
     * Take a receipt back out of the moving average (DATATEST K45): deleting a
     * received purchase removed the goods but left the average raised by them.
     * Call it before the stock leaves, while on hand still includes them.
     */
    public static function unwindReceipt($productId, $qty, $unitCost)
    {
        $qty = (float) $qty;
        $onHand = (float) DB::table('product_warehouse')
            ->where('product_id', $productId)->whereNull('deleted_at')->sum('qte');
        $after = $onHand - $qty;
        if ($qty <= 0 || $after <= 0.000001) {
            return;
        }
        $average = (float) DB::table('products')->where('id', $productId)->value('cost');
        $restored = (($average * $onHand) - ($qty * (float) $unitCost)) / $after;
        if ($restored >= 0) {
            DB::table('products')->where('id', $productId)->update(['cost' => round($restored, 6)]);
        }
    }

    public static function applyReceiptToAverage($productId, $qtyReceived, $unitCost)
    {
        $qtyReceived = (float) $qtyReceived;
        $unitCost = (float) $unitCost;

        if ($qtyReceived <= 0 || $unitCost < 0) {
            return;
        }

        // Locking reads (DATATEST K44): two receipts at once each read the old
        // average and the old on-hand -- a plain read inside a transaction returns
        // its snapshot -- and one receipt's cost was lost. The product row lock
        // makes the second wait, then read what the first committed.
        $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first(['cost']);
        if (! $product) {
            return;
        }

        // The balance before this receipt landed.
        $onHandNow = (float) DB::table('product_warehouse')
            ->where('product_id', $productId)->whereNull('deleted_at')->lockForUpdate()->sum('qte');
        $onHandBefore = $onHandNow - $qtyReceived;

        $oldAverage = (float) $product->cost;

        // Nothing on hand beforehand -- or a negative that should not happen --
        // means there is no prior cost to weight against, so the receipt sets it.
        if ($onHandBefore <= 0.000001) {
            $newAverage = $unitCost;
        } else {
            $newAverage = (($onHandBefore * $oldAverage) + ($qtyReceived * $unitCost))
                / ($onHandBefore + $qtyReceived);
        }

        DB::table('products')->where('id', $productId)->update([
            'cost' => round($newAverage, 4),
            'cost_updated_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The current moving average for a product. */
    public static function averageCost($productId)
    {
        return (float) DB::table('products')->where('id', $productId)->value('cost');
    }

    /**
     * The movement history for a product, newest first -- the answer to
     * "why is stock 18?".
     */
    public static function history($productId, $warehouseId = null, $limit = 100)
    {
        $q = DB::table('stock_movements')->where('product_id', $productId);

        if ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }

        return $q->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Stock value at the current average, per warehouse.
     */
    public static function valuation($warehouseId = null)
    {
        $q = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->whereNull('pw.deleted_at')
            ->where('pw.qte', '>', 0);

        if ($warehouseId) {
            $q->where('pw.warehouse_id', $warehouseId);
        }

        return $q->selectRaw('pw.warehouse_id, SUM(pw.qte) AS qty, SUM(pw.qte * p.cost) AS value')
            ->groupBy('pw.warehouse_id')->get();
    }
}
