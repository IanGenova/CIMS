<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Double-entry posting.
 *
 * Entries are append-only: a mistake is corrected by posting a reversal, never
 * by editing history. Every entry must balance to the cent before it is
 * written, so an unbalanced journal cannot exist even transiently.
 */
class LedgerService
{
    private static $accountCache = [];

    public static function forget()
    {
        self::$accountCache = [];
    }

    /** Resolve an account id from its slug, e.g. "sales_revenue". */
    public static function account($slug)
    {
        if (! array_key_exists($slug, self::$accountCache)) {
            self::$accountCache[$slug] = DB::table('accounts')
                ->whereNull('deleted_at')->where('slug', $slug)->value('id');
        }

        if (! self::$accountCache[$slug]) {
            throw ValidationException::withMessages([
                'account' => ['The "' . $slug . '" account is missing from the chart of accounts.'],
            ]);
        }

        return self::$accountCache[$slug];
    }

    /**
     * Write one balanced entry.
     *
     * @param  array  $lines  [['account' => slug|id, 'debit' => x, 'credit' => y, 'memo' => ...], ...]
     * @return int    the new entry id
     */
    public static function post(array $lines, $date, $memo = null, $sourceType = null, $sourceId = null, $reverses = null)
    {
        $prepared = [];
        $debits = 0.0;
        $credits = 0.0;

        foreach ($lines as $l) {
            $debit = round((float) (isset($l['debit']) ? $l['debit'] : 0), 4);
            $credit = round((float) (isset($l['credit']) ? $l['credit'] : 0), 4);

            // A line is one side or the other, never both.
            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages([
                    'journal' => ['A journal line cannot carry both a debit and a credit.'],
                ]);
            }
            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }

            $accountId = is_numeric($l['account']) ? (int) $l['account'] : self::account($l['account']);

