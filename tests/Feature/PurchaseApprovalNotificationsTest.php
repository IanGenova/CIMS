<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\NotificationFixtures;
use Tests\TestCase;

/**
 * Approving or rejecting a purchase (POST /api/purchase_approvals/{id}/...)
 * tells the buyer directly; an approval also tells the stockroom it is coming.
 */
class PurchaseApprovalNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    /** @test */
    public function approving_tells_the_buyer_and_the_stockroom()
    {
        $purchase = $this->raisePurchase(600);

        $this->actingAs($this->approver, 'api')->postJson("/api/purchase_approvals/{$purchase->id}/approve")->assertOk();

        $this->assertTrue($this->seesMention($this->purchaser, $purchase->Ref . ' was approved'));
        $this->assertTrue($this->seesMention($this->stockman, $purchase->Ref . ' is approved and on order'));
        $this->assertFalse($this->seesMention($this->approver, $purchase->Ref . ' was approved'));
    }

    /** @test */
    public function rejecting_tells_the_buyer_why()
    {
        $purchase = $this->raisePurchase(600);

        $this->actingAs($this->approver, 'api')
            ->postJson("/api/purchase_approvals/{$purchase->id}/reject", ['reason' => 'Too much cement for October'])
            ->assertOk();

        $mine = $this->bellOf($this->purchaser)->first(fn ($n) => str_contains($n['title'], $purchase->Ref . ' was rejected'));
        $this->assertNotNull($mine);
        $this->assertStringContainsString('Too much cement for October', $mine['body']);
        $this->assertSame('danger', $mine['level']);
        $this->assertFalse($this->seesMention($this->stockman, $purchase->Ref . ' was rejected'));
    }
}
