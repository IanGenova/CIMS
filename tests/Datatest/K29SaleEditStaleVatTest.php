<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K29 — SalesController@update recomputes the total with OrderTotals only; the
 * VAT buckets, SC/PWD discount and base_amount stay as they were before the edit.
 */
class K29SaleEditStaleVatTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K29 — editing a 2-bag sale (530.00) to 1 bag keeps VATable 473.21, VAT 56.79 and base_amount 530.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_edit_recomputes_the_vat_breakdown_and_base_amount()
    {
        $this->as($this->owner)->storeSale([$this->line(2)])->assertOk();
        $saleId = $this->lastId('sales');

        $this->putJson("/api/sales/$saleId", $this->saleEditPayload($saleId, [1]))->assertOk();

        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertEquals(265, $this->money($sale->GrandTotal), 'fixture: one bag after the edit');
        $this->assertEquals(28.39, $this->money($sale->vat_amount), "K29: after the edit the VAT still reads {$sale->vat_amount}");
        $this->assertEquals(236.61, $this->money($sale->vatable_sales), "K29: after the edit VATable sales still read {$sale->vatable_sales}");
        $this->assertEquals(265, $this->money($sale->base_amount), "K29: after the edit base_amount still reads {$sale->base_amount}");
    }

    /**
     * K29 — saving a senior sale unchanged (statutory fields resent) raises it from 189.29 to 265.00 and leaves it part-paid.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_unchanged_edit_keeps_the_senior_discount()
    {
        $this->as($this->owner)->storeSale([$this->line(1)], $this->senior())->assertOk();
        $saleId = $this->lastId('sales');
        $this->assertEquals(189.29, $this->money(DB::table('sales')->where('id', $saleId)->value('GrandTotal')), 'fixture');

        $this->putJson("/api/sales/$saleId", $this->saleEditPayload($saleId, [], $this->senior()))->assertOk();

        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertEquals(189.29, $this->money($sale->GrandTotal),
            "K29: an unchanged senior sale now totals {$sale->GrandTotal} (statutory discount still stored as {$sale->statutory_discount})");
    }
}