            $prepared[] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => isset($l['memo']) ? $l['memo'] : null,
            ];
            $debits += $debit;
            $credits += $credit;
        }

        if (empty($prepared)) {
            throw ValidationException::withMessages([
                'journal' => ['An entry needs at least one line with a value.'],
            ]);
        }

        if (round($debits - $credits, 2) !== 0.0) {
            throw ValidationException::withMessages([
                'journal' => [
                    'Entry does not balance: debits ' . number_format($debits, 2)
                    . ' against credits ' . number_format($credits, 2) . '.',
                ],
            ]);
        }

        return DB::transaction(function () use ($prepared, $date, $memo, $sourceType, $sourceId, $reverses) {
            $entryId = DB::table('journal_entries')->insertGetId([
                'Ref' => self::nextRef(),
                'date' => $date,
                'memo' => $memo,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reverses_entry_id' => $reverses,
                'user_id' => auth()->check() ? auth()->id() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($prepared as $p) {
                $p['journal_entry_id'] = $entryId;
                $p['created_at'] = now();
                $p['updated_at'] = now();
                DB::table('journal_lines')->insert($p);
            }

            return $entryId;
        });
    }

    /**
     * Reverse an entry by posting its mirror image. The original is untouched,
     * so the audit trail shows both what was done and what undid it.
     */
    public static function reverse($entryId, $date = null, $memo = null)
    {
        $entry = DB::table('journal_entries')->where('id', $entryId)->first();
        if (! $entry) {
            throw ValidationException::withMessages(['journal' => ['That entry does not exist.']]);
        }

        $already = DB::table('journal_entries')->where('reverses_entry_id', $entryId)->exists();
        if ($already) {
            throw ValidationException::withMessages([
                'journal' => ['That entry has already been reversed.'],
            ]);
        }

        $lines = DB::table('journal_lines')->where('journal_entry_id', $entryId)->get();
        $mirror = [];
        foreach ($lines as $l) {
            $mirror[] = [
                'account' => $l->account_id,
                'debit' => (float) $l->credit,
                'credit' => (float) $l->debit,
                'memo' => $l->memo,
            ];
        }

        return self::post(
            $mirror,
            $date ?: date('Y-m-d'),
            $memo ?: ('Reversal of ' . $entry->Ref),
            $entry->source_type,
            $entry->source_id,
            $entryId
        );
    }

    // ---------------- automatic postings ----------------

    /**
     * A completed sale: the customer owes us, revenue is earned, tax is a
     * liability, and the goods leave inventory at cost.
     */
    public static function postSale($saleId)
    {
        $sale = DB::table('sales')->where('id', $saleId)->first();
        if (! $sale || $sale->statut !== 'completed') {
            return null;
        }

        // A sale makes two entries, and each is guarded separately below. This
        // used to return here the moment the revenue entry existed, which made
        // the cost guard further down unreachable: a sale whose cost failed to
        // post the first time -- because the cost was not known yet, or the
        // write threw -- could never have it posted afterwards. The sweep would
        // find the revenue entry, conclude the sale was done, and move on,
        // leaving inventory permanently overstated by that sale's cost.
        $alreadyRevenue = self::alreadyPosted('Sale', $saleId);

        // In pesos, whatever currency the sale was in (DATATEST K35).
        $f = self::toPesos($sale);
        $total = round((float) $sale->GrandTotal * $f, 4);
        // Output VAT (and any order tax) is owed to the BIR, not earned
        // (DATATEST K34): it was credited to revenue.
        $tax = round(((float) $sale->vat_amount + (float) $sale->TaxNet) * $f, 4);
        $shipping = round((float) $sale->shipping * $f, 4);
        $revenue = round($total - $tax - $shipping, 4);

        $entry = null;
        if (! $alreadyRevenue) {
            $entry = DB::transaction(function () use ($sale, $saleId, $f, $total, $tax, $shipping, $revenue) {
                // The cash taken so far goes in with the sale; each payment is
                // then marked, so the payment run does not book it again.
                $payments = self::unbookedPayments('payment_sales', 'sale_id', $saleId);
                $paid = round($payments->sum('montant') * $f, 4);
                $owed = round($total - $paid, 4);

                $lines = [];
                if ($paid > 0) {
                    $lines[] = ['account' => 'cash', 'debit' => $paid, 'memo' => 'Payment on ' . $sale->Ref];
                }
                if ($owed != 0.0) {
                    $lines[] = $owed > 0
                        ? ['account' => 'accounts_receivable', 'debit' => $owed, 'memo' => 'Due on ' . $sale->Ref]
                        : ['account' => 'accounts_receivable', 'credit' => -$owed, 'memo' => 'Overpaid on ' . $sale->Ref];
                }
                if ($revenue != 0.0) {
                    $lines[] = ['account' => 'sales_revenue', 'credit' => $revenue];
                }
                if ($tax > 0) {
                    $lines[] = ['account' => 'tax_payable', 'credit' => $tax];
                }
                if ($shipping > 0) {
                    $lines[] = ['account' => 'shipping_income', 'credit' => $shipping];
                }

                $id = self::post($lines, $sale->date, 'Sale ' . $sale->Ref, 'Sale', $saleId);
                self::markBooked('payment_sales', $payments, $id);

                return $id;
            });
        }

        // Second entry: inventory leaves at cost. Guarded on its own source type --
        // the guard above only covers the revenue entry, so if that one is removed for
        // a correction and the sale re-posted, the cost would otherwise be booked twice.
        $cost = self::saleCost($saleId);
        if ($cost > 0 && ! self::alreadyPosted('SaleCost', $saleId)) {
            self::post([
                ['account' => 'cogs', 'debit' => $cost],
                ['account' => 'inventory', 'credit' => $cost],
            ], $sale->date, 'Cost of sale ' . $sale->Ref, 'SaleCost', $saleId);
        }

        return $entry;
    }

    /** A purchase adds to inventory and creates a payable. */
    public static function postPurchase($purchaseId)
    {
        if (self::alreadyPosted('Purchase', $purchaseId)) {
            return null;
        }

        $p = DB::table('purchases')->where('id', $purchaseId)->first();
        if (! $p || $p->statut !== 'received') {
            return null;
        }

        $f = self::toPesos($p);
        $total = round((float) $p->GrandTotal * $f, 4);
        $tax = round((float) $p->TaxNet * $f, 4);
        $goods = round($total - $tax, 4);

        return DB::transaction(function () use ($p, $purchaseId, $f, $total, $tax, $goods) {
            $payments = self::unbookedPayments('payment_purchases', 'purchase_id', $purchaseId);
            $paid = round($payments->sum('montant') * $f, 4);
            $owed = round($total - $paid, 4);

            $lines = [];
            if ($goods != 0.0) {
                $lines[] = ['account' => 'inventory', 'debit' => $goods];
            }
            if ($tax > 0) {
                $lines[] = ['account' => 'tax_payable', 'debit' => $tax];
            }
            if ($paid > 0) {
                $lines[] = ['account' => 'cash', 'credit' => $paid];
            }
            if ($owed != 0.0) {
                $lines[] = $owed > 0
                    ? ['account' => 'accounts_payable', 'credit' => $owed]
                    : ['account' => 'accounts_payable', 'debit' => -$owed];
            }

            $id = self::post($lines, $p->date, 'Purchase ' . $p->Ref, 'Purchase', $purchaseId);
            self::markBooked('payment_purchases', $payments, $id);

            return $id;
        });
    }

    /** An expense is money out. */
    public static function postExpense($expenseId)
    {
        if (self::alreadyPosted('Expense', $expenseId)) {
            return null;
        }

        $e = DB::table('expenses')->where('id', $expenseId)->whereNull('deleted_at')->first();
        if (! $e) {
            return null;
        }

        $amount = (float) $e->amount;
        if ($amount <= 0) {
            return null;
        }

        return self::post([
            ['account' => 'operating_expenses', 'debit' => $amount, 'memo' => $e->details],
            ['account' => 'cash', 'credit' => $amount],
        ], $e->date, 'Expense ' . $e->Ref, 'Expense', $expenseId);
    }

    // ---------------- returns, payments, deletes (DATATEST K27) ----------------
    //
    // Only sales, purchases and expenses were ever posted, once, with whatever
    // had been paid by then. A later payment, a refund, a return, an edit to a
    // payment and a deleted document never reached the journal, so its cash and
    // receivables drifted from the till and the statements.

    /** What each kind of payment is, and how it moves money. */
    const PAYMENTS = [
        'payment_sale' => ['table' => 'payment_sales', 'fk' => 'sale_id', 'parent' => 'sales',
            'parent_source' => 'Sale', 'source' => 'PaymentSale', 'debit' => 'cash', 'credit' => 'accounts_receivable'],
        'payment_purchase' => ['table' => 'payment_purchases', 'fk' => 'purchase_id', 'parent' => 'purchases',
            'parent_source' => 'Purchase', 'source' => 'PaymentPurchase', 'debit' => 'accounts_payable', 'credit' => 'cash'],
        // A refund to a customer: the drawer pays out what the return owed them.
        'payment_sale_return' => ['table' => 'payment_sale_returns', 'fk' => 'sale_return_id', 'parent' => 'sale_returns',
            'parent_source' => 'SaleReturn', 'source' => 'PaymentSaleReturn', 'debit' => 'accounts_receivable', 'credit' => 'cash'],
        // A supplier refunding goods sent back.
        'payment_purchase_return' => ['table' => 'payment_purchase_returns', 'fk' => 'purchase_return_id', 'parent' => 'purchase_returns',
            'parent_source' => 'PurchaseReturn', 'source' => 'PaymentPurchaseReturn', 'debit' => 'cash', 'credit' => 'accounts_payable'],
    ];

    /** The journal sources that belong to a document, for reversing it. */
    const DOCUMENT_SOURCES = [
        'sale' => [['Sale', 'SaleCost'], 'payment_sale'],
        'purchase' => [['Purchase'], 'payment_purchase'],
        'sale_return' => [['SaleReturn', 'SaleReturnCost'], 'payment_sale_return'],
        'purchase_return' => [['PurchaseReturn'], 'payment_purchase_return'],
    ];

    /**
     * A customer return: the revenue and its VAT come back off, the customer is
     * owed the price they paid (or has been refunded it), and the goods go back
     * on the shelf at cost.
     */
    public static function postSaleReturn($returnId)
    {
        if (self::alreadyPosted('SaleReturn', $returnId)) {
            return null;
        }
        $r = DB::table('sale_returns')->where('id', $returnId)->whereNull('deleted_at')->first();
        if (! $r || $r->statut !== 'received') {
            return null;
        }

        // The sale it came from sets the VAT share and the currency (K5: the
        // refund is at the price paid).
        $sale = $r->sale_id ? DB::table('sales')->where('id', $r->sale_id)->first() : null;
        $f = $sale ? self::toPesos($sale) : self::toPesos($r);
        $total = round((float) $r->GrandTotal * $f, 4);
        $vatShare = ($sale && (float) $sale->GrandTotal > 0)
            ? ((float) $sale->vat_amount + (float) $sale->TaxNet) / (float) $sale->GrandTotal : 0.0;
        $tax = round($total * $vatShare, 4);

        $entry = DB::transaction(function () use ($r, $returnId, $f, $total, $tax) {
            $refunds = self::unbookedPayments('payment_sale_returns', 'sale_return_id', $returnId);
            $refunded = round($refunds->sum('montant') * $f, 4);
            $owed = round($total - $refunded, 4);

            $lines = [['account' => 'sales_returns', 'debit' => round($total - $tax, 4)]];
            if ($tax > 0) {
                $lines[] = ['account' => 'tax_payable', 'debit' => $tax];
            }
            if ($refunded > 0) {
                $lines[] = ['account' => 'cash', 'credit' => $refunded, 'memo' => 'Refund on ' . $r->Ref];
            }
            if ($owed != 0.0) {
                $lines[] = $owed > 0
                    ? ['account' => 'accounts_receivable', 'credit' => $owed, 'memo' => 'Owed back on ' . $r->Ref]
                    : ['account' => 'accounts_receivable', 'debit' => -$owed];
            }

            $id = self::post($lines, $r->date, 'Return ' . $r->Ref, 'SaleReturn', $returnId);
            self::markBooked('payment_sale_returns', $refunds, $id);

            return $id;
        });

        $cost = (float) DB::table('sale_return_details as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'd.sale_unit_id')
            ->where('d.sale_return_id', $returnId)
            ->sum(DB::raw("d.quantity * (CASE WHEN u.id IS NULL THEN 1 WHEN u.operator = '/' THEN 1 / u.operator_value ELSE u.operator_value END) * p.cost"));
        if (round($cost, 4) > 0 && ! self::alreadyPosted('SaleReturnCost', $returnId)) {
            self::post([
                ['account' => 'inventory', 'debit' => round($cost, 4)],
                ['account' => 'cogs', 'credit' => round($cost, 4)],
            ], $r->date, 'Cost back on return ' . $r->Ref, 'SaleReturnCost', $returnId);
        }

        return $entry;
    }

    /** Goods sent back to a supplier: they leave inventory, and the supplier owes us. */
    public static function postPurchaseReturn($returnId)
    {
        if (self::alreadyPosted('PurchaseReturn', $returnId)) {
            return null;
        }
        $r = DB::table('purchase_returns')->where('id', $returnId)->whereNull('deleted_at')->first();
        if (! $r || $r->statut !== 'completed') {
            return null;
        }
        $f = self::toPesos($r);
        $total = round((float) $r->GrandTotal * $f, 4);
        if ($total <= 0) {
            return null;
        }

        return DB::transaction(function () use ($r, $returnId, $f, $total) {
            $refunds = self::unbookedPayments('payment_purchase_returns', 'purchase_return_id', $returnId);
            $refunded = round($refunds->sum('montant') * $f, 4);
            $owed = round($total - $refunded, 4);

            $lines = [['account' => 'inventory', 'credit' => $total]];
            if ($refunded > 0) {
                $lines[] = ['account' => 'cash', 'debit' => $refunded, 'memo' => 'Refund on ' . $r->Ref];
            }
            if ($owed != 0.0) {
                $lines[] = $owed > 0
                    ? ['account' => 'accounts_payable', 'debit' => $owed]
                    : ['account' => 'accounts_payable', 'credit' => -$owed];
            }

            $id = self::post($lines, $r->date, 'Supplier return ' . $r->Ref, 'PurchaseReturn', $returnId);
            self::markBooked('payment_purchase_returns', $refunds, $id);

            return $id;
        });
    }

    /**
     * A payment taken after its document was posted. One taken before is
     * already inside the document's entry (journal_entry_id says which).
     */
    public static function postPayment($kind, $paymentId)
    {
        $spec = self::PAYMENTS[$kind];

        return DB::transaction(function () use ($spec, $paymentId) {
            $p = DB::table($spec['table'])->where('id', $paymentId)->lockForUpdate()->first();
            if (! $p || $p->deleted_at !== null || $p->journal_entry_id !== null || (float) $p->montant <= 0) {
                return null;
            }
            // Not before its document: posting the document books it.
            if (! self::liveEntry($spec['parent_source'], $p->{$spec['fk']})) {
                return null;
            }
            $parent = DB::table($spec['parent'])->where('id', $p->{$spec['fk']})->first();
            $amount = round((float) $p->montant * self::toPesos($parent), 4);

            $id = self::post([
                ['account' => $spec['debit'], 'debit' => $amount],
                ['account' => $spec['credit'], 'credit' => $amount],
            ], $p->date ?: date('Y-m-d'), 'Payment ' . $p->Ref, $spec['source'], $paymentId);
            DB::table($spec['table'])->where('id', $paymentId)->update(['journal_entry_id' => $id]);

            return $id;
        });
    }

    /**
     * A booked payment was edited or deleted: take back what was booked (the
     * amount it had then), and leave it unbooked so its new amount, if any,
     * is posted by the next run.
     */
    public static function unbookPayment($kind, $paymentId, $bookedAmount)
    {
        $spec = self::PAYMENTS[$kind];
        $p = DB::table($spec['table'])->where('id', $paymentId)->first();
        if (! $p || ! $p->journal_entry_id) {
            return null;
        }

        $entryId = null;
        // If the entry it sat in was already reversed (the document was voided
        // or deleted), there is nothing left to take back.
        if (! self::isReversed($p->journal_entry_id) && (float) $bookedAmount > 0) {
            $parent = DB::table($spec['parent'])->where('id', $p->{$spec['fk']})->first();
            $amount = round((float) $bookedAmount * self::toPesos($parent), 4);
            $entryId = self::post([
                ['account' => $spec['credit'], 'debit' => $amount],
                ['account' => $spec['debit'], 'credit' => $amount],
            ], date('Y-m-d'), 'Payment ' . $p->Ref . ' changed or removed', $spec['source'], $paymentId);
        }
        DB::table($spec['table'])->where('id', $paymentId)->update(['journal_entry_id' => null]);

        return $entryId;
    }

    /**
     * A document was deleted: reverse everything it put in the journal -- its
     * own entries and its payments' -- so cash and revenue go back to where
     * they were. Already-reversed entries (a void) are left alone.
     */
    public static function reverseDocument($kind, $id, $memo = null)
    {
        [$sources, $paymentKind] = self::DOCUMENT_SOURCES[$kind];
        $spec = self::PAYMENTS[$paymentKind];
        $paymentIds = DB::table($spec['table'])->where($spec['fk'], $id)->pluck('id')->all();

        $entries = DB::table('journal_entries as e')
            ->whereNull('e.reverses_entry_id')
            ->where(function ($q) use ($sources, $id, $spec, $paymentIds) {
                $q->where(function ($d) use ($sources, $id) {
                    $d->whereIn('e.source_type', $sources)->where('e.source_id', $id);
                });
                if ($paymentIds) {
                    $q->orWhere(function ($pq) use ($spec, $paymentIds) {
                        $pq->where('e.source_type', $spec['source'])->whereIn('e.source_id', $paymentIds);
                    });
                }
            })
            ->whereNotExists(function ($r) {
                $r->select(DB::raw(1))->from('journal_entries as r')->whereColumn('r.reverses_entry_id', 'e.id');
            })
            ->orderBy('e.id')
            ->pluck('e.id');

        foreach ($entries as $entryId) {
            self::reverse($entryId, date('Y-m-d'), $memo);
        }

        return $entries->count();
    }

    /** Amount in pesos per unit of the document's currency (1 for pesos). */
    private static function toPesos($doc)
    {
        if (! $doc || ! isset($doc->base_amount) || $doc->base_amount === null) {
            return 1.0;
        }
        $total = (float) $doc->GrandTotal;

        return $total > 0 ? (float) $doc->base_amount / $total : 1.0;
    }

    private static function unbookedPayments($table, $fk, $docId)
    {
        return DB::table($table)->where($fk, $docId)->whereNull('deleted_at')
            ->whereNull('journal_entry_id')->lockForUpdate()->get(['id', 'montant']);
    }

    private static function markBooked($table, $payments, $entryId)
    {
        if ($payments->isNotEmpty()) {
            DB::table($table)->whereIn('id', $payments->pluck('id'))->update(['journal_entry_id' => $entryId]);
        }
    }

    private static function isReversed($entryId)
    {
        return DB::table('journal_entries')->where('reverses_entry_id', $entryId)->exists();
    }

    /** The document's own entry exists and has not been reversed. */
    private static function liveEntry($sourceType, $sourceId)
    {
        return DB::table('journal_entries as e')
            ->where('e.source_type', $sourceType)->where('e.source_id', $sourceId)
            ->whereNull('e.reverses_entry_id')
            ->whereNotExists(function ($r) {
                $r->select(DB::raw(1))->from('journal_entries as r')->whereColumn('r.reverses_entry_id', 'e.id');
            })
            ->exists();
    }

    // ---------------- reporting ----------------

    /** Trial balance: every account with a movement, and the two column totals. */
    public static function trialBalance($from = null, $to = null)
    {
        $q = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->when($from, function ($q) use ($from) {
                return $q->where('je.date', '>=', $from);
            })
            ->when($to, function ($q) use ($to) {
                return $q->where('je.date', '<=', $to);
            })
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')
            ->orderBy('a.code')
            ->select(
                'a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance',
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit')
            );

        $rows = [];
        $sumDebit = 0.0;
        $sumCredit = 0.0;

        foreach ($q->get() as $r) {
            $debit = round((float) $r->total_debit, 2);
            $credit = round((float) $r->total_credit, 2);

            // Show the net on the side the account normally sits.
            $net = $r->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;

            $rows[] = [
                'account_id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'normal_balance' => $r->normal_balance,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => round($net, 2),
            ];
            $sumDebit += $debit;
            $sumCredit += $credit;
        }

        return [
            'rows' => $rows,
            'total_debit' => round($sumDebit, 2),
            'total_credit' => round($sumCredit, 2),
            // If this is ever false the journal has been tampered with directly.
            'balanced' => round($sumDebit - $sumCredit, 2) === 0.0,
        ];
    }

    // ---------------------------------------------------------------

    private static function alreadyPosted($sourceType, $sourceId)
    {
        return DB::table('journal_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * What the sold goods actually cost.
     *
     * Taken from the stock movements the sale wrote, because those recorded the
     * average at the moment of sale. Reading products.cost instead would restate
     * a past sale every time a later purchase moved the average -- so last
     * month's margin would change because of something bought this month.
     *
     * Falls back to the product cost for sales made before movements were
     * tracked, which is the best figure available for them.
     */
    private static function saleCost($saleId)
    {
        $recorded = DB::table('stock_movements')
            ->where('reference_type', 'Sale')
            ->where('reference_id', $saleId)
            ->where('quantity', '<', 0)
            ->sum('total_cost');

        if ((float) $recorded > 0) {
            return round((float) $recorded, 4);
        }

        // In base units: a box of ten bags is ten bags' cost (DATATEST K18).
        $rows = DB::table('sale_details as sd')
            ->join('products as p', 'p.id', '=', 'sd.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'sd.sale_unit_id')
            ->where('sd.sale_id', $saleId)
            ->select('sd.quantity', 'p.cost', 'u.operator', 'u.operator_value')->get();

        $total = 0.0;
        foreach ($rows as $r) {
            $factor = $r->operator_value ? ($r->operator === '/' ? 1 / (float) $r->operator_value : (float) $r->operator_value) : 1;
            $total += (float) $r->quantity * $factor * (float) $r->cost;
        }

        return round($total, 4);
    }

    private static function nextRef()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('journal_entries', 'JE-', 6, 1);
    }
}
