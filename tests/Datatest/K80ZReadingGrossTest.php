<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W2Helpers;

/**
 * K80 — the Z-reading "gross" leaves out the VAT removed on SC/PWD sales, so
 * gross does not reconcile to shelf price.
 * One senior sale of a 265.00 bag: VAT removed 28.39, SC discount 47.32, net 189.29.
 */
class K80ZReadingGrossTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K80 — the reading's gross is 236.61 (net + SC discount); at shelf price it is 265.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_gross_includes_the_vat_removed_on_senior_sales()
    {
        $session = $this->as($this->owner)->postJson('/api/till/open', ['warehouse_id' => $this->branch,
            'opening_float' => 1000, 'terminal' => 'BT3'])->assertOk()->json('session.id');
        $this->pos([$this->line(1, 265, ['subtotal' => 189.29])], $this->branch,
            $this->senior(['amount' => 189.29, 'change' => 0]))->assertOk();

        $reading = $this->getJson("/api/till/sessions/$session/reading")->assertOk()->json('reading');

        $this->assertEquals(189.29, $this->money($reading['sales']['net']), 'fixture: the senior paid 189.29');
        $this->assertEquals(265, $this->money($reading['sales']['gross']),
            "K80: gross reads {$reading['sales']['gross']} = net {$reading['sales']['net']} + SC {$reading['discounts']['statutory']}; the 28.39 VAT removed is missing");
    }
}
