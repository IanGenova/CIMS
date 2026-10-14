<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W1Helpers;

/**
 * K52: a transfer saved as "sent" takes stock out of the source and puts it
 * nowhere. Owner decision: hold it in transit -- it leaves the source on
 * "sent", is shown as in transit, and arrives at the destination on
 * "completed". So the transfer's own ledger rows must tell the whole story:
 * one debit at the source when sent, one credit at the destination when it
 * arrives, both naming the transfer, and the source untouched by the arrival.
 */
class K52TransferInTransitTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K52 — completing a sent transfer re-debits the source and credits the destination with no reference.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sent_transfer_leaves_the_source_once_and_arrives_on_completion()
    {
        $this->as($this->owner);
        $start = $this->lastMovementId();
        $this->transfer([$this->moveLine(2)], 'sent')->assertOk();
        $id = $this->lastId('transfers');

        $this->assertEquals(48, $this->shelf($this->main), 'sent: the 2 bags have left Main');
        $this->assertEquals(20, $this->shelf($this->branch), 'sent: not yet at Branch');
        $sent = $this->movements($this->main, null, $start);
        $this->assertCount(1, $sent);
        $this->assertSame('Transfer', $sent[0]->reference_type);
        $this->assertEquals(-2, (float) $sent[0]->quantity);

        $arrival = $this->lastMovementId();
        $detail = \Illuminate\Support\Facades\DB::table('transfer_details')->where('transfer_id', $id)->value('id');
        $this->putJson("/api/transfers/{$id}", $this->transferBody([$this->moveLine(2, ['id' => $detail])], 'completed'))->assertOk();

        $this->assertEquals(48, $this->shelf($this->main));
        $this->assertEquals(22, $this->shelf($this->branch), 'completed: the 2 bags arrive at Branch');
        $this->assertCount(0, $this->movements($this->main, null, $arrival),
            'the arrival does not touch Main again: the bags left it once, when sent');
        $in = $this->movements($this->branch, null, $arrival);
        $this->assertCount(1, $in, 'one arrival at Branch');
        $this->assertEquals(2, (float) $in[0]->quantity);
        $this->assertSame('Transfer', $in[0]->reference_type, 'the arrival names the transfer');
        $this->assertEquals($id, $in[0]->reference_id);
    }
}
