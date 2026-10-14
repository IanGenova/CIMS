<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K51 — Document `statut` is not validated against the lifecycle states:
 * "banana" is stored on purchases, transfers, returns and quotations.
 */
class K51DocumentStatutTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K51 — A purchase is stored with statut "banana".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_with_an_unknown_status_is_refused()
    {
        $response = $this->actAs($this->owner)->purchase([$this->buyLine(10)], ['statut' => 'banana']);

        $this->assertRefused($response, '"banana" is not a purchase state');
        $this->assertSame(0, DB::table('purchases')->where('statut', 'banana')->count());
    }

    /**
     * K51 — A transfer is stored with statut "banana".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_transfer_with_an_unknown_status_is_refused()
    {
        $response = $this->actAs($this->owner)->transfer([$this->moveLine(1)], ['statut' => 'banana']);

        $this->assertRefused($response, '"banana" is not a transfer state');
        $this->assertSame(0, DB::table('transfers')->where('statut', 'banana')->count());
    }

    /**
     * K51 — A sale return is stored with statut "banana".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_return_with_an_unknown_status_is_refused()
    {
        $response = $this->actAs($this->owner)->saleReturn([$this->line(1)], ['statut' => 'banana']);

        $this->assertRefused($response, '"banana" is not a return state');
        $this->assertSame(0, DB::table('sale_returns')->where('statut', 'banana')->count());
    }

    /**
     * K51 — A purchase return is stored with statut "banana".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_return_with_an_unknown_status_is_refused()
    {
        $response = $this->actAs($this->owner)->purchaseReturn([$this->buyLine(1)], ['statut' => 'banana']);

        $this->assertRefused($response, '"banana" is not a return state');
        $this->assertSame(0, DB::table('purchase_returns')->where('statut', 'banana')->count());
    }

    /**
     * K51 — A quotation is stored with statut "banana".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_quotation_with_an_unknown_status_is_refused()
    {
        $response = $this->actAs($this->owner)->quotation([$this->line(1)], ['statut' => 'banana']);

        $this->assertRefused($response, '"banana" is not a quotation state');
        $this->assertSame(0, DB::table('quotations')->where('statut', 'banana')->count());
    }
}
