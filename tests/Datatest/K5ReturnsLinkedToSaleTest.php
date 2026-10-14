<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K5: returns are not linked to the sale they return. Owner decision: a return
 * is made from a sale (sale_id), is capped at sold - already returned, and a
 * later delete or void of the sale gives back only what did not come back.
 */
class K5ReturnsLinkedToSaleTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K5 — a customer return with no sale behind it is accepted and restocks.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_return_must_name_a_sale_of_that_customer()
    {
        $this->as($this->owner);
        $this->assertSame(0, DB::table('sale_details')->count(), 'the customer never bought anything');

        $response = $this->saleReturn([$this->line(50, 265)]);

        $this->assertRefused($response, 'a return that names no sale is refused');
        $this->assertEquals(50, $this->shelf(), 'no stock arrives from a sale that never happened');
        $this->assertSame(0, DB::table('sale_returns')->count());
    }

    /**
     * K5 — nothing caps a return at what was sold less what already came back.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_return_is_capped_at_sold_minus_already_returned()
    {
        $this->as($this->owner);
        $saleId = $this->sale([$this->line(2)])->assertOk()->json('id');
        $this->assertEquals(48, $this->shelf());

        $this->saleReturn([$this->line(2)], ['sale_id' => $saleId])->assertOk();
        $this->assertEquals(50, $this->shelf());

        $again = $this->saleReturn([$this->line(1)], ['sale_id' => $saleId]);

        $this->assertRefused($again, 'both bags sold already came back; a third cannot');
        $this->assertEquals(50, $this->shelf(), 'only the 2 bags sold can come back');
    }

    /**
     * K5 — deleting a sale after part of it was returned restocks the returned units twice.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_a_sale_after_its_return_does_not_restock_the_returned_units_again()
    {
        $this->as($this->owner);
        $saleId = $this->sale([$this->line(2)])->assertOk()->json('id');
        $this->saleReturn([$this->line(2)], ['sale_id' => $saleId])->assertOk();
        $this->assertEquals(50, $this->shelf());

        $this->deleteJson("/api/sales/{$saleId}");

        $this->assertEquals(50, $this->shelf(), 'sell 2, take 2 back, delete the sale: still 50, never 52');
    }

    /**
     * K5 — voiding a sale after part of it was returned restocks the returned units twice.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_voiding_a_sale_after_its_return_does_not_restock_the_returned_units_again()
    {
        $this->as($this->owner);
        $saleId = $this->sale([$this->line(3)])->assertOk()->json('id');
        $this->saleReturn([$this->line(1)], ['sale_id' => $saleId])->assertOk();
        $this->assertEquals(48, $this->shelf());

        $void = $this->postJson("/api/sales/{$saleId}/void", ['reason' => 'after a partial return']);

        if ($void->isSuccessful()) {
            $this->assertEquals(50, $this->shelf(), 'sell 3, take 1 back, void: the 2 still out come back, not 3');
        } else {
            $this->assertEquals(48, $this->shelf(), 'a refused void moves nothing');
        }
    }
}
