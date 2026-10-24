<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BranchStaff;
use Tests\TestCase;

/** The deliveries list (GET /api/deliveries) shows a branch only its own. */
class DeliveryWarehouseTest extends TestCase
{
    use RefreshDatabase;
    use BranchStaff;

    /** @test */
    public function a_branch_sees_only_its_own_deliveries()
    {
        $this->setUpBranches();
        foreach ([[$this->main, 'DLV-MAIN'], [$this->branch, 'DLV-BRANCH']] as [$w, $ref]) {
            $sale = DB::table('sales')->insertGetId(['user_id' => $this->author, 'date' => now()->toDateString(), 'Ref' => 'SL_' . $ref,
                'client_id' => $this->client, 'warehouse_id' => $w, 'GrandTotal' => 100, 'paid_amount' => 0,
                'payment_statut' => 'unpaid', 'statut' => 'completed']);
            DB::table('deliveries')->insert(['Ref' => $ref, 'sale_id' => $sale, 'client_id' => $this->client, 'warehouse_id' => $w,
                'date' => now()->toDateString(), 'status' => 'pending', 'user_id' => $this->author]);
        }
        $url = '/api/deliveries?page=1&limit=10&SortField=id&SortType=desc&search=';

        $this->assertSame(['DLV-MAIN'], $this->refsListed($this->staffOn(['Sales_view', 'record_view'], [$this->main]), $url, 'deliveries'));
        $this->assertSame(['DLV-BRANCH', 'DLV-MAIN'], $this->refsListed($this->staffOn(['Sales_view', 'record_view']), $url, 'deliveries'));
    }

    /** @test */
    public function a_branch_can_select_only_its_own_completed_sales_for_delivery()
    {
        $this->setUpBranches();
        foreach ([[$this->main, 'SL-MAIN'], [$this->branch, 'SL-BRANCH']] as [$warehouse, $ref]) {
            DB::table('sales')->insert(['user_id' => $this->author, 'date' => now()->toDateString(), 'Ref' => $ref,
                'client_id' => $this->client, 'warehouse_id' => $warehouse, 'GrandTotal' => 100,
                'paid_amount' => 0, 'payment_statut' => 'unpaid', 'statut' => 'completed']);
        }

        $url = '/api/deliveries/sales/undelivered';
        $mainStaff = $this->staffOn(['Sales_view', 'record_view'], [$this->main]);
        $this->assertSame(['SL-MAIN'], collect($this->actingAs($mainStaff, 'api')->getJson($url)
            ->assertOk()->json('sales'))->pluck('Ref')->all());
    }

    /** @test */
    public function a_sale_can_have_only_one_active_delivery()
    {
        $this->setUpBranches();
        $saleId = DB::table('sales')->insertGetId(['user_id' => $this->author,
            'date' => now()->toDateString(), 'Ref' => 'SL-ONE', 'client_id' => $this->client,
            'warehouse_id' => $this->main, 'GrandTotal' => 100, 'paid_amount' => 0,
            'payment_statut' => 'unpaid', 'statut' => 'completed']);
        $this->actingAs($this->staffOn(['Sales_add'], [$this->main]), 'api');
        $payload = ['sale_id' => $saleId, 'date' => now()->toDateString()];

        $this->postJson('/api/deliveries', $payload)->assertOk();
        $this->postJson('/api/deliveries', $payload)->assertStatus(422);
        $this->assertSame(1, DB::table('deliveries')->where('sale_id', $saleId)->count());
    }
}
