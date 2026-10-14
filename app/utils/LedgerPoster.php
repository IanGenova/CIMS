<?php

namespace App\utils;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Getting documents into the ledger without anyone asking.
 *
 * TWO ROUTES, ON PURPOSE
 *
 * 1. After the response. The moment a sale, purchase or expense is saved, it is
 *    queued to post once the reply has already gone back to the browser. The
 *    books are current within a moment of the till, and the posting cannot slow
 *    the sale down or -- more importantly -- fail it. A ledger that refuses to
 *    balance must never be the reason a customer cannot pay.
 *
 * 2. A sweep, on the schedule. It finds anything with no journal entry against
 *    it and posts it. This is the safety net: a document created from the
 *    command line, one whose first attempt threw, one whose status changed
 *    later, or anything written while automatic posting was switched off.
 *
 * Neither route can double-post, because the posting itself refuses to run
 * twice against the same document. The two are the same operation reached two
 * ways, not two mechanisms that have to agree.
 *
 * WHY THE SWEEP IS A JOIN AND NOT A LOOP
 *
 * The manual version walked every completed sale ever recorded and asked the
 * ledger about each one, which is two queries per document per run. On a
 * schedule that would grow without bound and spend nearly all of it confirming
 * that documents posted months ago are still posted. This asks the database for
 * the documents that have no entry, so a quiet sweep costs three queries
 * whatever the size of the history.
 */
class LedgerPoster
{
    /** Documents queued to post once this request has answered. */
    private static $queued = [];

    /** Registered the terminating callback yet? */
    private static $hooked = false;

    /** The document types this knows how to post. */
    const KINDS = [
        'sale' => ['table' => 'sales', 'source' => 'Sale', 'method' => 'postSale'],
        'purchase' => ['table' => 'purchases', 'source' => 'Purchase', 'method' => 'postPurchase'],
        'expense' => ['table' => 'expenses', 'source' => 'Expense', 'method' => 'postExpense'],
        // Returns, and the payments made after their document was posted
        // (DATATEST K27). Payments come last: each needs its document first.
        'sale_return' => ['table' => 'sale_returns', 'source' => 'SaleReturn', 'method' => 'postSaleReturn'],
        'purchase_return' => ['table' => 'purchase_returns', 'source' => 'PurchaseReturn', 'method' => 'postPurchaseReturn'],
        'payment_sale' => ['payment' => true],
        'payment_purchase' => ['payment' => true],
        'payment_sale_return' => ['payment' => true],
        'payment_purchase_return' => ['payment' => true],
    ];

    /** Post one document or payment of the given kind. */
    private static function run($kind, $id)
    {
        if (! empty(self::KINDS[$kind]['payment'])) {
            return LedgerService::postPayment($kind, $id);
        }
        $method = self::KINDS[$kind]['method'];

        return LedgerService::$method($id);
    }

    public static function isEnabled()
    {
        return (bool) DB::table('settings')->orderBy('id')->value('auto_post_ledger');
    }

    public static function lastPostedAt()
    {
        return DB::table('settings')->orderBy('id')->value('ledger_posted_at');
    }

    /**
     * Post this document once the response has gone out.
     *
     * Deliberately not inside the caller's transaction: a document is only worth
     * posting once it is actually committed, and a posting failure should leave
     * the sale standing rather than take it down.
     */
    public static function postSoon($kind, $id)
    {
        if (! isset(self::KINDS[$kind]) || ! $id) {
            return;
        }

        $key = $kind . ':' . $id;
        if (isset(self::$queued[$key])) {
            return;                                  // saved twice in one request
        }
        self::$queued[$key] = [$kind, (int) $id];

        if (self::$hooked) {
            return;
        }
        self::$hooked = true;

        App::terminating(function () {
            self::flush();
        });
    }

