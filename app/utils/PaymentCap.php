<?php

namespace App\utils;

use Illuminate\Validation\ValidationException;

/**
 * One rule for every payment against a document (DATATEST K4/K42/K48/K49):
 * what is paid stays between zero and the document's total, in whole
 * centavos, with a method named.
 *
 * Only creating a sale or purchase payment was capped. Refunds, payment edits
 * and purchase payment edits were not -- three refunds of 56.70 paid 170.10 on
 * a 56.70 return, and an edit pushed another supplier's bill to -99,899.
 */
class PaymentCap
{
    /** Rules for the amount and the method of a payment. */
    public static function rules()
    {
        return [
            'montant' => ['required', 'numeric', 'gt:0', function ($attribute, $value, $fail) {
                if (! PaymentStatus::isCentavos($value)) {
                    $fail('The amount must be in whole centavos (at least 0.01).');
                }
            }],
            'Reglement' => 'required|string',
        ];
    }

    /**
     * The status a document will have once $newPaid has been paid on it.
     * Refuses an amount above its total. Call with the document locked.
     */
    public static function status($doc, $newPaid)
    {
        $total = (int) round((float) $doc->GrandTotal * 100);
        $paid = (int) round((float) $newPaid * 100);

        if ($paid > $total) {
            $left = max(0, $total - (int) round((float) $doc->paid_amount * 100)) / 100;
            throw ValidationException::withMessages(['montant' => [
                'This would pay ' . number_format($paid / 100, 2) . ' on a total of ' . number_format($total / 100, 2)
                    . '. Only ' . number_format($left, 2) . ' is still due.',
            ]]);
        }

        return PaymentStatus::of($total / 100, $paid / 100);
    }
}
