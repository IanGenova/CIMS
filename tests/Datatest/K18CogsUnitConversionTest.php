<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K18 — COGS in ProfitCalculator sums sale-unit quantities and walks purchase
 * lines (FIFO) without unit conversion, status or warehouse. Decision: reports
 * cost goods at the moving average, as the stock ledger does.
 */
class K18CogsUnitConversionTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K18 — selling one box of 10 bags (cost 230 a bag) books COGS 230.00 instead of 2,300.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_cogs_converts_a_box_into_bags()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1, 2650, ['sale_unit_id' => $this->box])])->assertOk();
        $this->assertEquals(40, $this->shelf(), 'fixture: a box of 10 bags left Main');
        $ledgerCost = $this->money(abs(DB::table('stock_movements')->where('reference_type', 'Sale')
            ->where('reference_id', $this->lastId('sales'))->sum('total_cost')));

        $pnl = $this->profitAndLoss();

        $this->assertEquals(2300, $this->money($pnl['cost_of_goods_sold']),
            "K18: one box of 10 bags at 230 was costed {$pnl['cost_of_goods_sold']} (stock ledger says {$ledgerCost})");
    }

    /**
     * K18 — an ordered, never-received purchase at 100 a bag sets the cost of a bag sold today (COGS 100.00, not 230.00).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_cogs_ignores_purchases_that_were_never_received()
    {
        $this->as($this->owner)->storePurchase([$this->purchaseLine(10, 100)], 'ordered')->assertOk();
        $this->assertEquals(50, $this->shelf(), 'fixture: nothing was received');
        $this->posUnpaid([$this->line(1)])->assertOk();

        $pnl = $this->profitAndLoss();

        $this->assertEquals(230, $this->money($pnl['cost_of_goods_sold']),
            "K18: one bag (moving-average cost 230) was costed {$pnl['cost_of_goods_sold']} from an unreceived order");
    }
}
