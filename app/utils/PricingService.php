<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * Resolves what a product costs a particular customer, and what tax applies.
 *
 * Both answers used to be single columns on the product: products.price and
 * products.TaxNet. Those remain the fallback, so a deployment that sets up
 * neither price lists nor tax groups behaves exactly as it did before.
 */
class PricingService
{
    /** @var array<string,mixed> memoised for the life of one request */
    private static $cache = [];

    public static function forget()
    {
        self::$cache = [];
    }

    /**
     * The unit price for one product, for one customer, at one quantity.
     *
     * Resolution order, first match wins:
     *   1. a price list row for the product whose band the quantity reaches
     *   2. the customer group's blanket discount off the list price
     *   3. products.price
     *
     * @return array{price: float, source: string, list_price: float}
     */
    public static function priceFor($productId, $clientId = null, $quantity = 1, $date = null)
    {
        $product = self::product($productId);
        $listPrice = $product ? (float) $product->price : 0.0;

        if (! $clientId) {
            return ['price' => $listPrice, 'source' => 'product', 'list_price' => $listPrice];
        }

        $client = self::client($clientId);
        if (! $client || ! $client->customer_group_id) {
            return ['price' => $listPrice, 'source' => 'product', 'list_price' => $listPrice];
        }

        $date = $date ?: date('Y-m-d');

        // The highest band the quantity qualifies for, on a list live that day.
        $row = DB::table('price_list_items as i')
            ->join('price_lists as l', 'l.id', '=', 'i.price_list_id')
            ->where('l.customer_group_id', $client->customer_group_id)
            ->where('l.is_active', true)
            ->whereNull('l.deleted_at')
            ->where(function ($q) use ($date) {
                $q->whereNull('l.starts_on')->orWhere('l.starts_on', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('l.ends_on')->orWhere('l.ends_on', '>=', $date);
            })
            ->where('i.product_id', $productId)
            ->where('i.min_quantity', '<=', (float) $quantity)
            ->orderByDesc('i.min_quantity')
            ->select('i.price', 'i.discount_percent')
            ->first();

        if ($row) {
            if ($row->price !== null) {
                return ['price' => (float) $row->price, 'source' => 'price_list', 'list_price' => $listPrice];
            }
            if ($row->discount_percent !== null) {
                $p = $listPrice - ($listPrice * (float) $row->discount_percent / 100);
                return ['price' => round($p, 4), 'source' => 'price_list_discount', 'list_price' => $listPrice];
            }
        }

        $groupDiscount = (float) self::groupDiscount($client->customer_group_id);
        if ($groupDiscount > 0) {
            $p = $listPrice - ($listPrice * $groupDiscount / 100);
            return ['price' => round($p, 4), 'source' => 'customer_group', 'list_price' => $listPrice];
        }

        return ['price' => $listPrice, 'source' => 'product', 'list_price' => $listPrice];
    }

    /**
     * The effective tax percentage for a product, for a given customer.
     *
     * A tax group replaces the flat TaxNet with a set of components. Simple
     * components all apply to the net price; a compound component applies to
     * the net price plus everything charged before it. The result is folded
     * back into a single percentage so it drops straight into OrderTotals
     * without changing how a line is computed.
     *
     * @return array{percent: float, source: string, components: array}
     */
    public static function taxFor($productId, $clientId = null)
    {
        if ($clientId) {
            $client = self::client($clientId);
            if ($client && $client->is_tax_exempt) {
                return ['percent' => 0.0, 'source' => 'exempt', 'components' => []];
            }
        }

        $product = self::product($productId);
        if (! $product) {
            return ['percent' => 0.0, 'source' => 'none', 'components' => []];
        }

        if (! $product->tax_group_id) {
            return ['percent' => (float) $product->TaxNet, 'source' => 'product', 'components' => []];
        }

        $rates = self::groupRates($product->tax_group_id);
        if (empty($rates)) {
            return ['percent' => (float) $product->TaxNet, 'source' => 'product', 'components' => []];
        }

        // Work on a notional net of 100 so the charged total is directly a percentage.
        $base = 100.0;
        $running = $base;
        $charged = 0.0;
        $components = [];

        foreach ($rates as $r) {
            $on = $r->is_compound ? $running : $base;
            $amount = $on * ((float) $r->rate) / 100;
            $charged += $amount;
            $running += $amount;
            $components[] = [
                'name' => $r->name,
                'rate' => (float) $r->rate,
                'is_compound' => (bool) $r->is_compound,
                'effective_percent' => round($amount, 6),
            ];
        }

        return [
            'percent' => round($charged, 6),
            'source' => 'tax_group',
            'components' => $components,
        ];
    }

    /**
     * Stamp resolved tax (and optionally price) onto a request "details" array
     * so the rest of the pipeline sees authoritative figures rather than
     * whatever the browser sent.
     *
     * Only the tax percentage is forced. A unit price the caller supplied is
     * left alone, because overriding a price on a document is a legitimate
     * thing for a salesperson to do -- what must not happen is the tax
     * quietly differing from the customer's actual liability.
     */
    public static function applyToDetails(array $details, $clientId = null, $forcePrice = false)
    {
        // Owner decision (DATATEST K3): only a user allowed to override prices
        // may change a price or give a line discount. Everyone else sells at
        // the list price, whatever the screen sent -- a cashier sold cement at
        // 1.00 and the server stored it.
        $mayOverride = self::mayOverride();

        foreach ($details as $k => $line) {
            $line = (array) $line;
            $productId = isset($line['product_id']) ? $line['product_id'] : null;
            if (! $productId) {
                continue;
            }
            $qty = (float) (isset($line['quantity']) ? $line['quantity'] : 1);

            $tax = self::taxFor($productId, $clientId);
            $line['tax_percent'] = $tax['percent'];
            // How the price carries the tax is the product's, not the browser's:
            // "exclusive" sent for a VAT-inclusive item charged 12% twice (K3).
            $line['tax_method'] = (string) (DB::table('products')->where('id', $productId)->value('tax_method') ?? '1');

            // The list price per base unit, scaled to the line's unit: a box of
            // ten is ten times a piece.
            $unit = ! empty($line['sale_unit_id']) ? DB::table('units')->where('id', $line['sale_unit_id'])->first() : null;
            $baseQty = $unit ? LineRules::toBase($qty, $unit) : $qty;
            $listPrice = self::priceFor($productId, $clientId, $baseQty)['price'] * ($unit ? LineRules::toBase(1, $unit) : 1);

            $missingPrice = ! isset($line['Unit_price']) || $line['Unit_price'] === '' || $line['Unit_price'] === null;
            if ($forcePrice || $missingPrice || ! $mayOverride) {
                $line['Unit_price'] = round($listPrice, 4);
            }
            if (! $mayOverride) {
                $line['discount'] = 0;
            }

            // A discount never takes a line below zero (K3: a 300 discount on a
            // 265 bag stored a negative line and negative VAT).
            $discount = (float) ($line['discount'] ?? 0);
            $percent = (string) ($line['discount_Method'] ?? $line['discount_method'] ?? '2') !== '2';
            if ($discount < 0 || ($percent ? $discount > 100 : $discount > (float) $line['Unit_price'] + 0.000001)) {
                throw \Illuminate\Validation\ValidationException::withMessages(["details.$k.discount" => [
                    'A discount cannot be more than the price of the line.',
                ]]);
            }

            $details[$k] = $line;
        }

        return $details;
    }

    /** The signed-in user's role holds "price_override" (see the migration). */
    public static function mayOverride()
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (! $user) {
            return false;
        }

        return DB::table('permissions as p')
            ->join('permission_role as pr', 'pr.permission_id', '=', 'p.id')
            ->join('role_user as ru', 'ru.role_id', '=', 'pr.role_id')
            ->where('p.name', 'price_override')
            ->where('ru.user_id', $user->id)
            ->exists();
    }

