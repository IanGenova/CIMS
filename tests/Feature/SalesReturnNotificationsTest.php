<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\NotificationFixtures;
use Tests\TestCase;

/** A sale return (POST /api/returns/sale) tells the managers. */
class SalesReturnNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    /** @test */
    public function a_return_tells_the_managers_and_not_the_cashier()
    {
        // A return names the sale it comes from (DATATEST K5).
        $saleId = \Illuminate\Support\Facades\DB::table('sales')->insertGetId([
            'user_id' => $this->cashier->id, 'date' => now()->toDateString(), 'Ref' => 'SL_1', 'client_id' => $this->clientId,
            'warehouse_id' => $this->warehouseId, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 75.4, 'paid_amount' => 75.4, 'payment_statut' => 'paid', 'statut' => 'completed',
        ]);
        \Illuminate\Support\Facades\DB::table('sale_details')->insert([
            'date' => now()->toDateString(), 'sale_id' => $saleId, 'product_id' => $this->productId, 'price' => 75.4,
            'sale_unit_id' => $this->unitId, 'TaxNet' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_method' => '2',
            'total' => 75.4, 'quantity' => 1,
        ]);

        $this->actingAs($this->cashier, 'api')->postJson('/api/returns/sale', [
            'date' => now()->toDateString(), 'client_id' => $this->clientId, 'warehouse_id' => $this->warehouseId,
            'sale_id' => $saleId,
            'statut' => 'received', 'notes' => '', 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'details' => [[
                'product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 1,
                'sale_unit_id' => $this->unitId, 'Unit_price' => 75.4, 'tax_percent' => 0,
                'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            ]],
        ])->assertSuccessful();

        $n = $this->bellOf($this->manager)->first(fn ($n) => str_starts_with($n['title'], 'Return '));
        $this->assertNotNull($n);
        $this->assertStringContainsString('75.40', $n['body']);
        $this->assertFalse($this->seesMention($this->cashier, 'Return '));
    }
}
