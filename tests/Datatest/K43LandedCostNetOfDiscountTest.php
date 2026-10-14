<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K43: the weighted-average cost uses the gross unit cost and ignores the line
 * discount. Default applied: landed cost uses the net line cost (after the
 * line discount); order discount and shipping are not spread.
 *
 * Cement: 70 bags on hand (50 Main + 20 Branch) at an average of 230.
 */
class K43LandedCostNetOfDiscountTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K43 — a goods receipt at 300 less 50% moves the average as if 300 were paid.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_goods_receipt_values_the_stock_at_the_discounted_cost()
    {
        $this->as($this->owner);
        $id = $this->purchase([$this->buyLine(10, 300, ['discount' => 50, 'discount_Method' => '1'])], 'ordered');
        $this->assertEquals(1500, (float) DB::table('purchases')->where('id', $id)->value('GrandTotal'), '10 at 150 net');

        $this->receive($id, 10)->assertOk();

        // (70 x 230 + 10 x 150) / 80
        $this->assertEqualsWithDelta(220.0, $this->cost(), 0.001, 'the average takes the 150 actually paid, not 300');
        $row = DB::table('stock_movements')->where('product_id', $this->cement)->orderByDesc('id')->first();
        $this->assertEqualsWithDelta(150, (float) $row->unit_cost, 0.001, 'the receipt movement is valued net');
    }

    /**
     * K43 — a purchase saved "received" at 300 less 10% moves the average as if 300 were paid.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_received_purchase_values_the_stock_at_the_discounted_cost()
    {
        $this->as($this->owner);
        $post = $this->postJson('/api/purchases', $this->purchaseBody([$this->buyLine(10, 300, ['discount' => 10, 'discount_Method' => '1'])], 'received'));
        if (! $post->isSuccessful()) {
            // A repair may route all receiving through goods receipts (K7); the receipt test covers the cost then.
            $this->assertRefused($post, 'a refused save is a 4xx');
            $this->assertEqualsWithDelta(230, $this->cost(), 0.001);

            return;
        }

        // (70 x 230 + 10 x 270) / 80
        $this->assertEqualsWithDelta(235.0, $this->cost(), 0.001, 'the average takes the 270 actually paid, not 300');
    }
}
