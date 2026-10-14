<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K39: quotation conversion is not recorded -- no link from the sale to the
 * quotation -- so one quotation converts any number of times (map L2:
 * convert-to-sale creates one sale, once). The sale is posted with the
 * quotation_id the repair will honour.
 */
class K39QuotationConvertsOnceTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K39 — the same quotation converts into two sales.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_quotation_converts_into_one_sale_once()
    {
        $this->as($this->owner)->postJson('/api/quotations', ['date' => $this->today(), 'client_id' => $this->client,
            'warehouse_id' => $this->main, 'statut' => 'sent', 'notes' => 'W1 quote', 'tax_rate' => 0, 'discount' => 0,
            'shipping' => 0, 'GrandTotal' => 530, 'details' => [$this->line(2)]])->assertOk();
        $quotation = $this->lastId('quotations');
        $this->getJson("/api/sales/Change_to_Sale/{$quotation}")->assertOk();

        $this->sale([$this->line(2)], 'completed', null, ['quotation_id' => $quotation])->assertOk();
        $second = $this->sale([$this->line(2)], 'completed', null, ['quotation_id' => $quotation]);

        $this->assertRefused($second, 'the quotation was already converted');
        $this->assertSame(1, DB::table('sales')->count(), 'one quotation, one sale');
        $this->assertEquals(48, $this->shelf(), 'two bags leave, not four');
    }
}
