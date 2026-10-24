<?php

namespace Tests\Feature;

use App\utils\WarehouseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

class TraceabilityNetworkTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function network_dashboard_and_exception_drilldown_use_the_same_location_data()
    {
        $user = $this->actAsUserWith(['products_view', 'Reports_sales']);
        $central = DB::table('warehouses')->insertGetId([
            'name' => 'Central', 'code' => 'CEN', 'site_type' => 'central',
        ]);
        $branch = DB::table('warehouses')->insertGetId([
            'name' => 'Branch', 'code' => 'BR1', 'site_type' => 'branch',
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'NET', 'name' => 'Network']);
        $client = DB::table('clients')->insertGetId([
            'name' => 'Test customer', 'code' => 101, 'email' => 'network@example.invalid',
            'country' => 'PH', 'city' => 'Manila', 'phone' => '0917', 'adresse' => 'Test',
        ]);
        $product = DB::table('products')->insertGetId([
            'code' => 'NETWORK-1', 'Type_barcode' => 'CODE128', 'name' => 'Test item',
            'cost' => 10, 'price' => 20, 'category_id' => $category, 'stock_alert' => 5,
        ]);
        DB::table('product_warehouse')->insert([
            ['product_id' => $product, 'warehouse_id' => $central, 'qte' => 3],
            ['product_id' => $product, 'warehouse_id' => $branch, 'qte' => 9],
        ]);
        $sale = DB::table('sales')->insertGetId([
            'user_id' => $user->id, 'date' => now()->toDateString(), 'Ref' => 'SL-NETWORK-1',
            'client_id' => $client, 'warehouse_id' => $branch, 'GrandTotal' => 75,
            'payment_statut' => 'paid', 'statut' => 'completed',
        ]);
        $tracked = DB::table('products')->insertGetId([
            'code' => 'NETWORK-LOT', 'Type_barcode' => 'CODE128', 'name' => 'Tracked item',
            'cost' => 10, 'price' => 20, 'category_id' => $category, 'has_batches' => 1,
        ]);
        DB::table('product_warehouse')->insert([
            'product_id' => $tracked, 'warehouse_id' => $branch, 'qte' => 2,
        ]);
        $lot = DB::table('product_batches')->insertGetId([
            'product_id' => $tracked, 'batch_number' => 'HISTORICAL-LOT',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        DB::table('batch_stock')->insert([
            'product_batch_id' => $lot, 'warehouse_id' => $branch, 'qte' => 2,
        ]);
        DB::table('batch_movements')->insert([
            'product_batch_id' => $lot, 'warehouse_id' => $branch, 'qte' => 3,
            'movement_type' => 'opening',
        ]);
        DB::table('batch_movements')->insert([
            'product_batch_id' => $lot, 'warehouse_id' => $branch, 'qte' => -1,
            'movement_type' => 'sale', 'reference_type' => 'Sale', 'reference_id' => $sale,
            'user_id' => $user->id,
        ]);
        $driftLot = DB::table('product_batches')->insertGetId([
            'product_id' => $tracked, 'batch_number' => 'DRIFT-LOT',
        ]);
        DB::table('batch_stock')->insert([
            'product_batch_id' => $driftLot, 'warehouse_id' => $central, 'qte' => 1,
        ]);

        $overview = $this->getJson('/api/traceability/overview')->assertOk()->json('locations');
        $centralRow = collect($overview)->firstWhere('id', $central);
        $branchRow = collect($overview)->firstWhere('id', $branch);
        $this->assertEquals(3, $centralRow['on_hand']);
        $this->assertEquals(1, $centralRow['low_stock_products']);
        $this->assertEquals(0, $branchRow['low_stock_products']);
        $this->assertEquals(1, $branchRow['sales_30d_count']);
        $this->assertEquals(75, $branchRow['sales_30d_total']);
        $this->getJson('/api/traceability/overview')->assertOk()
            ->assertJsonPath('exceptions.lot_balance_mismatches', 1);

        $this->getJson('/api/traceability/exceptions?type=low_stock&warehouse_id=' . $central)
            ->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.product_code', 'NETWORK-1');
        $this->getJson('/api/traceability/exceptions?type=low_stock&warehouse_id=' . $branch)
            ->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson('/api/traceability/exceptions?type=expired_lots_with_stock')
            ->assertOk()->assertJsonPath('rows.0.id', $lot);
        $this->getJson('/api/traceability/exceptions?type=source_unknown_lots')
            ->assertOk()->assertJsonPath('rows.0.id', $driftLot);
        $this->getJson('/api/traceability/exceptions?type=lot_balance_mismatches')
            ->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.id', $driftLot);
        $this->getJson('/api/traceability/search?query=SL-NETWORK-1')
            ->assertOk()->assertJsonPath('batches.0.id', $lot);
        $this->getJson('/api/batches/' . $lot . '/trace')
            ->assertOk()->assertJsonPath('reconciliation.in_sync', true)
            ->assertJsonPath('movements.1.customer', 'Test customer');
    }

    /** @test */
    public function branch_only_user_cannot_read_the_network_dashboard_or_exceptions()
    {
        $user = $this->actAsUserWith(['products_view']);
        DB::table('users')->where('id', $user->id)->update(['all_warehouses' => 0]);
        WarehouseAccess::forget();

        $this->getJson('/api/traceability/overview')->assertForbidden();
        $this->getJson('/api/traceability/exceptions?type=low_stock')->assertForbidden();
    }

    /** @test */
    public function sales_metrics_are_hidden_without_sales_report_permission()
    {
        $this->actAsUserWith(['products_view']);
        DB::table('warehouses')->insert([
            'name' => 'Central', 'code' => 'CEN', 'site_type' => 'central',
        ]);

        $this->getJson('/api/traceability/overview')->assertOk()
            ->assertJsonPath('sales_visible', false)
            ->assertJsonPath('locations.0.sales_30d_count', null)
            ->assertJsonPath('locations.0.sales_30d_total', null);
    }
}
