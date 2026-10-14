<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * A stock count cannot overwrite the quantity of a serial-tracked product.
 *
 * Completing a count writes the counted figure straight onto the shelf. For a
 * product whose units are named by serial number that would leave the shelf
 * saying 3 while the serial list says 2, with no way to tell which unit is
 * missing. A missing or found unit goes through an adjustment, which names it.
 */
class StockCountControllerSerialTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['stock_count_view', 'stock_count_add', 'stock_count_edit']);
        $this->warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
    }

    private function product($code, $name, array $extra = [])
    {
        $unit = DB::table('units')->insertGetId(['name' => 'Unit', 'ShortName' => 'unit', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'C' . $code, 'name' => 'Cat ' . $code]);
        $id = DB::table('products')->insertGetId(array_merge([
            'code' => $code, 'Type_barcode' => 'CODE128', 'name' => $name, 'cost' => 100, 'price' => 150,
            'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
        ], $extra));
        DB::table('product_warehouse')->insert(['product_id' => $id, 'warehouse_id' => $this->warehouse, 'qte' => 2]);

        return $id;
    }

    private function countWith($productId, $counted)
    {
        $id = DB::table('stock_counts')->insertGetId([
            'Ref' => 'SC_1', 'date' => now()->toDateString(), 'warehouse_id' => $this->warehouse,
            'user_id' => auth()->id() ?: 1, 'status' => 'in_progress',
        ]);
        DB::table('stock_count_details')->insert([
            'stock_count_id' => $id, 'product_id' => $productId, 'system_qte' => 2, 'counted_qte' => $counted,
        ]);

        return $id;
    }

    /** @test */
    public function a_count_that_includes_a_serial_tracked_product_is_refused_and_changes_nothing()
    {
        $aircon = $this->product('AC-1', 'Inverter Aircon 1.5HP', ['has_serials' => 1]);
        $count = $this->countWith($aircon, 3);

        $this->postJson("/api/stock_counts/{$count}/complete")
            ->assertStatus(422)
            ->assertJsonFragment(['Inverter Aircon 1.5HP has serial numbers, so it cannot go through a stock count. Record a missing or found unit with an adjustment, which names the serial number.']);

        $this->assertEquals(2, DB::table('product_warehouse')->where('product_id', $aircon)->value('qte'));
        $this->assertEquals('in_progress', DB::table('stock_counts')->where('id', $count)->value('status'));
    }

    /** @test */
    public function a_count_of_ordinary_products_still_completes()
    {
        $cement = $this->product('CEM-1', 'Portland Cement 40kg');
        $count = $this->countWith($cement, 5);

        $this->postJson("/api/stock_counts/{$count}/complete")->assertOk();

        $this->assertEquals(5, DB::table('product_warehouse')->where('product_id', $cement)->value('qte'));
    }
}
