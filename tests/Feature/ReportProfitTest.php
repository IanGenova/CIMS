<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Profit on the Dashboard and in Profit & Loss, after returns.
 *
 * Found on the live Dashboard: today's profit read -66.83 on a day with a
 * single return. A customer return took its price off revenue but left the
 * goods' cost in cost of goods sold, although the goods went back on the
 * shelf; and goods sent back to a supplier were added to profit as if they
 * were income. The Dashboard also ran its own older cost calculation. Both
 * screens now read one calculation (ProfitCalculator).
 *
 * Fixture, all today: 10 bags bought at 30; a completed sale of 2 at 50;
 * the customer returns 1; and a supplier return worth 30.
 *   revenue 100 - 50 = 50 · cost 2x30 - 1x30 = 30 · gross profit 20
 */
class ReportProfitTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->actAsUserWith(['Reports_profit', 'record_view']);
        $today = now()->toDateString();

        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $client = DB::table('clients')->insertGetId(['name' => 'walk-in-customer', 'code' => 1]);
        $supplier = DB::table('providers')->insertGetId(['name' => 'Luzon Builders Supply', 'code' => 1,
            'email' => 'l@example.invalid', 'phone' => '0917', 'country' => 'PH', 'city' => 'QC', 'adresse' => 'x']);
        $unit = DB::table('units')->insertGetId(['name' => 'Bag', 'ShortName' => 'bag', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'CEM', 'name' => 'Cement']);
        $product = DB::table('products')->insertGetId([
            'code' => 'HW-0004', 'Type_barcode' => 'CODE128', 'name' => 'White Cement 1kg',
            'cost' => 30, 'price' => 50, 'category_id' => $category,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
        ]);

        $purchase = DB::table('purchases')->insertGetId([
            'user_id' => $user->id, 'Ref' => 'PR_9001', 'date' => $today, 'provider_id' => $supplier,
            'warehouse_id' => $warehouse, 'GrandTotal' => 300, 'statut' => 'received', 'payment_statut' => 'paid',
        ]);
        DB::table('purchase_details')->insert(['purchase_id' => $purchase, 'product_id' => $product,
            'cost' => 30, 'quantity' => 10, 'total' => 300, 'purchase_unit_id' => $unit]);

        $sale = DB::table('sales')->insertGetId([
            'user_id' => $user->id, 'date' => $today, 'Ref' => 'SL_9001', 'client_id' => $client,
            'warehouse_id' => $warehouse, 'GrandTotal' => 100, 'paid_amount' => 100,
            'payment_statut' => 'paid', 'statut' => 'completed',
        ]);
        DB::table('sale_details')->insert(['date' => $today, 'sale_id' => $sale, 'product_id' => $product,
            'price' => 50, 'total' => 100, 'quantity' => 2, 'sale_unit_id' => $unit]);

        $return = DB::table('sale_returns')->insertGetId([
            'user_id' => $user->id, 'date' => $today, 'Ref' => 'RT_9001', 'client_id' => $client,
            'warehouse_id' => $warehouse, 'GrandTotal' => 50, 'payment_statut' => 'unpaid', 'statut' => 'received',
        ]);
        DB::table('sale_return_details')->insert(['sale_return_id' => $return, 'product_id' => $product,
            'price' => 50, 'total' => 50, 'quantity' => 1, 'sale_unit_id' => $unit]);

        DB::table('purchase_returns')->insert([
            'user_id' => $user->id, 'date' => $today, 'Ref' => 'RP_9001', 'provider_id' => $supplier,
            'warehouse_id' => $warehouse, 'GrandTotal' => 30, 'payment_statut' => 'unpaid', 'statut' => 'completed',
        ]);
    }

    /** @test */
    public function profit_and_loss_takes_the_returned_goods_out_of_cost()
    {
        $today = now()->toDateString();
        $data = $this->getJson("/api/report/ProfitAndLoss?from={$today}&to={$today}")->assertOk()->json('data');

        $this->assertEqualsWithDelta(50, $data['revenue'], 0.001);
        $this->assertEqualsWithDelta(30, $data['cost_of_goods_sold'], 0.001);
        $this->assertEqualsWithDelta(20, $data['profit'], 0.001, 'a supplier return is not income');
    }

    /** @test */
    public function the_dashboard_shows_the_same_profit()
    {
        $report = $this->getJson('/api/report/report_dashboard')->assertOk()->json('report');

        $this->assertEqualsWithDelta(50, $report['revenue'], 0.001);
        $this->assertEqualsWithDelta(20, $report['profit'], 0.001);
    }

    /** @test */
    public function a_branch_manager_sees_only_their_branchs_returns()
    {
        // Same fixture, but the manager is tied to a second branch: none of
        // the sales or returns are theirs, so their profit is nothing.
        $other = DB::table('warehouses')->insertGetId(['name' => 'Branch Warehouse']);
        $me = auth('api')->user();
        DB::table('users')->where('id', $me->id)->update(['all_warehouses' => 0]);
        DB::table('user_warehouse')->insert(['user_id' => $me->id, 'warehouse_id' => $other]);
        \App\utils\WarehouseAccess::forget();

        $report = $this->getJson('/api/report/report_dashboard')->assertOk()->json('report');

        $this->assertEqualsWithDelta(0, $report['revenue'], 0.001);
        $this->assertEqualsWithDelta(0, $report['profit'], 0.001);
    }

    /** @test */
    public function a_return_alone_is_not_a_loss_of_its_whole_price()
    {
        // No supplier return this time, so the two old errors cannot cancel.
        DB::table('purchase_returns')->delete();

        $report = $this->getJson('/api/report/report_dashboard')->assertOk()->json('report');

        $this->assertEqualsWithDelta(20, $report['profit'], 0.001);
    }
}
