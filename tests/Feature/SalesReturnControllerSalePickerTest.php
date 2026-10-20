<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BranchStaff;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * The return screen picks the sale a return comes from (DATATEST K5).
 *
 * A customer return now has to name its sale, so the screen lists the
 * customer's completed sales to choose from: newest first, no voided, pending
 * or deleted ones, and no other customer's.
 */
class SalesReturnControllerSalePickerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;
    use BranchStaff;

    /** @test */
    public function the_picker_lists_only_this_customers_completed_sales_newest_first()
    {
        $user = $this->actAsUserWith(['Sale_Returns_add']);
        $wh = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $juan = DB::table('clients')->insertGetId(['name' => 'Juan', 'code' => 1]);
        $maria = DB::table('clients')->insertGetId(['name' => 'Maria', 'code' => 2]);
        $sale = function ($ref, $client, $statut, $date, $deleted = null) use ($wh, $user) {
            return DB::table('sales')->insertGetId(['user_id' => $user->id, 'date' => $date, 'Ref' => $ref, 'client_id' => $client,
                'warehouse_id' => $wh, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 100,
                'paid_amount' => 100, 'payment_statut' => 'paid', 'statut' => $statut, 'deleted_at' => $deleted]);
        };
        $sale('SL_1', $juan, 'completed', '2026-10-01');
        $sale('SL_2', $juan, 'completed', '2026-10-05');
        $sale('SL_3', $juan, 'voided', '2026-10-05');
        $sale('SL_4', $juan, 'pending', '2026-10-05');
        $sale('SL_5', $juan, 'completed', '2026-10-05', now());
        $sale('SL_6', $maria, 'completed', '2026-10-05');

        $refs = collect($this->getJson("/api/returns/sale/for_client?client_id={$juan}")->assertOk()->json('sales'))->pluck('Ref')->all();

        $this->assertSame(['SL_2', 'SL_1'], $refs);
    }

    /** @test */
    public function branch_staff_cannot_select_another_branch_sale_for_a_return()
    {
        $this->setUpBranches();
        foreach ([[$this->main, 'SL-MAIN'], [$this->branch, 'SL-BRANCH']] as [$warehouse, $ref]) {
            DB::table('sales')->insert(['user_id' => $this->author, 'date' => now()->toDateString(),
                'Ref' => $ref, 'client_id' => $this->client, 'warehouse_id' => $warehouse,
                'GrandTotal' => 100, 'paid_amount' => 100, 'payment_statut' => 'paid',
                'statut' => 'completed']);
        }

        $staff = $this->staffOn(['Sale_Returns_add'], [$this->main]);
        $refs = collect($this->actingAs($staff, 'api')
            ->getJson("/api/returns/sale/for_client?client_id={$this->client}")
            ->assertOk()->json('sales'))->pluck('Ref')->all();

        $this->assertSame(['SL-MAIN'], $refs);
    }
}
