<?php

namespace Tests\Feature;

use App\utils\ProductSalesForecast;
use App\utils\WarehouseAccess;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

class ProductSalesAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WarehouseAccess::forget();
        parent::tearDown();
    }

    public function test_linear_fit_and_low_data_signal(): void
    {
        $fit = ProductSalesForecast::fit([1, 2, 3, 4], 2);
        $this->assertSame([5.0, 6.0], $fit['forecast']);
        $this->assertEqualsWithDelta(1, $fit['slope'], 0.001);
        $this->assertEqualsWithDelta(1, $fit['r_squared'], 0.001);

        $falling = ProductSalesForecast::fit([3, 2, 1, 0], 4);
        $this->assertSame([0.0, 0.0, 0.0, 0.0], $falling['forecast']);

        $limited = ProductSalesForecast::analyze([0, 0, 10, 0], [0, 0, 100, 0], 2);
        $this->assertSame('limited', $limited['trend']);
        $this->assertSame(1, $limited['active_weeks']);
    }

    public function test_report_forecasts_completed_sales_and_excludes_current_week_and_voids(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00'));
        $user = $this->actAsUserWith(['Reports_sales', 'record_view']);
        [$warehouse, $client, $unit, $products] = $this->fixtures();
        [$fast, $slow] = $products;

        foreach ([1, 2, 3, 4] as $week => $quantity) {
            $date = Carbon::today()->subWeeks(4 - $week)->addDay()->toDateString();
            $sale = $this->sale($user->id, $warehouse, $client, $date, 'completed');
            // The final week's invoice is in a currency worth half as much
            // as base; its converted sales value is still 400.
            if ($week === 3) {
                DB::table('sales')->where('id', $sale)->update(['exchange_rate' => 2]);
            }
            $this->line($sale, $fast, $unit, $quantity, $quantity * ($week === 3 ? 200 : 100));
            $this->line($sale, $slow, $unit, 8, 80);
        }
        $oldDate = '2026-10-14';
        foreach (['voided', 'pending'] as $status) {
            $sale = $this->sale($user->id, $warehouse, $client, $oldDate, $status);
            $this->line($sale, $fast, $unit, 1000, 100000);
        }
        $sale = $this->sale($user->id, $warehouse, $client, '2026-10-19', 'completed');
        $this->line($sale, $fast, $unit, 1000, 100000);

        $response = $this->getJson('/api/report/product-sales-analytics?history_weeks=4&horizon_weeks=2')
            ->assertOk();
        $this->assertSame('2026-10-18', $response->json('period.history_through'));
        $this->assertSame($fast, $response->json('top.0.product_id'));
        $this->assertSame($slow, $response->json('bottom.0.product_id'));
        $this->assertSame($fast, $response->json('trending.0.product_id'));
        $fastRow = collect($response->json('products'))->firstWhere('product_id', $fast);
        $this->assertSame([1, 2, 3, 4], array_map('intval', $fastRow['weekly_units']));
        $this->assertEqualsWithDelta(11, $fastRow['predicted_units'], 0.01);
        $this->assertEqualsWithDelta(1100, $fastRow['predicted_revenue'], 0.01);
    }

    public function test_report_respects_warehouse_scope_and_sales_report_permission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00'));
        $user = $this->actAsUserWith(['Reports_sales', 'record_view']);
        [$warehouse, $client, $unit, $products] = $this->fixtures();
        $otherWarehouse = DB::table('warehouses')->insertGetId(['name' => 'Other Branch']);
        DB::table('users')->where('id', $user->id)->update(['all_warehouses' => 0]);
        DB::table('user_warehouse')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse]);
        WarehouseAccess::forget();
        $date = '2026-10-12';
        $sale = $this->sale($user->id, $warehouse, $client, $date, 'completed');
        $this->line($sale, $products[0], $unit, 2, 20);
        $box = DB::table('units')->insertGetId(['name' => 'Box', 'ShortName' => 'box', 'operator' => '*', 'operator_value' => 10]);
        $this->line($sale, $products[0], $box, 1, 100);
        $other = $this->sale($user->id, $otherWarehouse, $client, $date, 'completed');
        $this->line($other, $products[1], $unit, 100, 1000);

        $visible = $this->getJson('/api/report/product-sales-analytics?history_weeks=4')->assertOk()
            ->assertJsonCount(1, 'products')->assertJsonCount(1, 'warehouses');
        $visible->assertJsonCount(0, 'top')->assertJsonCount(0, 'bottom');
        $visible->assertJsonCount(1, 'historical_top')->assertJsonCount(1, 'historical_bottom');
        $this->assertEqualsWithDelta(120, $visible->json('historical_top.0.observed_revenue'), 0.01);
        $this->assertSame(0, $visible->json('summary.forecastable_products'));
        $this->assertFalse($visible->json('scope.own_sales_only'));
        $this->assertEqualsWithDelta(12, $visible->json('products.0.observed_units'), 0.01);
        $this->getJson('/api/report/product-sales-analytics?warehouse_id=' . $otherWarehouse)->assertForbidden();

        DB::table('permission_role')->where('role_id', $user->roles()->first()->id)
            ->where('permission_id', DB::table('permissions')->where('name', 'record_view')->value('id'))->delete();
        $this->assertTrue($this->getJson('/api/report/product-sales-analytics')->assertOk()->json('scope.own_sales_only'));

        DB::table('permission_role')->where('role_id', $user->roles()->first()->id)
            ->where('permission_id', DB::table('permissions')->where('name', 'Reports_sales')->value('id'))->delete();
        $this->getJson('/api/report/product-sales-analytics')->assertForbidden();
    }

    private function fixtures(): array
    {
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $client = DB::table('clients')->insertGetId([
            'name' => 'Walk-in', 'code' => 1, 'email' => 'walkin@example.invalid',
            'country' => 'Philippines', 'city' => 'Iloilo', 'phone' => '0917', 'adresse' => 'Main',
        ]);
        $unit = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'AN', 'name' => 'Analytics']);
        $products = [];
        foreach (['Fast', 'Slow'] as $name) {
            $products[] = DB::table('products')->insertGetId([
                'code' => 'AN-' . $name, 'Type_barcode' => 'CODE128', 'name' => $name,
                'cost' => 5, 'price' => 10, 'category_id' => $category,
                'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            ]);
        }
        return [$warehouse, $client, $unit, $products];
    }

    private function sale(int $user, int $warehouse, int $client, string $date, string $status): int
    {
        return DB::table('sales')->insertGetId([
            'user_id' => $user, 'date' => $date, 'Ref' => uniqid('AN-'),
            'client_id' => $client, 'warehouse_id' => $warehouse,
            'GrandTotal' => 100, 'payment_statut' => 'paid', 'statut' => $status,
        ]);
    }

    private function line(int $sale, int $product, int $unit, float $quantity, float $total): void
    {
        DB::table('sale_details')->insert([
            'date' => DB::table('sales')->where('id', $sale)->value('date'),
            'sale_id' => $sale, 'product_id' => $product, 'sale_unit_id' => $unit,
            'quantity' => $quantity, 'price' => $total / $quantity, 'total' => $total,
        ]);
    }
}
