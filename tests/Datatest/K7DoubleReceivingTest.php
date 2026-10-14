<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K7: statut='received' on a purchase and goods receipts are two receiving
 * mechanisms that do not know about each other (map L3: goods receipts receive
 * only what is outstanding; short-close stops further receipts).
 */
class K7DoubleReceivingTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K7 — a purchase saved "received" can be received again by a goods receipt.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_goods_bought_once_are_received_once()
    {
        $this->as($this->owner);
        $post = $this->postJson('/api/purchases', $this->purchaseBody([$this->buyLine(5, 230)], 'received'));
        if (! $post->isSuccessful()) {
            // A repair may refuse "received" on save and receive only by goods receipt.
            $this->assertRefused($post, 'a refused save is a 4xx');
            $this->assertEquals(50, $this->shelf());

            return;
        }
        $id = $this->lastId('purchases');
        $this->assertEquals(55, $this->shelf(), 'the "received" save brought the 5 bags in');

        $this->receive($id, 5);

        $this->assertEquals(55, $this->shelf(), '5 bags bought arrive once: 55 on the shelf, not 60');
        $this->assertEquals(5, DB::table('stock_movements')->where('product_id', $this->cement)
            ->where('warehouse_id', $this->main)->sum('quantity'), 'the ledger shows 5 bags in for 5 bought');
    }

    /**
     * K7 — deleting a short-closed purchase takes out every ordered unit, not the ones that arrived.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_a_short_closed_purchase_reverses_only_what_arrived()
    {
        $this->as($this->owner);
        $id = $this->purchase([$this->buyLine(10, 230)], 'ordered');
        $this->receive($id, 4)->assertOk();
        $this->assertEquals(54, $this->shelf());
        $this->postJson("/api/purchases/{$id}/close_short", ['reason' => 'Supplier ran out'])->assertOk();

        $delete = $this->deleteJson("/api/purchases/{$id}");

        if ($delete->isSuccessful()) {
            $this->assertEquals(50, $this->shelf(), 'only the 4 bags that arrived leave again, not the 10 ordered');
            $this->assertSame(0, DB::table('goods_receipts')->where('purchase_id', $id)
                ->whereNull('deleted_at')->where('status', 'received')->count(),
                'no live goods receipt is left pointing at a deleted purchase');
        } else {
            $this->assertRefused($delete, 'a refused delete is a 4xx');
            $this->assertEquals(54, $this->shelf(), 'a refused delete moves nothing');
        }
    }
}
