<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * Why a settings record cannot be deleted yet, in words for the screen; null
 * when it can. Deleting these used to go straight through: a warehouse took
 * its stock rows with it, and the base currency or a category in use could
 * simply vanish (tests/Feature/DeleteGuardTest.php).
 */
class DeleteGuard
{
    private static function plural($n, $word)
    {
        $n = (float) $n;
        $shown = rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');

        return $shown . ' ' . $word . ($n == 1 ? '' : 's');
    }

    /**
     * Nothing to delete: the id does not exist or was deleted already. Answered
     * with a 404 rather than "success", and a second delete must not overwrite
     * the first one's time (DATATEST K70).
     */
    public static function missing($table, $id)
    {
        return ! \DB::table($table)->where('id', $id)->whereNull('deleted_at')->exists();
    }

    public static function notFound()
    {
        return response()->json(['message' => 'That record does not exist or was already deleted.'], 404);
    }

    public static function warehouse($id)
    {
        $stock = DB::table('product_warehouse')->where('warehouse_id', $id)
            ->whereNull('deleted_at')->where('qte', '>', 0)
            ->selectRaw('COUNT(DISTINCT product_id) as products, COALESCE(SUM(qte), 0) as units')->first();

        if ($stock && $stock->products > 0) {
            $name = DB::table('warehouses')->where('id', $id)->value('name');

            return sprintf('%s still holds stock (%s across %s). Transfer or adjust it out before deleting.',
                $name, self::plural($stock->units, 'unit'), self::plural($stock->products, 'product'));
        }

        // Nor while a document is still open against it: an order not yet
        // received, a sale not yet completed, a transfer under way (K36).
        $open = DB::table('purchases')->whereNull('deleted_at')->where('warehouse_id', $id)
                ->where('statut', '!=', 'received')->where('approval_status', '!=', 'rejected')->count()
            + DB::table('sales')->whereNull('deleted_at')->where('warehouse_id', $id)
                ->whereIn('statut', ['pending', 'ordered'])->count()
            + DB::table('transfers')->whereNull('deleted_at')->where('statut', '!=', 'completed')
                ->where(function ($q) use ($id) {
                    $q->where('from_warehouse_id', $id)->orWhere('to_warehouse_id', $id);
                })->count();
        if ($open > 0) {
            $name = DB::table('warehouses')->where('id', $id)->value('name');

            return sprintf('%s still has %s open (orders, pending sales or transfers). Finish or cancel them first.',
                $name, self::plural($open, 'document'));
        }

        return null;
    }

    /** A product whose stock would vanish with it (DATATEST K36). */
    public static function product($id)
    {
        $onHand = (float) DB::table('product_warehouse')->whereNull('deleted_at')->where('product_id', $id)->sum('qte');
        if (abs($onHand) > 0.000001) {
            $name = DB::table('products')->where('id', $id)->value('name');

            return sprintf('%s still has %s on hand. Sell, transfer or adjust the stock to zero before deleting it.',
                $name, self::plural($onHand, 'unit'));
        }

        return null;
    }

    /** A unit a product is still counted, sold or bought in (DATATEST K36). */
    public static function unit($id)
    {
        $count = DB::table('products')->whereNull('deleted_at')
            ->where(function ($q) use ($id) {
                $q->where('unit_id', $id)->orWhere('unit_sale_id', $id)->orWhere('unit_purchase_id', $id);
            })->count();
        if ($count === 0) {
            return null;
        }
        $name = DB::table('units')->where('id', $id)->value('name');

        return sprintf('%s is still used by %s. Change their units first.', $name, self::plural($count, 'product'));
    }

    /** A customer who still owes, or is owed, money (DATATEST K36). */
    public static function client($id)
    {
        $balance = (float) (CreditService::balanceFor($id)['balance'] ?? 0);
        if (abs($balance) >= 0.005) {
            $name = DB::table('clients')->where('id', $id)->value('name');

            return sprintf('%s has an open balance of %s. Settle it before deleting the customer.',
                $name, number_format($balance, 2));
        }

        return null;
    }

    public static function currency($id)
    {
        $currency = DB::table('currencies')->where('id', $id)->first();
        if (! $currency) {
            return null;
        }
        if ($currency->is_base) {
            return $currency->name . ' is the base currency. Make another currency the base first.';
        }
        if ((int) DB::table('settings')->whereNull('deleted_at')->value('currency_id') === (int) $id) {
            return $currency->name . ' is the default currency in System Settings. Choose another default first.';
        }
        // A document in this currency would lose its rate (DATATEST K36).
        foreach (['sales' => 'sale', 'purchases' => 'purchase', 'quotations' => 'quotation',
            'sale_returns' => 'return', 'purchase_returns' => 'supplier return'] as $table => $word) {
            $n = DB::table($table)->whereNull('deleted_at')->where('currency_id', $id)->count();
            if ($n > 0) {
                return sprintf('%s is used by %s. It cannot be deleted.', $currency->name, self::plural($n, $word));
            }
        }

        return null;
    }

    public static function category($id)
    {
        return self::productsUsing('category_id', $id, 'categories', 'category');
    }

    public static function brand($id)
    {
        return self::productsUsing('brand_id', $id, 'brands', 'brand');
    }

    private static function productsUsing($column, $id, $table, $kind)
    {
        $count = DB::table('products')->whereNull('deleted_at')->where($column, $id)->count();
        if ($count === 0) {
            return null;
        }
        $name = DB::table($table)->where('id', $id)->value('name');

        return sprintf('%s is still used by %s. Move those products to another %s first.',
            $name, self::plural($count, 'product'), $kind);
    }

    /** The first refusal among several ids, or null when all may go. */
    public static function firstReason($check, array $ids)
    {
        foreach ($ids as $id) {
            $reason = self::$check($id);
            if ($reason) {
                return $reason;
            }
        }

        return null;
    }
}
