<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K27 — the journal is posted only for sale, purchase and expense, once at
 * creation. Deletes are never reversed, and payments, edits and returns are never posted.
 * Driven through the API, then the scheduled `ledger:post` sweep.
 */
class K27JournalCoverageTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K27 — a posted sale that is then deleted keeps its revenue entry with no reversal.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_a_posted_sale_reverses_its_journal_entries()
    {
        $this->as($this->owner)->pos([$this->line(1)])->assertOk();
        $saleId = $this->lastId('sales');
        $this->postLedger();
        $this->assertNotEquals(0.0, $this->journalNet('cash', 'Sale', $saleId), 'fixture: the sale reached the journal');

        $this->deleteJson("/api/sales/$saleId")->assertOk();
        $this->postLedger();

        $revenue = $this->journalNet('sales_revenue', 'Sale', $saleId);
        $cash = $this->journalNet('cash', 'Sale', $saleId);
        $reversals = DB::table('journal_entries')->where('source_id', $saleId)->whereNotNull('reverses_entry_id')->count();
        $this->assertEquals(0.0, $cash, "K27: a deleted sale still leaves cash {$cash} in the journal ({$reversals} reversing entries)");
        $this->assertEquals(0.0, $revenue, "K27: a deleted sale still leaves revenue {$revenue} in the journal");
    }

    /**
     * K27 — a 100.00 payment taken after the sale was posted never reaches the journal (cash stays 0).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_later_payment_is_posted_to_the_journal()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)])->assertOk();
        $saleId = $this->lastId('sales');
        $this->postLedger();
        $this->assertEquals(0.0, $this->journalNet('cash'), 'fixture: nothing was paid at the sale');

        $this->paySale($saleId, 100)->assertOk();
        $this->postLedger();

        $cash = $this->journalNet('cash');
        $receivable = $this->journalNet('accounts_receivable');
        $this->assertEquals(100.0, $cash, "K27: a 100.00 payment left the journal's cash at {$cash}");
        $this->assertEquals(165.0, $receivable, "K27: after a 100.00 payment on 265.00 the receivable reads {$receivable}");
    }

    /**
     * K27 — a 265.00 cash sale returned and refunded in cash leaves 265.00 of cash in the journal.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_refunded_return_is_posted_to_the_journal()
    {
        $this->as($this->owner)->pos([$this->line(1)])->assertOk();
        $saleId = $this->lastId('sales');
        $this->postLedger();
        $this->assertEquals(265.0, $this->journalNet('cash'), 'fixture: the cash sale is in the journal');

        $this->saleReturn($saleId, [$this->line(1)])->assertOk();
        $this->refund($this->lastId('sale_returns'), 265)->assertOk();
        $this->postLedger();

        $cash = $this->journalNet('cash');
        $this->assertEquals(0.0, $cash, "K27: after the 265.00 refund the journal's cash still reads {$cash}");
    }
}
