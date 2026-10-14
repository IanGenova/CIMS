<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W2Helpers;

/**
 * K34 — output VAT is booked as sales revenue: postSale credits tax_payable
 * from TaxNet (order tax) instead of the sale's vat_amount (M1).
 */
class K34VatBookedAsRevenueTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K34 — a 265.00 VAT-inclusive cash sale (VAT 28.39) posts revenue 265.00 and no Tax Payable line.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_output_vat_is_credited_to_tax_payable_not_revenue()
    {
        $this->as($this->owner)->pos([$this->line(1)])->assertOk();
        $sale = $this->lastSale();
        $this->assertEquals(28.39, $this->money($sale->vat_amount), 'fixture: 265 - 265/1.12');

        $this->postLedger();

        $taxPayable = 0.0 - $this->journalNet('tax_payable', 'Sale', $sale->id);
        $revenue = 0.0 - $this->journalNet('sales_revenue', 'Sale', $sale->id);
        $this->assertEquals(28.39, $taxPayable, "K34: the sale credited Tax Payable {$taxPayable}; its output VAT is 28.39");
        $this->assertEquals(236.61, $revenue, "K34: the sale credited revenue {$revenue}; net of VAT it is 236.61");
    }
}
