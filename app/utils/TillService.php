<?php

namespace App\utils;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The till session, and the reading taken from it.
 *
 * WHY X AND Z SHARE ONE CODE PATH
 *
 * An X-reading is a look at the shift so far; a Z-reading closes it. If they
 * were computed separately they would eventually disagree, and the whole point
 * of the pair is that the cashier can check mid-shift and get the same answer
 * the close will give. So there is one reading() and the two callers differ only
 * in whether they write the result down.
 *
 * WHAT IS IN THE DRAWER
 *
 *     opening float + cash taken + cash in - cash out - refunds = expected
 *
 * Only cash counts. A card sale is revenue but it never reaches the drawer, and
 * counting it would make every till short by the day's card takings. What lands
 * in the drawer is what each payment applied to its sale: payment_sales.montant
 * already *is* the tendered amount less the change, and `change` is recorded
 * beside it only as a note of what was handed back. Subtracting it again
 * understated every drawer by the day's change -- a ₱807.16 sale paid with
 * ₱1,000 counted as ₱614.32.
 *
 * RETURNS
 *
 * A refund comes out of the drawer that is open when it is given, not the one
 * that took the original sale -- a customer returning last week's purchase is
 * paid from today's cash. Returns are therefore matched by the session's own
 * window and warehouse, not by the sale they refund. That is stated on the
 * reading so nobody has to guess the basis.
 */
class TillService
{
    /** Cash movements that are not sales. */
    const MOVEMENT_TYPES = ['cash_in' => 'Cash in', 'cash_out' => 'Cash out'];

    /**
     * The session a sale should belong to: the one this cashier has open at this
     * warehouse. Null when there is none, which is only an error if the setting
     * says a session is required.
     */
    public static function currentFor($userId, $warehouseId, $terminal = null)
    {
        $q = DB::table('till_sessions')
            ->whereNull('deleted_at')
            ->where('status', 'open')
            ->where('warehouse_id', $warehouseId)
            ->where('user_id', $userId);

        if ($terminal) {
            $q->where('terminal', $terminal);
        }

        return $q->orderByDesc('id')->first();
    }

    /** Is a session required before the till will sell? */
    public static function isRequired()
    {
        $v = DB::table('pos_settings')->orderBy('id')->value('require_till_session');

        return (bool) $v;
    }

