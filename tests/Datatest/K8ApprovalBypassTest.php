<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K8: purchase approval is not enforced when a purchase is stored or updated
 * with statut=received, and a rejection undoes nothing (map L3: above the
 * threshold it needs approval before it can be received). Threshold: 10,000.
 */
class K8ApprovalBypassTest extends DatatestCase
{
    use W1Helpers;

    private function purchaser()
    {
        return $this->person(['Purchases_add', 'Purchases_edit', 'Purchases_view']);
    }

    /**
     * K8 — a purchase over the threshold saved "received" moves stock and cost before approval.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_awaiting_approval_cannot_be_saved_as_received()
    {
        // 50 bags at 240 = 12,000, over the 10,000 threshold.
        $this->as($this->purchaser())->postJson('/api/purchases', $this->purchaseBody([$this->buyLine(50, 240)], 'received'));

        $this->assertEquals(50, $this->shelf(), 'no stock arrives before someone approves the order');
        $this->assertEqualsWithDelta(230, $this->cost(), 0.001, 'the average cost is untouched');
        $received = DB::table('purchases')->where('statut', 'received')->whereIn('approval_status', ['pending', 'rejected'])->count();
        $this->assertSame(0, $received, 'no purchase is both received and unapproved');
    }

    /**
     * K8 — a rejected purchase can be edited to "received" and its stock comes in.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_rejected_purchase_cannot_be_received_by_edit()
    {
        $buyer = $this->purchaser();
        $this->as($buyer);
        $id = $this->purchase([$this->buyLine(50, 240)], 'ordered');
        $this->assertSame('pending', DB::table('purchases')->where('id', $id)->value('approval_status'));
        $this->as($this->owner)->postJson("/api/purchase_approvals/{$id}/reject", ['reason' => 'Too much'])->assertOk();

        $response = $this->as($buyer)->editPurchase($id, [$this->buyLine(50, 240, ['id' => $this->purchaseDetailId($id)])], 'received');

        $this->assertRefused($response, 'a rejected purchase cannot be received');
        $this->assertEquals(50, $this->shelf(), 'no stock from a rejected order');
        $this->assertSame('ordered', DB::table('purchases')->where('id', $id)->value('statut'));
    }
}
