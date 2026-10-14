<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K50 — Product, tax and tax-group master data validate only `required`:
 * negative or non-numeric price and cost, tax outside 0-100.
 */
class K50MasterDataBoundsTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K50 — A product is created with price -100 and cost -50.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_product_with_a_negative_price_or_cost_is_refused()
    {
        $this->actAs($this->owner)->postJson('/api/Products', $this->newProductBody('W3-OK'))->assertOk(); // fixture: the form is valid

        $response = $this->postJson('/api/Products', $this->newProductBody('W3-NEG', ['price' => -100, 'cost' => -50]));

        $this->assertRefused($response, 'price and cost must be 0 or more');
        $this->assertSame(0, DB::table('products')->where('code', 'W3-NEG')->count());
    }

    /**
     * K50 — A product is created with price "abc", stored as 0.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_product_with_a_non_numeric_price_is_refused()
    {
        $response = $this->actAs($this->owner)->postJson('/api/Products', $this->newProductBody('W3-ABC', ['price' => 'abc', 'cost' => '12abc']));

        $this->assertRefused($response, 'price and cost must be numbers');
        $this->assertSame(0, DB::table('products')->where('code', 'W3-ABC')->count());
    }

    /**
     * K50 — A product is created with a VAT rate of 500 %.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_product_with_a_tax_above_100_percent_is_refused()
    {
        $response = $this->actAs($this->owner)->postJson('/api/Products', $this->newProductBody('W3-TAX', ['TaxNet' => 500]));

        $this->assertRefused($response, 'a tax percentage lies between 0 and 100');
        $this->assertSame(0, DB::table('products')->where('code', 'W3-TAX')->count());
    }

    /**
     * K50 — A tax group is created with a 500 % rate.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_tax_group_rate_above_100_percent_is_refused()
    {
        $response = $this->actAs($this->owner)->postJson('/api/tax_groups', ['name' => 'W3 500', 'rates' => [['name' => 'r', 'rate' => 500]]]);

        $this->assertRefused($response, 'a tax percentage lies between 0 and 100');
        $this->assertSame(0, DB::table('tax_groups')->where('name', 'W3 500')->count());
    }

    /**
     * K50 — A POS sale is accepted with an order tax of 101 %.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_pos_order_tax_above_100_percent_is_refused()
    {
        $response = $this->actAs($this->owner)->pos([$this->line(1)], null, ['tax_rate' => 101]);

        $this->assertRefused($response, 'a tax percentage lies between 0 and 100');
        $this->assertSame(0, DB::table('sales')->count());
    }
}
