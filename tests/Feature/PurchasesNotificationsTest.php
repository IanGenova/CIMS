<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\NotificationFixtures;
use Tests\TestCase;

/**
 * Raising a purchase (POST /api/purchases) tells the right people at once:
 * over the approval line, the approvers; under it, the stockroom that it is
 * coming. The buyer is never told about their own order.
 */
class PurchasesNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    /** @test */
    public function a_purchase_over_the_line_asks_the_approvers_and_not_the_buyer()
    {
        $purchase = $this->raisePurchase(600); // 20 x 600 = 12,000
        $this->assertSame('pending', $purchase->approval_status);

        $this->assertTrue($this->seesMention($this->approver, $purchase->Ref . ' needs approval'));
        $this->assertFalse($this->seesMention($this->purchaser, $purchase->Ref));
        $this->assertFalse($this->seesMention($this->stockman, $purchase->Ref));
    }

    /** @test */
    public function a_small_order_tells_the_stockroom_it_is_coming()
    {
        $purchase = $this->raisePurchase(58); // 1,160
        $this->assertSame('not_required', $purchase->approval_status);

        $this->assertTrue($this->seesMention($this->stockman, $purchase->Ref . ' is on order'));
        $this->assertFalse($this->seesMention($this->approver, $purchase->Ref));
    }
}
