<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\NotificationFixtures;
use Tests\TestCase;

/** A stock adjustment (POST /api/adjustments) tells the managers. */
class AdjustmentNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    /** @test */
    public function a_write_off_tells_the_managers_and_not_the_stockman()
    {
        $this->actingAs($this->stockman, 'api')->postJson('/api/adjustments', [
            'warehouse_id' => $this->warehouseId, 'date' => now()->toDateString(), 'notes' => 'Bag split',
            'details' => [['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 1, 'type' => 'sub']],
        ])->assertSuccessful();

        $n = $this->bellOf($this->manager)->first(fn ($n) => str_contains($n['title'], 'Stock adjusted'));
        $this->assertNotNull($n);
        $this->assertStringContainsString('1 line(s) taken off at Main Warehouse', $n['body']);
        $this->assertSame('warning', $n['level']);
        $this->assertFalse($this->seesMention($this->stockman, 'Stock adjusted'));
        $this->assertFalse($this->seesMention($this->cashier, 'Stock adjusted'));
    }
}
