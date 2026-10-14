<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K17 — POS CreatePOS never calls the credit-limit check (M5: the credit limit
 * refuses a sale that would exceed it).
 */
class K17PosCreditLimitTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K17 — a 2,650.00 unpaid POS sale goes through for a customer with a 1,000.00 credit limit; the back office refuses the same basket.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_pos_refuses_a_sale_over_the_customers_credit_limit()
    {
        DB::table('clients')->where('id', $this->client)->update(['credit_limit' => 1000]);
        $this->as($this->owner)->storeSale([$this->line(10)])->assertStatus(422);  // control: the back office refuses
        $before = (int) $this->lastId('sales');

        $response = $this->posUnpaid([$this->line(10)]);

        $sale = DB::table('sales')->where('id', '>', $before)->first();
        $this->assertTrue($sale === null, 'K17: the POS put ' . ($sale->GrandTotal ?? '') . ' on account for a customer with a 1,000.00 credit limit (HTTP '
            . $response->status() . ')');
        $response->assertStatus(422);
    }
}
