<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K71: a goods receipt accepts an all-zero receipt (an empty GRN) and more
 * rejected units than were ordered.
 */
class K71GoodsReceiptQuantitiesTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K71 — a receipt of nothing is saved as a GRN with no lines.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_empty_goods_receipt_is_refused()
    {
        $this->as($this->owner);
        $id = $this->purchase([$this->buyLine(10, 230)], 'ordered');

        $response = $this->receive($id, 0, 0);

        $this->assertRefused($response, 'a receipt must receive or reject something');
        $this->assertSame(0, DB::table('goods_receipts')->count(), 'no empty GRN is written');
    }

    /**
     * K71 — 5 received and 99 rejected on a line of 10 is accepted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_received_and_rejected_cannot_exceed_what_is_outstanding()
    {
        $this->as($this->owner);
        $id = $this->purchase([$this->buyLine(10, 230)], 'ordered');

        $response = $this->receive($id, 5, 99);

        $this->assertRefused($response, '5 + 99 is more than the 10 ordered');
        $this->assertSame(0, DB::table('goods_receipt_details')->count());
        $this->assertEquals(50, $this->shelf(), 'nothing is received on a refusal');
    }
}
