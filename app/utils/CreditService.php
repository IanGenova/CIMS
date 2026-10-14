<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer credit control.
 *
 * What a customer owes is: whatever they were carrying when they were entered
 * into this system, plus everything invoiced to them, minus everything they
 * have paid. Draft and pending orders are excluded -- nothing is owed until
 * the goods have gone out.
 */
class CreditService
{
    /**
     * The outstanding balance for one customer, and the parts it is made of.
     *
     * @return array{opening: float, invoiced: float, paid: float, returned: float, balance: float}
     */
    public static function balanceFor($clientId)
    {
        $client = DB::table('clients')->where('id', $clientId)->first();
        if (! $client) {
            return ['opening' => 0.0, 'invoiced' => 0.0, 'paid' => 0.0, 'returned' => 0.0, 'balance' => 0.0];
        }

        $opening = (float) $client->opening_balance;

        // Only completed sales represent a debt.
        $invoiced = (float) DB::table('sales')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->where('statut', 'completed')
            ->sum('GrandTotal');

        $paid = (float) DB::table('sales')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->where('statut', 'completed')
            ->sum('paid_amount');

        // Credit notes reduce what is owed -- except the part already handed
        // back as a refund. Sale returns are saved "received"; counting only
        // "completed" ones ignored every return (DATATEST K31).
        $returned = 0.0;
        if (\Schema::hasTable('sale_returns')) {
            $returns = DB::table('sale_returns')
                ->whereNull('deleted_at')
                ->where('client_id', $clientId)
                ->whereIn('statut', ['received', 'completed'])
                ->selectRaw('COALESCE(SUM(GrandTotal), 0) AS total, COALESCE(SUM(paid_amount), 0) AS refunded')
                ->first();
            $returned = (float) $returns->total - (float) $returns->refunded;
        }

        return [
            'opening' => round($opening, 2),
            'invoiced' => round($invoiced, 2),
            'paid' => round($paid, 2),
            'returned' => round($returned, 2),
            'balance' => round($opening + $invoiced - $paid - $returned, 2),
        ];
    }

    /**
     * Refuse a sale that would push a customer past their ceiling.
     *
     * A limit of zero means no ceiling, which is where every existing customer
     * starts -- so this changes nothing until somebody sets a limit.
     *
     * @param  float  $orderTotal    the total of the order being placed
     * @param  float  $payingNow     what is being paid at the till
     * @param  float  $alreadyOnDoc  for an edit, the total this document already contributes
     */
    public static function assertWithinLimit($clientId, $orderTotal, $payingNow = 0, $alreadyOnDoc = 0)
    {
        // Inside the sale's transaction the customer row is locked, so two tills
        // selling to the same customer at once check one after the other: both
        // used to see room and together went 448 on a 300 limit (DATATEST K11).
        $q = DB::table('clients')->where('id', $clientId);
        if (DB::transactionLevel() > 0) {
            $q->lockForUpdate();
        }
        $client = $q->first();
        if (! $client) {
            return;
        }

        $limit = (float) $client->credit_limit;
        if ($limit <= 0) {
            return;
        }

        $current = self::balanceFor($clientId)['balance'];
        $projected = $current - (float) $alreadyOnDoc + (float) $orderTotal - (float) $payingNow;

        if ($projected > $limit) {
            throw ValidationException::withMessages([
                'credit_limit' => [
                    'This sale would take ' . $client->name . ' to ' . number_format($projected, 2)
                    . ', over their credit limit of ' . number_format($limit, 2)
                    . '. Take a payment, or raise the limit on the customer record.',
                ],
            ]);
        }
    }

    /**
     * Unpaid completed sales bucketed by how overdue they are.
     *
     * @return array{buckets: array, rows: array, total: float}
     */
    public static function ageing($clientId = null, $asOf = null)
    {
        $asOf = $asOf ?: date('Y-m-d');

        $q = DB::table('sales as s')
            ->join('clients as c', 'c.id', '=', 's.client_id')
            ->whereNull('s.deleted_at')
            ->where('s.statut', 'completed')
            ->whereRaw('s.GrandTotal > s.paid_amount');

        if ($clientId) {
            $q->where('s.client_id', $clientId);
        }

        $rows = $q->select(
            's.id', 's.Ref', 's.date', 's.GrandTotal', 's.paid_amount',
            's.client_id', 'c.name as client'
        )->orderBy('s.date')->get();

        $buckets = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'over90' => 0.0];
        $out = [];
        $total = 0.0;

        foreach ($rows as $r) {
            $due = round((float) $r->GrandTotal - (float) $r->paid_amount, 2);
            $age = (int) floor((strtotime($asOf) - strtotime($r->date)) / 86400);

            if ($age <= 0) {
                $bucket = 'current';
            } elseif ($age <= 30) {
                $bucket = 'd30';
            } elseif ($age <= 60) {
                $bucket = 'd60';
            } elseif ($age <= 90) {
                $bucket = 'd90';
            } else {
                $bucket = 'over90';
            }

            $buckets[$bucket] += $due;
            $total += $due;

            $out[] = [
                'sale_id' => $r->id,
                'Ref' => $r->Ref,
                'date' => $r->date,
                'client_id' => $r->client_id,
                'client' => $r->client,
                'total' => round((float) $r->GrandTotal, 2),
                'paid' => round((float) $r->paid_amount, 2),
                'due' => $due,
                'age_days' => max(0, $age),
                'bucket' => $bucket,
            ];
        }

        foreach ($buckets as $k => $v) {
            $buckets[$k] = round($v, 2);
        }

        return ['buckets' => $buckets, 'rows' => $out, 'total' => round($total, 2)];
    }
}
