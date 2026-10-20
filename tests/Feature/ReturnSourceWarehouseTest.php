<?php

namespace Tests\Feature;

use App\utils\ReturnRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\BranchStaff;
use Tests\TestCase;

class ReturnSourceWarehouseTest extends TestCase
{
    use RefreshDatabase;
    use BranchStaff;

    /** @test */
    public function a_branch_cannot_link_a_customer_return_to_another_branch_sale()
    {
        $this->setUpBranches();
        $saleId = DB::table('sales')->insertGetId([
            'user_id' => $this->author, 'date' => now()->toDateString(), 'Ref' => 'SL-OTHER',
            'client_id' => $this->client, 'warehouse_id' => $this->branch,
            'GrandTotal' => 100, 'paid_amount' => 100, 'payment_statut' => 'paid',
            'statut' => 'completed',
        ]);
        $this->actingAs($this->staffOn(['Sale_Returns_add'], [$this->main]), 'api');

        try {
            ReturnRules::forSale($saleId, $this->client, []);
            $this->fail('A return was linked to a sale outside the user’s branch.');
        } catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }
    }

    /** @test */
    public function a_branch_cannot_link_a_supplier_return_to_another_branch_purchase()
    {
        $this->setUpBranches();
        $supplierId = DB::table('providers')->insertGetId(['name' => 'Supplier', 'code' => 9876]);
        $purchaseId = DB::table('purchases')->insertGetId([
            'user_id' => $this->author, 'date' => now()->toDateString(), 'Ref' => 'PO-OTHER',
            'provider_id' => $supplierId, 'warehouse_id' => $this->branch,
            'GrandTotal' => 100, 'paid_amount' => 0, 'payment_statut' => 'unpaid',
            'statut' => 'ordered',
        ]);
        $this->actingAs($this->staffOn(['Purchases_Returns_add'], [$this->main]), 'api');

        try {
            ReturnRules::forPurchase($purchaseId, $supplierId, []);
            $this->fail('A return was linked to a purchase outside the user’s branch.');
        } catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }
    }
}
