<?php

namespace App\utils;

use App\Models\product_warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Voiding a sale.
 *
 * WHY NOT JUST DELETE IT
 *
 * A deleted sale is a hole. The invoice number it used is gone from the record
 * and nothing explains the gap, which for a registered till is exactly backwards
 * -- the gap is the thing that has to be accounted for. So the sale stays, with
 * the reason it was voided and the person who authorised it, and every effect it
 * had is undone.
 *
 * WHAT HAS TO BE UNDONE
 *
 *   the stock it took      -- back on the shelf, into the same lots it came from
 *   the money it recorded  -- the payments come off, so the drawer stops
 *                             expecting cash that was handed straight back
 *   the books              -- both journal entries reversed, not erased
 *
 * HOW THE STOCK GOES BACK
 *
 * By replaying the sale's own movements inverted, rather than recomputing from
 * the sale lines. The movements are already in base units, so unit conversions
 * that were applied on the way out are undone exactly rather than recalculated
 * -- and a recalculation is where a rounding difference would creep in and leave
 * the shelf permanently half a unit out.
 *
 * Batch-tracked lines are the exception: they go back through BatchService,
 * which returns them to the lots they were picked from and then re-derives the
 * warehouse total. Replaying their movements as well would put the stock back
 * twice, so they are skipped here.
 *
 * WHEN
 *
 * A void is a correction made at the till, not a change of mind next week. While
 * the shift that took the sale is still open it can be voided; once that drawer
 * has been counted down, the reading it was counted against is signed off and
 * the way back is a return. A sale rung up outside any session gets the same
 * day it was made.
 */
class VoidService
{
    public static function canVoid($sale)
    {
        // A sale deleted while this void waited for it has already put its
        // stock back; voiding it would do so twice (DATATEST K78).
        if (! $sale || $sale->deleted_at !== null) {
            return 'not_found';
        }
        if ($sale->statut === 'voided' || $sale->voided_at !== null) {
            return 'already_voided';
        }
        if ($sale->statut !== 'completed') {
            return 'not_completed';
        }
        if (XenditCheckout::saleHoldsXenditMoney($sale->id)) {
            return 'xendit_paid';
        }
        // Goods already came back on it: a void would restock them twice (DATATEST K5).
        if (DB::table('sale_returns')->where('sale_id', $sale->id)->whereNull('deleted_at')->exists()) {
            return 'has_returns';
        }

        if ($sale->till_session_id) {
            $status = DB::table('till_sessions')->where('id', $sale->till_session_id)->value('status');
            if ($status !== 'open') {
                return 'shift_closed';
            }

            return true;
        }

        // No session behind it, so the day it was made is the boundary.
        if (substr((string) $sale->created_at, 0, 10) !== date('Y-m-d')) {
            return 'not_today';
        }

        return true;
    }

    /** Why a void was refused, in words a cashier can act on. */
    public static function refusal($code)
    {
        $messages = [
            'not_found' => 'That sale does not exist.',
            'already_voided' => 'That sale has already been voided.',
            'not_completed' => 'Only a completed sale can be voided.',
            'shift_closed' => 'That shift has already been counted down. Use a sale return instead.',
            'has_returns' => 'Goods have already come back on this sale. Delete that return first, or return the rest instead of voiding.',
            'not_today' => 'That sale was not made today. Use a sale return instead.',
            'xendit_paid' => 'Refund the Xendit payment before voiding this sale.',
        ];

        return $messages[$code] ?? 'That sale cannot be voided.';
    }

