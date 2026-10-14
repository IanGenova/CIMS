<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W2Helpers;

/**
 * K74 — the SC/PWD statutory discount stacks with the order (promo) discount.
 * Decision (RA 9994): the customer gets the higher of the two, not both.
 */
class K74StatutoryStacksPromoTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K74 — senior + 20.00 order discount on a 265.00 bag charges 175.00 (both applied);
     * SC alone = 189.29, promo alone = 245.00, so the higher discount gives 189.29.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_senior_gets_the_higher_of_the_statutory_and_promo_discount()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)], null, $this->senior(['discount' => 20]))->assertOk();

        $sale = $this->lastSale();
        $this->assertEquals(189.29, $this->money($sale->GrandTotal),
            "K74: senior plus a 20.00 promo was charged {$sale->GrandTotal} (statutory {$sale->statutory_discount}, promo {$sale->discount})");
    }
}
