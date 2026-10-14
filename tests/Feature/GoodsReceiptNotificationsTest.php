<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\NotificationFixtures;
use Tests\TestCase;

/** Booking in a delivery (POST /api/goods_receipts) tells the buyer it arrived. */
class GoodsReceiptNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    private function receive($purchase, $qty)
    {
        $line = DB::table('purchase_details')->where('purchase_id', $purchase->id)->first();

        $this->actingAs($this->stockman, 'api')->postJson('/api/goods_receipts', [
            'purchase_id' => $purchase->id, 'date' => now()->toDateString(),
            'details' => [['purchase_detail_id' => $line->id, 'received_qte' => $qty, 'rejected_qte' => 0]],
        ])->assertSuccessful();
    }

    /** @test */
    public function a_full_delivery_tells_the_buyer_everything_arrived()
    {
        $purchase = $this->raisePurchase(58);

        $this->receive($purchase, 20);

        $n = $this->bellOf($this->purchaser)->first(fn ($n) => str_contains($n['title'], $purchase->Ref . ' delivery booked in'));
        $this->assertNotNull($n);
        $this->assertStringContainsString('Everything ordered has arrived', $n['body']);
        $this->assertFalse($this->seesMention($this->stockman, 'delivery booked in'));
    }

    /** @test */
    public function a_part_delivery_says_what_is_still_outstanding()
    {
        $purchase = $this->raisePurchase(58);

        $this->receive($purchase, 8);

        $n = $this->bellOf($this->purchaser)->first(fn ($n) => str_contains($n['title'], 'delivery booked in'));
        $this->assertStringContainsString('1 line(s) still outstanding', $n['body']);
    }
}
