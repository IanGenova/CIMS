<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K42 — payment status uses strict float equality ($due === 0.0), so a fully
 * paid document stays 'partial' for good (M4: paid when paid >= total).
 */
class K42FloatPaymentStatusTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K42 — a 2,760.80 sale paid 0.10 then 2,760.70 (exactly 2,760.80) stays 'partial'.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_paid_to_the_centavo_is_marked_paid()
    {
        $item = $this->product(['price' => 276.08]);
        $this->as($this->owner)->posUnpaid([$this->lineOf($item, 10, 276.08)])->assertOk();
        $saleId = $this->lastId('sales');

        $this->paySale($saleId, 0.10)->assertOk();
        $this->paySale($saleId, 2760.70)->assertOk();

        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertEquals(2760.80, $this->money($sale->GrandTotal), 'fixture: 10 x 276.08');
        $this->assertEquals(2760.80, $this->money($sale->paid_amount), 'fixture: paid in full');
        $this->assertSame('paid', $sale->payment_statut,
            "K42: a sale paid exactly in full reads '{$sale->payment_statut}'");
    }
}
