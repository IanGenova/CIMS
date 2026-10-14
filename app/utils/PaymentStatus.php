<?php

namespace App\utils;

/**
 * Paid, partial or unpaid -- decided in whole centavos (DATATEST K41/K42).
 *
 * Comparing pesos as floats left a sale paid 0.10 + 2,760.70 against 2,760.80
 * "partial" for good (a residue of 4.5e-13), and an unpaid till sale was saved
 * with no status at all.
 */
class PaymentStatus
{
    public static function of($total, $paid)
    {
        $due = (int) round((float) $total * 100);
        $in = (int) round((float) $paid * 100);

        if ($in <= 0) {
            return 'unpaid';
        }

        return $in >= $due ? 'paid' : 'partial';
    }

    /** An amount must be whole centavos and more than zero. */
    public static function isCentavos($amount)
    {
        $cents = (float) $amount * 100;

        return $cents >= 1 - 0.000001 && abs($cents - round($cents)) < 0.000001;
    }
}
