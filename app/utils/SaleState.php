<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * When a sale may still change (DATATEST K15).
 *
 * VoidService refused to void a sale twice or after its shift was counted
 * down, but the other doors stayed open: a voided sale could be paid, edited
 * back to completed (taking stock again), and a sale from a closed Z-reading
 * could be deleted. A voided sale is final, and a counted-down shift is closed
 * to every change; corrections go through a sale return.
 */
class SaleState
{
    /**
     * A sale that goods have come back on cannot be voided or deleted: its
     * returned units are already on the shelf, and undoing the sale would put
     * them there a second time (DATATEST K5). Delete the return first.
     */
    public static function assertNoReturns($saleId, $doing = 'deleted')
    {
        $ref = DB::table('sale_returns')->where('sale_id', $saleId)->whereNull('deleted_at')->value('Ref');
        if ($ref) {
            $sale = DB::table('sales')->where('id', $saleId)->value('Ref');
            throw ValidationException::withMessages(['sale' => [
                "Sale {$sale} has a return ({$ref}), so it cannot be {$doing}. Delete the return first.",
            ]]);
        }
    }

    /** @param object|int $sale a sales row (or its id) */
    public static function assertChangeable($sale, $doing = 'changed')
    {
        if (is_numeric($sale)) {
            $sale = DB::table('sales')->where('id', $sale)->first();
        }
        if (! $sale) {
            return;
        }

        if ($sale->statut === 'voided' || $sale->voided_at !== null) {
            throw ValidationException::withMessages(['sale' => ["Sale {$sale->Ref} was voided and cannot be {$doing}."]]);
        }

        if (! empty($sale->till_session_id)
            && DB::table('till_sessions')->where('id', $sale->till_session_id)->value('status') !== 'open') {
            throw ValidationException::withMessages(['sale' => [
                "Sale {$sale->Ref} belongs to a shift that has been counted down, so it cannot be {$doing}. Use a sale return instead.",
            ]]);
        }
    }
}
