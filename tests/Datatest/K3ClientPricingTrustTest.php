<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K3 — the till takes the unit price, the line discount and the tax method from
 * the browser. Owner decision: the server uses the product's price; only a user
 * with the price/discount override permission may change it (the cashier has none).
 */
class K3ClientPricingTrustTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K3 — a cashier without the override permission sold two 265 bags for 1.00 each.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_cashier_cannot_sell_below_the_list_price()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->cashier)->pos([$this->line(2, 1)], $this->branch, ['amount' => 0, 'change' => 0]);

        $this->assertNotEquals(500, $response->status(), 'the request itself must not crash: ' . $response->getContent());
        $sale = DB::table('sales')->where('id', '>', $before)->first();
        if ($sale) {
            $line = DB::table('sale_details')->where('sale_id', $sale->id)->first();
            $this->assertEquals(265, $this->money($line->price),
                "K3: the cashier's sale was stored at {$line->price} a bag; the product's price is 265");
            $this->assertEquals(530, $this->money($sale->GrandTotal), 'K3: two bags at the list price come to 530');
        } else {
            $response->assertStatus(422);
        }
    }

    /**
     * K3 — a cashier gave a 50.00 line discount although only the owner may discount.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_cashier_cannot_give_a_line_discount()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->cashier)->pos([$this->line(1, 265, ['discount' => 50, 'subtotal' => 215])],
            $this->branch, ['amount' => 0, 'change' => 0]);

        $this->assertNotEquals(500, $response->status(), $response->getContent());
        $sale = DB::table('sales')->where('id', '>', $before)->first();
        if ($sale) {
            $this->assertEquals(265, $this->money($sale->GrandTotal),
                "K3: the cashier's discounted bag was stored at {$sale->GrandTotal}; without the override permission it sells at 265");
        } else {
            $response->assertStatus(422);
        }
    }

    /**
     * K3 — a line discount larger than the line is accepted, leaving a negative line (-35.00) with negative VAT.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_line_discount_cannot_take_a_line_below_zero()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->owner)->pos([
            $this->line(1, 265, ['discount' => 300, 'subtotal' => 0]),
            $this->line(2, 265),
        ], null, ['amount' => 0, 'change' => 0]);

        $this->assertNotEquals(500, $response->status(), $response->getContent());
        $lowest = DB::table('sale_details')->where('sale_id', '>', $before)->min('total');
        $this->assertTrue($lowest === null || (float) $lowest >= 0,
            "K3: a sale line was stored with a negative total of {$lowest}; a discount is capped at the line amount");
    }

    /**
     * K3 — the client's tax_method is kept: a VAT-inclusive 265 item sent as "exclusive" is charged 296.80.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_tax_method_comes_from_the_product_not_the_browser()
    {
        $vatItem = $this->product(['TaxNet' => 12, 'tax_method' => '2']);
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->cashier)->pos([$this->lineOf($vatItem, 1, 265, ['tax_method' => '1', 'tax_percent' => 0])],
            $this->branch, ['amount' => 0, 'change' => 0]);

        $this->assertNotEquals(500, $response->status(), $response->getContent());
        $sale = DB::table('sales')->where('id', '>', $before)->first();
        if ($sale) {
            $line = DB::table('sale_details')->where('sale_id', $sale->id)->first();
            $this->assertEquals(265, $this->money($sale->GrandTotal),
                "K3: a VAT-inclusive 265 item was charged {$sale->GrandTotal} because the browser said tax_method {$line->tax_method}");
        } else {
            $response->assertStatus(422);
        }
    }
}
