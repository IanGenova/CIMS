<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\NotificationFixtures;
use Tests\TestCase;

/** A transfer (POST /api/transfers) tells whoever handles transfers. */
class TransferNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    /** @test */
    public function a_transfer_tells_the_other_stockroom_staff()
    {
        $branchStaff = $this->userWith(['transfer_view']);

        $this->actingAs($this->stockman, 'api')->postJson('/api/transfers', [
            'transfer' => [
                'date' => now()->toDateString(), 'from_warehouse' => $this->warehouseId, 'to_warehouse' => $this->branchId,
                'statut' => 'completed', 'notes' => '', 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            ],
            'details' => [[
                'product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 4,
                'purchase_unit_id' => $this->unitId, 'Unit_cost' => 58, 'tax_percent' => 0,
                'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            ]],
        ])->assertSuccessful();

        $this->assertTrue($this->seesMention($branchStaff, 'Main Warehouse to Branch Warehouse'));
        $this->assertFalse($this->seesMention($this->stockman, 'Main Warehouse to Branch Warehouse'));
        $this->assertFalse($this->seesMention($this->cashier, 'Transfer'));
    }
}