    // ---- lookups, memoised so a 50-line order does not issue 150 queries ----

    private static function product($id)
    {
        $k = 'p' . $id;
        if (! array_key_exists($k, self::$cache)) {
            self::$cache[$k] = DB::table('products')
                ->select('id', 'price', 'TaxNet', 'tax_group_id')
                ->where('id', $id)->first();
        }

        return self::$cache[$k];
    }

    private static function client($id)
    {
        $k = 'c' . $id;
        if (! array_key_exists($k, self::$cache)) {
            self::$cache[$k] = DB::table('clients')
                ->select('id', 'customer_group_id', 'is_tax_exempt')
                ->where('id', $id)->first();
        }

        return self::$cache[$k];
    }

    private static function groupDiscount($groupId)
    {
        $k = 'g' . $groupId;
        if (! array_key_exists($k, self::$cache)) {
            self::$cache[$k] = DB::table('customer_groups')
                ->where('id', $groupId)->whereNull('deleted_at')->where('is_active', true)
                ->value('default_discount_percent') ?: 0;
        }

        return self::$cache[$k];
    }

    private static function groupRates($taxGroupId)
    {
        $k = 't' . $taxGroupId;
        if (! array_key_exists($k, self::$cache)) {
            self::$cache[$k] = DB::table('tax_group_rates')
                ->where('tax_group_id', $taxGroupId)
                ->orderBy('sort_order')->orderBy('id')
                ->get()->all();
        }

        return self::$cache[$k];
    }
}
