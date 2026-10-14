<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * What is owed to a supplier (DATATEST K32; owner decision: payables count
 * only goods received, less returns).
 *
 * The supplier report summed every purchase's total: a rejected order and an
 * order still on the way were "owed", and a purchase return changed nothing.
 */
class PayablesService
{
    /**
     * SQL for the value of what has arrived on purchase `$p`: the whole total
     * once it is received, otherwise the received share of its lines.
     */
    public static function receivedValueSql($p = 'purchases')
    {
        return "(CASE WHEN {$p}.statut = 'received' THEN {$p}.GrandTotal ELSE {$p}.GrandTotal * COALESCE(
            (SELECT SUM(d.received_qte * d.total / NULLIF(d.quantity, 0)) FROM purchase_details d WHERE d.purchase_id = {$p}.id)
            / NULLIF((SELECT SUM(d2.total) FROM purchase_details d2 WHERE d2.purchase_id = {$p}.id), 0), 0) END)";
    }

    /** Purchases that can be owed at all: live, and not rejected. */
    public static function owable($query, $p = 'purchases')
    {
        return $query->whereNull("{$p}.deleted_at")->where("{$p}.approval_status", '!=', 'rejected');
    }

    /** @return array{total_purchase:int,total_amount:float,total_paid:float,returns:float,due:float} */
    public static function forSupplier($providerId)
    {
        $purchases = self::owable(DB::table('purchases'))->where('provider_id', $providerId);

        $received = (float) (clone $purchases)->sum(DB::raw(self::receivedValueSql()));

        $paid = (float) DB::table('payment_purchases as pp')
            ->join('purchases', 'purchases.id', '=', 'pp.purchase_id')
            ->whereNull('pp.deleted_at')->whereNull('purchases.deleted_at')
            ->where('purchases.provider_id', $providerId)
            ->sum('pp.montant');

        // Goods sent back: the supplier owes them to us, until it refunds them.
        $returned = (float) DB::table('purchase_returns')->whereNull('deleted_at')
            ->where('provider_id', $providerId)->sum('GrandTotal');
        $refunded = (float) DB::table('payment_purchase_returns as pr')
            ->join('purchase_returns as r', 'r.id', '=', 'pr.purchase_return_id')
            ->whereNull('pr.deleted_at')->whereNull('r.deleted_at')
            ->where('r.provider_id', $providerId)
            ->sum('pr.montant');

        return [
            'total_purchase' => (clone $purchases)->count(),
            'total_amount' => round($received, 2),
            'total_paid' => round($paid, 2),
            'returns' => round($returned - $refunded, 2),
            'due' => round($received - $paid - ($returned - $refunded), 2),
        ];
    }
}
