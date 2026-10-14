<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K33 — order-level tax_rate is added on top of VAT-inclusive totals and sits
 * outside the VAT buckets. Decision: no order-level tax on VAT-inclusive lines.
 */
class K33OrderTaxOnVatInclusiveTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K33 — a 12% order tax on a 265.00 VAT-inclusive bag charges 296.80, with 31.80 in no VAT bucket.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_no_order_tax_is_added_on_vat_inclusive_lines()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->owner)->posUnpaid([$this->line(1)], null, ['tax_rate' => 12]);

        $sale = DB::table('sales')->where('id', '>', $before)->first();
        if (! $sale) {
            $response->assertStatus(422);

            return;
        }
        $buckets = $this->money($sale->vatable_sales + $sale->vat_amount + $sale->vat_exempt_sales + $sale->zero_rated_sales);
        $this->assertEquals(265, $this->money($sale->GrandTotal),
            "K33: a 265.00 VAT-inclusive bag was charged {$sale->GrandTotal} (order tax {$sale->TaxNet})");
        $this->assertEquals($this->money($sale->GrandTotal), $buckets, 'K33: the VAT buckets do not add up to the total');
    }
}
