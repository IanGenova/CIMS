<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conversion between a document's currency and the base currency.
 *
 * Rates are stored as "how many units of this currency make one unit of the
 * base currency" -- the base currency itself is always 1. A document snapshots
 * the rate it was written at, because editing a rate must restate future
 * documents, never historical ones.
 */
class CurrencyService
{
    private static $base = null;

    public static function forget()
    {
        self::$base = null;
    }

    /** The base currency row, or null if none is marked. */
    public static function base()
    {
        if (self::$base === null) {
            self::$base = DB::table('currencies')
                ->whereNull('deleted_at')->where('is_base', true)->first() ?: false;
        }

        return self::$base ?: null;
    }

    public static function baseId()
    {
        $b = self::base();

        return $b ? $b->id : null;
    }

    /**
     * The rate to use for a document. Falls back to 1 so a deployment that
     * never touches currencies behaves exactly as it did before.
     */
    public static function rateFor($currencyId)
    {
        if (! $currencyId) {
            return 1.0;
        }

        $row = DB::table('currencies')->whereNull('deleted_at')->where('id', $currencyId)->first();
        if (! $row) {
            return 1.0;
        }

        $rate = (float) $row->exchange_rate;

        // A zero or negative rate would silently zero out or invert a total.
        if ($rate <= 0) {
            throw ValidationException::withMessages([
                'currency' => ['Currency ' . $row->code . ' has no usable exchange rate. Set a rate greater than zero before transacting in it.'],
            ]);
        }

        return $rate;
    }

    /** Convert an amount in the given currency into the base currency. */
    public static function toBase($amount, $currencyId, $rate = null)
    {
        $rate = $rate !== null ? (float) $rate : self::rateFor($currencyId);

        return round(((float) $amount) / ($rate ?: 1), 4);
    }

    /**
     * Everything a document needs to record about its currency, resolved once.
     *
     * @return array{currency_id: int|null, exchange_rate: float}
     */
    public static function stampFor($currencyId = null)
    {
        $currencyId = $currencyId ?: self::baseId();

        return [
            'currency_id' => $currencyId,
            'exchange_rate' => self::rateFor($currencyId),
        ];
    }
}
