<?php

namespace Tests\Feature;

use App\utils\ProductKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * A service is never out of stock on the product picker.
 *
 * Every sale screen caps a line at the stock on hand. A service has none, so
 * the list reports it as always available (ProductKind::SERVICE_STOCK) and
 * flags it, and the screens show "Service" instead of a quantity.
 */
class PosControllerServiceStockTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $warehouse;

    /** @test */
    public function a_service_is_listed_as_always_available()
    {
        $this->actAsUserWith(['products_view', 'Pos_view']);
        $this->warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $unit = DB::table('units')->insertGetId(['name' => 'Job', 'ShortName' => 'job', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'SRV', 'name' => 'Services']);
        $id = DB::table('products')->insertGetId([
            'code' => 'SRV-001', 'Type_barcode' => 'CODE128', 'name' => 'Aircon Installation', 'cost' => 600, 'price' => 1500,
            'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2', 'is_service' => 1,
        ]);
        DB::table('product_warehouse')->insert(['product_id' => $id, 'warehouse_id' => $this->warehouse, 'qte' => 0]);

        $items = $this->getJson("/api/GetProductsByParametre?warehouse_id={$this->warehouse}&stock=1")->assertOk()->json();
        $service = collect($items['products'] ?? $items)->firstWhere('id', $id);

        $this->assertTrue($service['is_service']);
        $this->assertEquals(ProductKind::SERVICE_STOCK, $service['qte_sale']);
        $this->assertEquals(ProductKind::SERVICE_STOCK, $service['qte']);
        $this->assertEquals(0, DB::table('product_warehouse')->where('product_id', $id)->value('qte'));
    }
}
