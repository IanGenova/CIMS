<?php

namespace App\utils;

/**
 * Authoritative server-side money calculation for every order document
 * (sales, POS, purchases, quotations, returns and transfers).
 *
 * The client sends quantities, unit prices, discounts and tax settings.
 * It must never be trusted for the resulting money: line totals and the
 * grand total are recomputed here from those inputs.
 *
 * The arithmetic intentionally mirrors Calcul_Total() / Update_Detail()
 * in the Vue forms so the figure the user approved on screen is the same
 * figure that is stored.
 */
class OrderTotals
{
    /**
     * Recompute a single order line.
     *
     * @param  array   $line      One entry of the request "details" array.
     * @param  string  $priceKey  "Unit_price" for sales-side documents,
     *                            "Unit_cost" for purchase-side documents.
     * @return array{net_price: float, tax_unit: float, subtotal: float}
     */
    public static function line(array $line, $priceKey = 'Unit_price')
    {
        $unitPrice = (float) ($line[$priceKey] ?? 0);
        $quantity  = (float) ($line['quantity'] ?? 0);
        $discount  = (float) ($line['discount'] ?? 0);
        $taxPercent = (float) ($line['tax_percent'] ?? 0);

        // "2" = fixed amount, anything else = percentage.
        $discountMethod = (string) ($line['discount_Method'] ?? $line['discount_method'] ?? '2');
        // "1" = tax exclusive, anything else = tax inclusive.
        $taxMethod = (string) ($line['tax_method'] ?? '1');

        $discountNet = $discountMethod === '2'
            ? $discount
            : ($unitPrice * $discount) / 100;

        if ($taxMethod === '1') {
            // Exclusive: tax is added on top of the discounted price.
            $netPrice = $unitPrice - $discountNet;
            $taxUnit  = ($taxPercent * ($unitPrice - $discountNet)) / 100;
        } else {
            // Inclusive: the discounted price already contains the tax.
            $divisor  = ($taxPercent / 100) + 1;
            $netPrice = $divisor == 0 ? 0.0 : ($unitPrice - $discountNet) / $divisor;
            $taxUnit  = $unitPrice - $netPrice - $discountNet;
        }

        return [
            'net_price' => $netPrice,
            'tax_unit'  => $taxUnit,
            'subtotal'  => round(($quantity * $netPrice) + ($taxUnit * $quantity), 2),
        ];
    }

    /**
     * Recompute every line plus the order-level totals.
     *
     * @param  array  $details   The request "details" array.
     * @param  array  $order     ['discount' => x, 'shipping' => y, 'tax_rate' => z]
     * @param  string $priceKey  "Unit_price" or "Unit_cost".
     * @return array{lines: array<int,array>, subtotal: float, tax_net: float, grand_total: float}
     */
    public static function forOrder(array $details, array $order, $priceKey = 'Unit_price')
    {
        $lines = [];
        $subtotal = 0.0;

        foreach ($details as $key => $line) {
            $computed = self::line((array) $line, $priceKey);
            $lines[$key] = $computed;
            $subtotal += $computed['subtotal'];
        }

        $orderDiscount = (float) ($order['discount'] ?? 0);
        $orderShipping = (float) ($order['shipping'] ?? 0);
        $orderTaxRate  = (float) ($order['tax_rate'] ?? 0);

        $totalWithoutDiscount = $subtotal - $orderDiscount;
        $taxNet = ($totalWithoutDiscount * $orderTaxRate) / 100;

        return [
            'lines'       => $lines,
            'subtotal'    => round($subtotal, 2),
            'tax_net'     => round($taxNet, 2),
            'grand_total' => round($totalWithoutDiscount + $taxNet + $orderShipping, 2),
        ];
    }

    /**
     * The recomputed total for one line. Convenience wrapper for call sites
     * that only need the single figure.
     */
    public static function subtotal($line, $priceKey = 'Unit_price')
    {
        return self::line((array) $line, $priceKey)['subtotal'];
    }

    /**
     * The recomputed grand total for a whole document.
     */
    public static function grand($details, array $order, $priceKey = 'Unit_price')
    {
        return self::forOrder((array) $details, $order, $priceKey)['grand_total'];
    }

    /**
     * The recomputed order-level tax for a whole document.
     */
    public static function tax($details, array $order, $priceKey = 'Unit_price')
    {
        return self::forOrder((array) $details, $order, $priceKey)['tax_net'];
    }
}
