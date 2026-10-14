<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * A standing check of the data, rather than of the code.
 *
 * Every check here is an invariant the system is meant to hold at all times, so
 * anything that fails is a real inconsistency and not a matter of opinion. The
 * tests prove the code is right when it runs; this proves the data is still
 * right after it has been running for a while, which is a different question and
 * the one that goes unasked.
 *
 * It is deliberately read-only. The single repair it offers has to be asked for
 * by name, because an audit that quietly changes what it is measuring is not an
 * audit.
 */
class SystemAudit
{
    /**
     * Run every check.
     *
     * Returns the checks, a count of failures, and a separate list of notes --
     * things worth knowing that are not faults, which would otherwise either be
     * lost or inflate the failure count until nobody trusts it.
     */
    public static function run()
    {
        $checks = [];
        $notes = [];

        $checks[] = self::stockReconciles();
        $checks[] = self::everyProductHasAStockRow();
        $checks[] = self::noNegativeStock();
        $checks[] = self::journalsBalance();
        $checks[] = self::noOrphans();
        $checks[] = self::voidedSalesCarryNoMoney();
        $checks[] = self::voidsAreExplained();
        $checks[] = self::invoiceRefsUnique();
        $checks[] = self::ledgerKeepingUp();
        $checks[] = self::theClock();
        $checks[] = self::schedulerAlive();

        foreach (self::gatherNotes() as $note) {
            $notes[] = $note;
        }

        $failures = 0;
        foreach ($checks as $c) {
            if (! $c['passed']) {
                $failures++;
            }
        }

        return ['checks' => $checks, 'failures' => $failures, 'notes' => $notes];
    }

    private static function result($name, $passed, $detail, array $rows = [])
    {
        return ['name' => $name, 'passed' => (bool) $passed, 'detail' => $detail, 'rows' => $rows];
    }

    /**
     * Does the shelf agree with the history that explains it?
     *
     * This is the one that matters most. Stock changing without a movement means
     * the ledger can no longer answer why the shelf holds what it holds, which is
     * the whole reason the movement table exists.
     */
    private static function stockReconciles()
    {
        $rows = [];
        $checked = 0;

        foreach (DB::table('product_warehouse')->whereNull('deleted_at')->get() as $pw) {
            $checked++;
            $sum = (float) DB::table('stock_movements')
                ->where('product_id', $pw->product_id)
                ->where('warehouse_id', $pw->warehouse_id)
                ->sum('quantity');

            if (abs($sum - (float) $pw->qte) > 0.0001) {
                $rows[] = sprintf('product %d in warehouse %d: shelf %s, movements %s (out by %+g)',
                    $pw->product_id, $pw->warehouse_id, rtrim(rtrim((string) $pw->qte, '0'), '.'),
                    $sum, $sum - (float) $pw->qte);
            }
        }

        return self::result('stock reconciles', empty($rows),
            sprintf('%d stocked row(s) checked, %d out of step', $checked, count($rows)), $rows);
    }

    /**
     * Does every product have somewhere to be, in every warehouse?
     *
     * A product with no row for a warehouse has no place for stock to land
     * there. Every screen that moves stock reads this row first, so the gap is
     * invisible until the day somebody transfers goods into that warehouse and
     * they do not arrive.
     */
    private static function everyProductHasAStockRow()
    {
        $products = DB::table('products')->whereNull('deleted_at')->pluck('id');
        $warehouses = DB::table('warehouses')->whereNull('deleted_at')->pluck('id');

        $held = DB::table('product_warehouse')->whereNull('deleted_at')
            ->whereNull('product_variant_id')
            ->get(['product_id', 'warehouse_id'])
            ->mapWithKeys(function ($r) {
                return [$r->product_id . ':' . $r->warehouse_id => true];
            });

        $rows = [];
        foreach ($products as $productId) {
            foreach ($warehouses as $warehouseId) {
                if (! isset($held[$productId . ':' . $warehouseId])) {
                    $rows[] = sprintf('product %d has no stock row in warehouse %d',
                        $productId, $warehouseId);
                }
            }
        }

        return self::result('every product has a stock row', empty($rows),
            sprintf('%d product/warehouse pair(s) checked, %d missing',
                count($products) * count($warehouses), count($rows)),
            array_slice($rows, 0, 20));
    }

    private static function noNegativeStock()
    {
        $rows = [];
        foreach (DB::table('product_warehouse')->whereNull('deleted_at')
            ->where('qte', '<', 0)->get() as $pw) {
            $rows[] = sprintf('product %d in warehouse %d: %s', $pw->product_id, $pw->warehouse_id, $pw->qte);
        }

        return self::result('no negative stock', empty($rows),
            sprintf('%d row(s) below zero', count($rows)), $rows);
    }

