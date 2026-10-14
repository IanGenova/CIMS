<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K30 — the POS and sale store check the amount tendered, and stamp base_amount,
 * against the total before VAT exemption and the SC/PWD discount.
 * Senior, one 265.00 bag: 265 / 1.12 = 236.61, less 20% = 189.29 due.
 */
class K30TenderBeforeStatutoryTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function assertNotOverpaid($before, $response)
    {
        $sale = DB::table('sales')->where('id', '>', $before)->first();
        if (! $sale) {
            $response->assertStatus(422);

            return;
        }
        $this->assertEquals(189.29, $this->money($sale->GrandTotal), 'fixture: senior total due');
        $this->assertLessThanOrEqual($this->money($sale->GrandTotal), $this->money($sale->paid_amount),
            "K30: a senior sale totalling {$sale->GrandTotal} was recorded as paid {$sale->paid_amount}");
    }

    /**
     * K30 — the POS accepts 265.00 tendered on a 189.29 senior sale and stores paid 265.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_pos_checks_the_tender_against_the_senior_total()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->owner)->pos([$this->line(1)], null, $this->senior(['amount' => 265, 'change' => 0]));

        $this->assertNotOverpaid($before, $response);
    }

    /**
     * K30 — POST /api/sales accepts 265.00 paid on a 189.29 senior sale.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_back_office_sale_checks_the_payment_against_the_senior_total()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->owner)->storeSale([$this->line(1)], $this->senior(['amount' => 265]));

        $this->assertNotOverpaid($before, $response);
    }

    /**
     * K30 — base_amount is stamped 265.00 from the pre-discount total while the sale totals 189.29.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_base_amount_is_the_total_after_the_senior_discount()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)], null, $this->senior())->assertOk();

        $sale = $this->lastSale();
        $this->assertEquals(189.29, $this->money($sale->GrandTotal), 'fixture: senior total due');
        $this->assertEquals($this->money($sale->GrandTotal), $this->money($sale->base_amount),
            "K30: base_amount {$sale->base_amount} does not match the sale total {$sale->GrandTotal}");
    }
}
