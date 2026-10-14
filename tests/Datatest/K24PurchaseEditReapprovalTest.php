<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K24: purchase edit never recomputes approval_status, so an approved or
 * below-threshold order can be raised to any amount. Default applied: any
 * increase in a purchase's total after approval needs approval again.
 * Threshold: 10,000.
 */
class K24PurchaseEditReapprovalTest extends DatatestCase
{
    use W1Helpers;

    private function purchaser()
    {
        return $this->person(['Purchases_add', 'Purchases_edit', 'Purchases_view']);
    }

    /**
     * K24 — a 1,000 order edited to 100,000 stays "not_required".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_raising_an_order_over_the_threshold_puts_it_up_for_approval()
    {
        $this->as($this->purchaser());
        $id = $this->purchase([$this->buyLine(10, 100)], 'ordered');
        $this->assertSame('not_required', DB::table('purchases')->where('id', $id)->value('approval_status'));

        $this->editPurchase($id, [$this->buyLine(500, 200, ['id' => $this->purchaseDetailId($id)])], 'ordered')->assertOk();

        $this->assertEquals(100000, (float) DB::table('purchases')->where('id', $id)->value('GrandTotal'));
        $this->assertSame('pending', DB::table('purchases')->where('id', $id)->value('approval_status'),
            'a 100,000 order needs approval like any new one');
    }

    /**
     * K24 — an approved order raised tenfold stays approved and can be received in full.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_raising_an_approved_order_needs_approval_again()
    {
        $buyer = $this->purchaser();
        $this->as($buyer);
        $id = $this->purchase([$this->buyLine(50, 240)], 'ordered');       // 12,000
        $this->as($this->owner)->postJson("/api/purchase_approvals/{$id}/approve")->assertOk();

        $this->as($buyer)->editPurchase($id, [$this->buyLine(500, 240, ['id' => $this->purchaseDetailId($id)])], 'ordered')->assertOk();

        $this->assertSame('pending', DB::table('purchases')->where('id', $id)->value('approval_status'),
            'approval was given for 12,000, not 120,000');
        $this->as($this->owner)->receive($id, 500);
        $this->assertEquals(50, $this->shelf(), 'the unapproved increase cannot be received');
    }
}
