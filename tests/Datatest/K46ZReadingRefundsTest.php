<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W2Helpers;

/**
 * K46 — the Z-reading deducts every sale-return refund made at the warehouse
 * during the shift, whatever the payment method or user. Decision: a refund
 * comes out of the drawer of the user who gave it, and only cash refunds count.
 * Cashier's Branch drawer: float 1,000.00 + one cash sale of 265.00 = 1,265.00.
 */
class K46ZReadingRefundsTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function shiftWithARefund($method)
    {
        $session = $this->as($this->cashier)->postJson('/api/till/open', ['warehouse_id' => $this->branch,
            'opening_float' => 1000])->assertOk()->json('session.id');
        $this->pos([$this->line(1)], $this->branch)->assertOk();
        $saleId = $this->lastId('sales');

        $this->as($this->owner)->saleReturn($saleId, [$this->line(1)], $this->branch)->assertOk();
        $this->refund($this->lastId('sale_returns'), 265, $method)->assertOk();

        return $this->as($this->cashier)->postJson("/api/till/sessions/$session/close", ['counted_cash' => 1265])
            ->assertOk()->json('reading.cash');
    }

    /**
     * K46 — a 265.00 refund by bank transfer is taken out of the cashier's drawer (expected 1,000.00, not 1,265.00).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_non_cash_refund_does_not_come_out_of_the_drawer()
    {
        $cash = $this->shiftWithARefund('bank transfer');

        $this->assertEquals(1265, $this->money($cash['expected']),
            "K46: a bank-transfer refund cut the drawer's expected cash to {$cash['expected']} (refunds {$cash['refunds']})");
    }

    /**
     * K46 — a cash refund given by the owner (no till of theirs) is taken out of the cashier's drawer.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_another_users_cash_refund_does_not_come_out_of_this_drawer()
    {
        $cash = $this->shiftWithARefund('Cash');

        $this->assertEquals(1265, $this->money($cash['expected']),
            "K46: the owner's cash refund cut the cashier's expected cash to {$cash['expected']} (refunds {$cash['refunds']})");
    }
}
