<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W1Helpers;

/**
 * K45: deleting a received purchase does not unwind the average cost it
 * caused. Default applied: deleting a received purchase is refused once its
 * stock has moved on; otherwise the cost is unwound.
 */
class K45PurchaseDeleteUnwindsCostTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K45 — the 300-a-bag receipt is deleted untouched and the average stays raised.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_an_untouched_received_purchase_restores_the_average_cost()
    {
        $this->as($this->owner);
        $id = $this->purchase([$this->buyLine(10, 300)], 'ordered');
        $this->receive($id, 10)->assertOk();
        $this->assertEquals(60, $this->shelf());
        $this->assertEqualsWithDelta(238.75, $this->cost(), 0.001, '(70 x 230 + 10 x 300) / 80');

        $this->deleteJson("/api/purchases/{$id}")->assertOk();

        $this->assertEquals(50, $this->shelf(), 'the 10 bags went back out');
        $this->assertEqualsWithDelta(230, $this->cost(), 0.001, 'nothing else moved, so the average returns to 230');
    }
}
