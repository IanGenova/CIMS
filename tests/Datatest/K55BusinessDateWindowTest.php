<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K55 — No business-date window. Decision: documents may be dated up to 30
 * days back and never in the future.
 */
class K55BusinessDateWindowTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K55 — A completed sale dated 9999-12-31 is accepted and moves stock today.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_dated_in_the_future_is_refused()
    {
        $response = $this->actAs($this->owner)->sale([$this->line(1)], ['date' => '9999-12-31']);

        $this->assertRefused($response, 'a document is never dated in the future');
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertEquals(50, $this->shelf($this->main));
    }

    /**
     * K55 — A completed sale dated 1900-01-01 is accepted and moves stock today.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_dated_more_than_30_days_back_is_refused()
    {
        $response = $this->actAs($this->owner)->sale([$this->line(1)], ['date' => '1900-01-01']);

        $this->assertRefused($response, 'a document may be dated at most 30 days back');
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertEquals(50, $this->shelf($this->main));
    }

    /**
     * K55 — A sale payment dated 0001-01-01 is accepted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_payment_dated_0001_is_refused()
    {
        $this->actAs($this->owner);
        $sale = $this->unpaidSale();

        $response = $this->postJson('/api/payment/sale', ['sale_id' => $sale, 'date' => '0001-01-01', 'montant' => 1,
            'Reglement' => 'Cash', 'change' => 0]);

        $this->assertRefused($response, 'a payment may be dated at most 30 days back');
        $this->assertSame(0, DB::table('payment_sales')->where('sale_id', $sale)->count());
    }

    /**
     * K55 — An expense dated 9999-12-31 is accepted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_expense_dated_in_the_future_is_refused()
    {
        $response = $this->actAs($this->owner)->expense(['date' => '9999-12-31']);

        $this->assertRefused($response, 'a document is never dated in the future');
        $this->assertSame(0, DB::table('expenses')->count());
    }

    /**
     * K55 — A purchase dated 31 days back is accepted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_dated_31_days_back_is_refused()
    {
        $response = $this->actAs($this->owner)->purchase([$this->buyLine(10)], ['date' => now()->subDays(31)->toDateString()]);

        $this->assertRefused($response, 'a document may be dated at most 30 days back');
        $this->assertSame(0, DB::table('purchases')->count());
    }
}
