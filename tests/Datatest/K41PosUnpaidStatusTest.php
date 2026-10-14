<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K41 — an unpaid POS sale is saved with payment_statut empty: the status is written only when an amount was tendered.
 */
class K41PosUnpaidStatusTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K41 — An unpaid POS sale is saved with payment_statut '' instead of 'unpaid'.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_unpaid_pos_sale_is_marked_unpaid()
    {
        $this->as($this->owner)->pos([$this->line(2)], null, ['amount' => 0, 'received_amount' => 0])->assertOk();

        $sale = $this->lastSale();
        $this->assertEquals(530, $this->money($sale->GrandTotal), 'fixture: two bags at 265');
        $this->assertEquals(0, $this->money($sale->paid_amount));
        $this->assertSame('unpaid', $sale->payment_statut,
            "K41 (M4): an unpaid sale must read 'unpaid'; it was saved as '" . $sale->payment_statut . "'");
    }
}
