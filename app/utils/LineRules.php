<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What every document line must be, whatever the screen sent
 * (.flow/DATATEST-2026-10-06.md K6, K13, K20, K23, K53).
 *
 * A line names a product, perhaps a variant, a unit and a quantity. The
 * controllers each checked a different subset of this, so a negative transfer
 * pulled stock out of a branch, a unit from another family sold ten bags for
 * the price of one, a variant of another product created stock from nothing,
 * and half a bag of cement could be sold. One place now says what a line is.
 */
class LineRules
{
    /**
     * @param array       $details  the request's lines
     * @param string|null $unitKey  'sale_unit_id' | 'purchase_unit_id' | null (quantity is in base units)
     * @param array       $opts     'stock' => true when the document moves stock (services refused)
     */
    public static function check($details, $unitKey = null, array $opts = [])
    {
        $stock = $opts['stock'] ?? true;

        foreach (array_values((array) $details) as $i => $line) {
            $productId = $line['product_id'] ?? null;
            $product = $productId ? DB::table('products')->where('id', $productId)->first() : null;
            if (! $product || $product->deleted_at !== null) {
                self::fail($i, 'This product does not exist or has been deleted.');
            }
            $name = $product->name;

            $qty = $line['quantity'] ?? null;
            if (! is_numeric($qty) || (float) $qty <= 0) {
                self::fail($i, "{$name}: the quantity must be more than zero.", 'quantity');
            }

            if ($stock && $product->is_service) {
                self::fail($i, "{$name} is a service and has no stock.");
            }

            self::variant($i, $product, $line['product_variant_id'] ?? null);

            $unit = null;
            if ($unitKey && ! empty($line[$unitKey])) {
                $unit = DB::table('units')->where('id', $line[$unitKey])->whereNull('deleted_at')->first();
                if (! $unit || ! self::sameFamily($unit, $product->unit_id)) {
                    self::fail($i, "{$name} is not sold or bought in that unit.");
                }
            }

            $base = $unit ? self::toBase((float) $qty, $unit) : (float) $qty;
            if (! self::divisible($product->unit_id) && abs($base - round($base)) > 0.000001) {
                self::fail($i, "{$name} is counted in whole units; " . rtrim(rtrim(number_format($base, 6, '.', ''), '0'), '.') . ' is not a whole number.', 'quantity');
            }
        }
    }

    /** A variant product names one of its own variants; a plain product names none. */
    public static function variant($i, $product, $variantId)
    {
        if ($product->is_variant) {
            $ok = $variantId && DB::table('product_variants')->where('id', $variantId)
                ->where('product_id', $product->id)->whereNull('deleted_at')->exists();
            if (! $ok) {
                self::fail($i, "{$product->name}: choose which variant.");
            }
        } elseif ($variantId) {
            self::fail($i, "{$product->name} has no variants.");
        }
    }

    /** The unit is the product's base unit, or one defined on it. */
    public static function sameFamily($unit, $baseUnitId)
    {
        return (int) $unit->id === (int) $baseUnitId || (int) $unit->base_unit === (int) $baseUnitId;
    }

    public static function toBase($qty, $unit)
    {
        $value = (float) $unit->operator_value ?: 1;

        return $unit->operator === '/' ? $qty / $value : $qty * $value;
    }

    /** Kilograms, metres, litres may be split; pieces, bags and sheets may not. */
    public static function divisible($unitId)
    {
        return (bool) DB::table('units')->where('id', $unitId)->value('is_divisible');
    }

    /** A unit's own definition: a positive factor, multiply or divide. */
    public static function unitRules()
    {
        return [
            'operator' => 'nullable|in:*,/',
            'operator_value' => 'nullable|numeric|gt:0',
        ];
    }

    /** Under the field the screen shows it against: "details.0.quantity" (DATATEST K69). */
    private static function fail($i, $message, $field = null)
    {
        throw ValidationException::withMessages([($field ? "details.$i.$field" : "details.$i") => [$message]]);
    }
}