    private static function journalsBalance()
    {
        $out = DB::table('journal_lines')
            ->select('journal_entry_id', DB::raw('ROUND(SUM(debit) - SUM(credit), 4) AS diff'))
            ->groupBy('journal_entry_id')
            ->havingRaw('ABS(ROUND(SUM(debit) - SUM(credit), 4)) > 0.0001')
            ->get();

        $rows = [];
        foreach ($out as $u) {
            $rows[] = sprintf('entry %d is out by %s', $u->journal_entry_id, $u->diff);
        }

        return self::result('journal entries balance', $out->isEmpty(),
            sprintf('%d entr%s checked, %d unbalanced',
                DB::table('journal_entries')->count(),
                DB::table('journal_entries')->count() === 1 ? 'y' : 'ies',
                count($rows)), $rows);
    }

    private static function noOrphans()
    {
        $counts = [
            'sale lines with no sale' => DB::table('sale_details as d')
                ->leftJoin('sales as s', 's.id', '=', 'd.sale_id')->whereNull('s.id')->count(),
            'payments with no sale' => DB::table('payment_sales as p')
                ->leftJoin('sales as s', 's.id', '=', 'p.sale_id')->whereNull('s.id')->count(),
            'journal lines with no entry' => DB::table('journal_lines as l')
                ->leftJoin('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->whereNull('e.id')->count(),
            'stock movements for a missing product' => DB::table('stock_movements as m')
                ->leftJoin('products as p', 'p.id', '=', 'm.product_id')->whereNull('p.id')->count(),
            'till movements with no session' => DB::table('till_movements as t')
                ->leftJoin('till_sessions as s', 's.id', '=', 't.till_session_id')
                ->whereNull('s.id')->count(),
        ];

        $rows = [];
        foreach ($counts as $what => $n) {
            if ($n > 0) {
                $rows[] = sprintf('%s: %d', $what, $n);
            }
        }

        return self::result('no orphaned records', empty($rows),
            sprintf('%d relationship(s) checked', count($counts)), $rows);
    }

    /**
     * A void hands the money straight back, so a voided sale that still shows
     * payments would leave the drawer expecting cash that is not there.
     */
    private static function voidedSalesCarryNoMoney()
    {
        $paid = DB::table('sales')->where('statut', 'voided')->where('paid_amount', '>', 0)->count();
        $payments = DB::table('payment_sales as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.statut', 'voided')->whereNull('p.deleted_at')->count();

        $rows = [];
        if ($paid > 0) {
            $rows[] = "$paid voided sale(s) still show an amount paid";
        }
        if ($payments > 0) {
            $rows[] = "$payments payment row(s) still attached to a voided sale";
        }

        return self::result('voided sales carry no money', empty($rows),
            sprintf('%d voided sale(s)', DB::table('sales')->where('statut', 'voided')->count()), $rows);
    }

    /**
     * The reason is what explains the gap a void leaves in the invoice numbers.
     * Without it the gap is merely present, which is the thing a void exists to
     * avoid.
     */
    private static function voidsAreExplained()
    {
        $n = DB::table('sales')->where('statut', 'voided')
            ->where(function ($q) {
                $q->whereNull('void_reason')->orWhere('void_reason', '');
            })->count();

        return self::result('voids are explained', $n === 0,
            sprintf('%d void(s) without a reason', $n),
            $n > 0 ? ["$n voided sale(s) carry no reason"] : []);
    }

    private static function invoiceRefsUnique()
    {
        $dupes = DB::table('sales')->select('Ref', DB::raw('COUNT(*) n'))
            ->whereNull('deleted_at')->groupBy('Ref')->havingRaw('COUNT(*) > 1')->get();

        $rows = [];
        foreach ($dupes as $d) {
            $rows[] = sprintf('%s used %d times', $d->Ref, $d->n);
        }

        return self::result('invoice references are unique', $dupes->isEmpty(),
            sprintf('%d sale(s) checked', DB::table('sales')->whereNull('deleted_at')->count()), $rows);
    }

    private static function ledgerKeepingUp()
    {
        $pending = LedgerPoster::pendingCount();
        $enabled = LedgerPoster::isEnabled();

        $rows = [];
        if ($pending['total'] > 0) {
            $rows[] = sprintf('%d document(s) waiting: %d sale(s), %d purchase(s), %d expense(s)',
                $pending['total'], $pending['sale'], $pending['purchase'], $pending['expense']);
        }
        if (! $enabled) {
            $rows[] = 'automatic posting is switched off';
        }

        return self::result('the ledger is keeping up', empty($rows),
            sprintf('%d waiting, posting %s', $pending['total'], $enabled ? 'on' : 'off'), $rows);
    }

    /**
     * Three clocks have to agree: the application's, the database's, and the one
     * on the wall. A shop that disagrees with itself about the time cannot
     * produce a daily reading anyone would sign.
     */
    private static function theClock()
    {
        $rows = [];
        $zone = config('app.timezone');

        try {
            $wall = new \DateTime('now', new \DateTimeZone($zone));
        } catch (\Throwable $e) {
            return self::result('the clock', false, 'unknown timezone: ' . $zone, [$e->getMessage()]);
        }

        if (abs(now()->getTimestamp() - $wall->getTimestamp()) > 120) {
            $rows[] = sprintf('the application says %s but %s says %s',
                now()->format('H:i:s'), $zone, $wall->format('H:i:s'));
        }

        $mysql = DB::select('SELECT NOW() AS n')[0]->n;
        if (abs(strtotime($mysql) - now()->getTimestamp()) > 120) {
            $rows[] = sprintf('the database says %s, the application says %s',
                $mysql, now()->toDateTimeString());
        }

        return self::result('the clock', empty($rows),
            sprintf('%s, %s', $zone, now()->toDateTimeString()), $rows);
    }

    /**
     * The heartbeat is written by the scheduler itself, so a stale one means the
     * scheduler is not being run -- which no amount of checking from inside the
     * application would otherwise reveal.
     */
    private static function schedulerAlive()
    {
        $path = storage_path('framework/scheduler-heartbeat');

        if (! file_exists($path)) {
            return self::result('the scheduler is alive', false,
                'no heartbeat has ever been written',
                ['nothing is running the scheduler; see scripts/README-scheduler.md']);
        }

        $age = time() - strtotime(trim((string) file_get_contents($path)));

        return self::result('the scheduler is alive', $age < 300,
            sprintf('heartbeat is %d second(s) old', $age),
            $age >= 300 ? [sprintf('the scheduler has not run for %d minute(s)', intdiv($age, 60))] : []);
    }

    /** Things worth knowing that are not faults. */
    private static function gatherNotes()
    {
        $notes = [];

        $demo = DB::table('products')->where('code', 'LIKE', 'DEMO%')->count();
        if ($demo > 0) {
            $notes[] = "$demo DEMO-prefixed product(s) still loaded from testing";
        }

        $stale = DB::table('till_sessions')->whereNull('deleted_at')
            ->where('status', 'open')
            ->where('opened_at', '<', now()->subDay())->count();
        if ($stale > 0) {
            $notes[] = "$stale till session(s) have been open more than a day";
        }

        return $notes;
    }

    /**
     * Bring the shelf back into line with the movements that explain it.
     *
     * The movements are the side to trust: each one is a recorded event with a
     * reason and a document behind it, while the shelf is a single number that
     * something changed without saying so. Returns how many rows moved.
     *
     * Not run by the audit itself -- an audit that changes what it measures is
     * not an audit.
     */
    public static function repairStockDrift()
    {
        $fixed = 0;

        foreach (DB::table('product_warehouse')->whereNull('deleted_at')->get() as $pw) {
            $sum = (float) DB::table('stock_movements')
                ->where('product_id', $pw->product_id)
                ->where('warehouse_id', $pw->warehouse_id)
                ->sum('quantity');

            if (abs($sum - (float) $pw->qte) > 0.0001) {
                DB::table('product_warehouse')->where('id', $pw->id)
                    ->update(['qte' => $sum, 'updated_at' => now()]);
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Give every product a stock row in every warehouse, at zero.
     *
     * Written straight to the table rather than through the model: these rows
     * hold nothing, so there is no movement to record, and a batch-tracked
     * product would otherwise be refused by the model's own guard.
     */
    public static function createMissingStockRows()
    {
        $products = DB::table('products')->whereNull('deleted_at')->pluck('id');
        $warehouses = DB::table('warehouses')->whereNull('deleted_at')->pluck('id');

        $held = DB::table('product_warehouse')->whereNull('deleted_at')
            ->whereNull('product_variant_id')
            ->get(['product_id', 'warehouse_id'])
            ->mapWithKeys(function ($r) {
                return [$r->product_id . ':' . $r->warehouse_id => true];
            });

        $made = [];
        foreach ($products as $productId) {
            foreach ($warehouses as $warehouseId) {
                if (! isset($held[$productId . ':' . $warehouseId])) {
                    $made[] = [
                        'product_id' => $productId,
                        'warehouse_id' => $warehouseId,
                        'product_variant_id' => null,
                        'qte' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        foreach (array_chunk($made, 200) as $chunk) {
            DB::table('product_warehouse')->insert($chunk);
        }

        return count($made);
    }
}