    /**
     * Void it: stock back, payments off, books reversed, the record kept.
     */
    public static function void($saleId, $reason)
    {
        return DB::transaction(function () use ($saleId, $reason) {
            $sale = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first();

            $can = self::canVoid($sale);
            if ($can !== true) {
                return ['error' => $can];
            }

            StockLedger::context('void', 'Sale', $saleId, ['notes' => 'Void: ' . $reason]);

            // 1. batch-tracked lines, back into their own lots
            $batchReversed = BatchService::reverse('Sale', $saleId);
            // ...and serial-tracked units, back on the shelf they left.
            SerialService::releaseSale($saleId, 'voided');

            // 2. everything else, by inverting the movements this sale made.
            //
            // Everything except a previous reversal, rather than a list of the
            // types that count. That list was ['pos', 'sale'], and the moment
            // marketplace orders started writing 'marketplace' it silently gave
            // nothing back -- the sale was voided, the stock was not returned,
            // and nothing said so. Naming what to exclude cannot fail that way
            // when another way of selling appears.
            $movements = DB::table('stock_movements')
                ->where('reference_type', 'Sale')
                ->where('reference_id', $saleId)
                ->whereNotIn('movement_type', ['void'])
                ->get();

            $restored = 0;
            foreach ($movements as $m) {
                if (BatchService::isTracked($m->product_id)) {
                    continue;                       // already back, via its lots
                }

                $stock = product_warehouse::where('deleted_at', '=', null)
                    ->where('warehouse_id', $m->warehouse_id)
                    ->where('product_id', $m->product_id)
                    ->when($m->product_variant_id !== null, function ($q) use ($m) {
                        return $q->where('product_variant_id', $m->product_variant_id);
                    }, function ($q) {
                        return $q->whereNull('product_variant_id');
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    continue;
                }

                $stock->qte = (float) $stock->qte - (float) $m->quantity;   // quantity is negative
                $stock->save();
                $restored++;
            }

            StockLedger::clear();

            // 3. the money. The payments are removed rather than marked, because
            //    the till reading sums payment_sales to work out what should be
            //    in the drawer -- and the cash was handed straight back.
            $payments = DB::table('payment_sales')->where('sale_id', $saleId)->count();
            DB::table('payment_sales')->where('sale_id', $saleId)->delete();

            // 4. the books, reversed rather than erased
            $reversed = 0;
            $entries = DB::table('journal_entries')
                ->whereIn('source_type', ['Sale', 'SaleCost'])
                ->where('source_id', $saleId)
                ->get();
            foreach ($entries as $entry) {
                $mirrored = DB::table('journal_entries')
                    ->where('reverses_entry_id', $entry->id)->exists();
                if ($mirrored) {
                    continue;
                }
                LedgerService::reverse($entry->id, null, 'Void of ' . $sale->Ref);
                $reversed++;
            }

            // 5. the record itself
            DB::table('sales')->where('id', $saleId)->update([
                'statut' => 'voided',
                'voided_at' => now(),
                'voided_by' => Auth::check() ? Auth::user()->id : null,
                'void_reason' => $reason,
                'paid_amount' => 0,
                'payment_statut' => 'unpaid',
                'updated_at' => now(),
            ]);

            // Keep the customer promise linked to the cancelled sale for audit.
            // Its reservation was consumed when sold; a void restores physical
            // stock but must not silently reopen the old promise.
            $affectedOrders = DB::table('customer_orders')->where('sale_id', $saleId)->pluck('id');
            DB::table('customer_orders')->where('sale_id', $saleId)->update([
                'status' => 'sale_voided', 'updated_at' => now(),
            ]);
            foreach ($affectedOrders as $orderId) {
                CustomerOrderService::notifyStatus($orderId);
            }

            // Written straight to the table, so the observer never saw it (K60).
            \App\Observers\AuditObserver::record(\App\Models\Sale::class, $saleId, 'voided',
                ['statut' => $sale->statut, 'paid_amount' => $sale->paid_amount],
                ['statut' => 'voided', 'void_reason' => $reason]);

            // The managers hear about every void, with its reason.
            ActivityNotifier::saleVoided($saleId);

            return [
                'sale' => DB::table('sales')->where('id', $saleId)->first(),
                'stock_lines_restored' => $restored,
                'batch_movements_reversed' => $batchReversed,
                'payments_removed' => $payments,
                'journal_entries_reversed' => $reversed,
            ];
        // Retried on a deadlock, like every other stock-moving transaction:
        // a single attempt surfaced one as a raw 500 (DATATEST K78).
        }, 5);
    }
}