    /**
     * Open a drawer.
     *
     * One open session per drawer: a second cashier opening the same terminal
     * while it is still open would make both readings meaningless.
     */
    public static function open($warehouseId, $openingFloat, $terminal = 'MAIN', $notes = null)
    {
        $terminal = $terminal ?: 'MAIN';

        return DB::transaction(function () use ($warehouseId, $openingFloat, $terminal, $notes) {
            $existing = DB::table('till_sessions')->whereNull('deleted_at')
                ->where('status', 'open')
                ->where('warehouse_id', $warehouseId)
                ->where('terminal', $terminal)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['error' => 'already_open', 'session' => $existing];
            }

            // Owner decision (DATATEST K76): one open till per person per
            // warehouse. A second drawer under another terminal name split the
            // shift's sales between two readings.
            $userId = Auth::check() ? Auth::user()->id : null;
            $mine = $userId ? DB::table('till_sessions')->whereNull('deleted_at')
                ->where('status', 'open')
                ->where('warehouse_id', $warehouseId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first() : null;
            if ($mine) {
                return ['error' => 'user_has_open', 'session' => $mine];
            }

            $id = DB::table('till_sessions')->insertGetId([
                'warehouse_id' => $warehouseId,
                'terminal' => $terminal,
                'user_id' => Auth::check() ? Auth::user()->id : null,
                'opened_at' => now(),
                'opening_float' => round((float) $openingFloat, 4),
                'status' => 'open',
                'notes' => $notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['session' => DB::table('till_sessions')->where('id', $id)->first()];
        });
    }

    /** Money in or out of the drawer that is not a sale. */
    public static function move($sessionId, $type, $amount, $reason = null)
    {
        if (! array_key_exists($type, self::MOVEMENT_TYPES)) {
            return ['error' => 'bad_type'];
        }

        // Not abs(): the type says which way the money went, so a negative
        // amount means the caller meant something other than what it wrote.
        // Flipping its sign would book the opposite of the intent, silently.
        // Whole centavos only: 0.001 was stored and never counted (DATATEST K47).
        if (! \App\utils\PaymentStatus::isCentavos($amount)) {
            return ['error' => 'bad_amount'];
        }
        $amount = round((float) $amount, 2);

        return DB::transaction(function () use ($sessionId, $type, $amount, $reason) {
        // Locked: two payouts at once must not both see the same cash (K47/K11).
        $session = DB::table('till_sessions')->where('id', $sessionId)->lockForUpdate()->first();
        if (! $session || $session->status !== 'open') {
            return ['error' => 'not_open'];
        }
        // Only someone who works in that warehouse moves its cash (K14).
        WarehouseAccess::assertAllows($session->warehouse_id);

        // A payout cannot take out more than the drawer should hold: a 5,000
        // cash-out from a 1,000 float left the expected cash at -4,000 (K47).
        if ($type === 'cash_out') {
            $expected = (float) (self::reading($sessionId)['cash']['expected'] ?? 0);
            if ((int) round($amount * 100) > (int) round($expected * 100)) {
                return ['error' => 'not_enough_cash', 'expected' => $expected];
            }
        }

        $id = DB::table('till_movements')->insertGetId([
            'till_session_id' => $sessionId,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'user_id' => Auth::check() ? Auth::user()->id : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['movement' => DB::table('till_movements')->where('id', $id)->first()];
        });
    }

    /**
     * The reading. Everything the shift did, and what should be in the drawer.
     */
    public static function reading($sessionId)
    {
        $s = DB::table('till_sessions')->where('id', $sessionId)->first();
        if (! $s) {
            return null;
        }

        $from = $s->opened_at;
        $to = $s->closed_at ?: now();

        // ---- sales rung up on this session -------------------------------
        $sales = DB::table('sales')
            ->whereNull('deleted_at')
            ->where('till_session_id', $sessionId)
            ->where('statut', 'completed')
            ->get();

        $count = $sales->count();
        $net = 0.0;            // what was actually charged
        $vatable = 0.0;
        $vat = 0.0;
        $exempt = 0.0;
        $zero = 0.0;
        $statutory = 0.0;
        $regularDiscount = 0.0;
        $byStatutory = [];

        foreach ($sales as $sale) {
            $net += (float) $sale->GrandTotal;
            $vatable += (float) $sale->vatable_sales;
            $vat += (float) $sale->vat_amount;
            $exempt += (float) $sale->vat_exempt_sales;
            $zero += (float) $sale->zero_rated_sales;
            $statutory += (float) $sale->statutory_discount;
            $regularDiscount += (float) $sale->discount;

            if ($sale->statutory_type && (float) $sale->statutory_discount > 0) {
                $k = $sale->statutory_type;
                if (! isset($byStatutory[$k])) {
                    $byStatutory[$k] = ['count' => 0, 'amount' => 0.0];
                }
                $byStatutory[$k]['count']++;
                $byStatutory[$k]['amount'] += (float) $sale->statutory_discount;
            }
        }

        // A Senior/PWD sale is also VAT-exempt: the VAT taken off the price
        // before the 20% is part of what the customer did not pay. Without it
        // gross did not reconcile (DATATEST K80). The 20% is of the net-of-VAT
        // price, so that base is the discount / 20%.
        $vatRemoved = round($statutory / VatService::STATUTORY_RATE * VatService::rate() / 100, 2);

        // Gross is what the goods would have come to before any discount was
        // given. Net is what was charged. The difference is the discounts and
        // the VAT exemption, which is the identity the reading is checked against.
        $gross = round($net + $statutory + $regularDiscount + $vatRemoved, 2);

        // ---- what was voided on this shift --------------------------------
        // A void is not a sale, so it is out of every figure above -- but a
        // reading that simply omitted it would leave the gap in the invoice
        // numbers unexplained, which is the one thing a void exists to avoid.
        $voided = DB::table('sales')
            ->whereNull('deleted_at')
            ->where('till_session_id', $sessionId)
            ->where('statut', 'voided')
            ->get();

        $voidTotal = 0.0;
        $voidRefs = [];
        foreach ($voided as $v) {
            $voidTotal += (float) $v->GrandTotal;
            $voidRefs[] = ['ref' => $v->Ref, 'amount' => round((float) $v->GrandTotal, 2),
                'reason' => $v->void_reason];
        }

        // ---- the invoices this shift used --------------------------------
        // Voided invoices are part of the run of numbers this shift used, so
        // the range has to include them -- otherwise the first or last invoice
        // of a shift silently disappears when it happens to be a void.
        $refs = $sales->pluck('Ref')->merge($voided->pluck('Ref'))
            ->filter()->sort()->values();

        // ---- how it was paid ---------------------------------------------
        $payments = DB::table('payment_sales as ps')
            ->join('sales as s', 's.id', '=', 'ps.sale_id')
            ->whereNull('ps.deleted_at')
            ->where('s.till_session_id', $sessionId)
            ->groupBy('ps.Reglement')
            ->select('ps.Reglement as method',
                DB::raw('COUNT(*) as n'),
                DB::raw('SUM(ps.montant) as taken'))
            ->get();

        $byMethod = [];
        $cashTaken = 0.0;
        foreach ($payments as $p) {
            // montant is net of change already; see the header.
            $netTaken = round((float) $p->taken, 2);
            $byMethod[] = [
                'method' => $p->method,
                'count' => (int) $p->n,
                'amount' => $netTaken,
            ];
            if (self::isCash($p->method)) {
                $cashTaken += $netTaken;
            }
        }

        // ---- cash in and out ----------------------------------------------
        $moves = DB::table('till_movements')->whereNull('deleted_at')
            ->where('till_session_id', $sessionId)->orderBy('id')->get();

        $cashIn = 0.0;
        $cashOut = 0.0;
        foreach ($moves as $m) {
            if ($m->type === 'cash_in') {
                $cashIn += (float) $m->amount;
            } else {
                $cashOut += (float) $m->amount;
            }
        }

        // ---- refunds paid out of this drawer ------------------------------
        // Owner decision (DATATEST K46): a refund comes out of the drawer of
        // the person who gave it, and only cash does. Every return at the
        // warehouse used to be taken off, bank transfers and other people's
        // refunds included.
        $refundRows = DB::table('payment_sale_returns as pr')
            ->join('sale_returns as r', 'r.id', '=', 'pr.sale_return_id')
            ->whereNull('pr.deleted_at')->whereNull('r.deleted_at')
            ->where('r.warehouse_id', $s->warehouse_id)
            ->where('pr.user_id', $s->user_id)
            ->where('pr.created_at', '>=', $from)
            ->where('pr.created_at', '<=', $to)
            ->get(['pr.sale_return_id', 'pr.montant', 'pr.Reglement']);
        $cashRefunds = $refundRows->filter(function ($r) {
            return self::isCash($r->Reglement);
        });
        $returns = (object) ['n' => $cashRefunds->pluck('sale_return_id')->unique()->count()];

        $refunds = round((float) $cashRefunds->sum('montant'), 2);

        // ---- what should be in the drawer ---------------------------------
        $expected = round((float) $s->opening_float + $cashTaken + $cashIn - $cashOut - $refunds, 2);

        $counted = $s->counted_cash === null ? null : (float) $s->counted_cash;
        $variance = $counted === null ? null : round($counted - $expected, 2);

        return [
            'session' => [
                'id' => (int) $s->id,
                'warehouse_id' => (int) $s->warehouse_id,
                'terminal' => $s->terminal,
                'status' => $s->status,
                'opened_at' => $s->opened_at,
                'closed_at' => $s->closed_at,
                'opening_float' => round((float) $s->opening_float, 2),
                'z_counter' => $s->z_counter === null ? null : (int) $s->z_counter,
                'notes' => $s->notes,
            ],
            'sales' => [
                'count' => $count,
                'gross' => $gross,
                'net' => round($net, 2),
                'first_invoice' => $refs->first(),
                'last_invoice' => $refs->last(),
            ],
            'vat' => [
                'vatable_sales' => round($vatable, 2),
                'vat_amount' => round($vat, 2),
                'vat_exempt_sales' => round($exempt, 2),
                'zero_rated_sales' => round($zero, 2),
            ],
            'discounts' => [
                'statutory' => round($statutory, 2),
                'regular' => round($regularDiscount, 2),
                // "Less VAT on SC/PWD sales" (DATATEST K80).
                'vat_exemption' => $vatRemoved,
                'by_type' => $byStatutory,
            ],
            'payments' => $byMethod,
            'cash' => [
                'opening_float' => round((float) $s->opening_float, 2),
                'cash_sales' => round($cashTaken, 2),
                'cash_in' => round($cashIn, 2),
                'cash_out' => round($cashOut, 2),
                'refunds' => $refunds,
                'expected' => $expected,
                'counted' => $counted,
                'variance' => $variance,
            ],
            'voids' => [
                'count' => count($voidRefs),
                'amount' => round($voidTotal, 2),
                'invoices' => $voidRefs,
            ],
            'returns' => [
                'count' => (int) ($returns->n ?? 0),
                'amount' => $refunds,
                'basis' => 'Cash refunds given by this cashier at this warehouse while this session was open.',
            ],
            'movements' => $moves,
            'accumulated' => [
                'before' => $s->grand_total_before === null ? null : round((float) $s->grand_total_before, 2),
                'after' => $s->grand_total_after === null ? null : round((float) $s->grand_total_after, 2),
            ],
        ];
    }

    /**
     * Close the drawer against a count.
     *
     * The Z-counter and the accumulated grand total are allocated here, in the
     * same transaction and under a lock, because they are the two numbers that
     * must never repeat, skip or go backwards.
     */
    public static function close($sessionId, $countedCash, $notes = null)
    {
        return DB::transaction(function () use ($sessionId, $countedCash, $notes) {
            $s = DB::table('till_sessions')->where('id', $sessionId)->lockForUpdate()->first();
            if (! $s) {
                return ['error' => 'not_found'];
            }
            // Only someone who works in that warehouse closes its drawer (K14).
            WarehouseAccess::assertAllows($s->warehouse_id);
            if ($s->status !== 'open') {
                return ['error' => 'not_open'];
            }

            $reading = self::reading($sessionId);
            $expected = $reading['cash']['expected'];
            $counted = round((float) $countedCash, 4);

            // The next Z for this drawer, and the lifetime total it carries on.
            $prev = DB::table('till_sessions')
                ->where('warehouse_id', $s->warehouse_id)
                ->where('terminal', $s->terminal)
                ->whereNotNull('z_counter')
                ->orderByDesc('z_counter')
                ->first();

            $zCounter = $prev ? (int) $prev->z_counter + 1 : 1;
            $before = $prev ? (float) $prev->grand_total_after : 0.0;
            $after = round($before + $reading['sales']['net'], 4);

            DB::table('till_sessions')->where('id', $sessionId)->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => Auth::check() ? Auth::user()->id : null,
                'counted_cash' => $counted,
                'expected_cash' => $expected,
                'variance' => round($counted - $expected, 4),
                'z_counter' => $zCounter,
                'grand_total_before' => $before,
                'grand_total_after' => $after,
                'notes' => $notes !== null && $notes !== '' ? $notes : $s->notes,
                'updated_at' => now(),
            ]);

            // Re-read so the stored reading carries the closing figures, then
            // keep it verbatim: a re-print must show what was signed off.
            $final = self::reading($sessionId);
            DB::table('till_sessions')->where('id', $sessionId)
                ->update(['reading' => json_encode($final)]);

            // A drawer that did not balance is news to the managers.
            ActivityNotifier::tillClosed($sessionId);

            return ['reading' => $final];
        });
    }

    /**
     * A card payment is revenue but it never reaches the drawer. Anything not
     * recognised as cash is treated as not cash, which errs towards reporting a
     * shortage rather than hiding one.
     */
    private static function isCash($method)
    {
        return in_array(strtolower(trim((string) $method)), ['cash', 'espece', 'especes'], true);
    }
}