    /**
     * Post everything this request queued. Runs after the response, so a failure
     * here is logged and swept up later rather than shown to whoever was at the
     * till.
     */
    public static function flush()
    {
        $queued = self::$queued;
        self::$queued = [];

        if (! $queued || ! self::isEnabled()) {
            return 0;
        }

        $posted = 0;
        foreach ($queued as [$kind, $id]) {
            try {
                if (self::run($kind, $id)) {
                    $posted++;
                }
            } catch (\Throwable $e) {
                // The sweep will find it again; there is nobody to tell now.
                Log::warning('Ledger posting failed for ' . $kind . ' ' . $id . ': ' . $e->getMessage());
            }
        }

        if ($posted > 0) {
            self::markRun();
        }

        return $posted;
    }

    /**
     * Documents with nothing in the journal against them.
     *
     * A voided sale is excluded by its status, and one that was posted and then
     * voided already has entries, so neither comes back here.
     */
    public static function pendingIds($kind, $limit = 500)
    {
        $spec = self::KINDS[$kind] ?? null;
        if (! $spec) {
            return collect();
        }

        // A payment is waiting when it is booked nowhere and its document is
        // in the journal (and not reversed); before that, posting the
        // document books it.
        if (! empty($spec['payment'])) {
            $p = LedgerService::PAYMENTS[$kind];

            return DB::table($p['table'] . ' as d')
                ->whereNull('d.deleted_at')
                ->whereNull('d.journal_entry_id')
                ->where('d.montant', '>', 0)
                ->whereExists(function ($e) use ($p) {
                    $e->select(DB::raw(1))->from('journal_entries as je')
                        ->where('je.source_type', $p['parent_source'])
                        ->whereColumn('je.source_id', 'd.' . $p['fk'])
                        ->whereNull('je.reverses_entry_id')
                        ->whereNotExists(function ($r) {
                            $r->select(DB::raw(1))->from('journal_entries as rv')->whereColumn('rv.reverses_entry_id', 'je.id');
                        });
                })
                ->orderBy('d.id')->limit($limit)->pluck('d.id');
        }

        $q = DB::table($spec['table'] . ' as d')
            ->leftJoin('journal_entries as je', function ($join) use ($spec) {
                $join->on('je.source_id', '=', 'd.id')
                    ->where('je.source_type', '=', $spec['source']);
            })
            ->whereNull('d.deleted_at')
            ->whereNull('je.id');

        if ($kind === 'sale') {
            $q->where('d.statut', 'completed');
        } elseif ($kind === 'purchase') {
            $q->where('d.statut', 'received');
        } elseif ($kind === 'sale_return') {
            $q->where('d.statut', 'received');
        } elseif ($kind === 'purchase_return') {
            $q->where('d.statut', 'completed');
        }

        return $q->orderBy('d.id')->limit($limit)->pluck('d.id');
    }

    /** How much is waiting, for the screen that shows it. */
    public static function pendingCount()
    {
        $counts = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $counts[$kind] = self::pendingIds($kind, 100000)->count();
        }
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * Post everything outstanding. Safe to run at any time and as often as
     * wanted: a document already in the journal is skipped by the posting
     * itself, not by anything remembered here.
     */
    public static function sweep($limit = 500)
    {
        $posted = array_fill_keys(array_keys(self::KINDS), 0) + ['failed' => 0];

        foreach (array_keys(self::KINDS) as $kind) {
            foreach (self::pendingIds($kind, $limit) as $id) {
                try {
                    if (self::run($kind, $id)) {
                        $posted[$kind]++;
                    }
                } catch (\Throwable $e) {
                    $posted['failed']++;
                    Log::warning('Ledger sweep failed for ' . $kind . ' ' . $id . ': ' . $e->getMessage());
                }
            }
        }

        self::markRun();

        return $posted;
    }

    private static function markRun()
    {
        DB::table('settings')->orderBy('id')->limit(1)->update(['ledger_posted_at' => now()]);
    }
}
