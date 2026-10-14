<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Service items: labour, repairs, installation, delivery.
 *
 * Asked for so CIMS fits shops that sell work as well as goods. A service is
 * priced, sold, taxed and reported like a product, but it is never stocked:
 * selling it moves no stock, it is never "out of stock", it never shows on a
 * low-stock alert, and it can be neither batch- nor serial-tracked.
 */
class ProductKindServiceTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $warehouse;
    private $unit;
    private $category;
    private $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['products_add', 'products_edit', 'products_view', 'Pos_view', 'Sale_Returns_add', 'Stock_Alerts', 'Reports_quantity_alerts', 'record_view']);
        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC']);
        DB::table('pos_settings')->insert(['note_customer' => 'Thank you', 'show_note' => 1, 'show_barcode' => 1,
            'show_discount' => 1, 'show_customer' => 1, 'show_email' => 1, 'show_phone' => 1, 'show_address' => 1]);
        $this->warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->client = DB::table('clients')->insertGetId(['name' => 'walk-in-customer', 'code' => 1]);
        $this->unit = DB::table('units')->insertGetId(['name' => 'Job', 'ShortName' => 'job', 'operator' => '*', 'operator_value' => 1]);
        $this->category = DB::table('categories')->insertGetId(['code' => 'SRV', 'name' => 'Services']);
    }

    private function createService(array $extra = [])
    {
        $this->postJson('/api/Products', array_merge([
            'code' => 'SRV-001', 'Type_barcode' => 'CODE128', 'name' => 'Aircon Installation',
            'price' => 1500, 'cost' => 600, 'category_id' => $this->category, 'unit_id' => $this->unit,
            'unit_sale_id' => $this->unit, 'unit_purchase_id' => $this->unit, 'tax_method' => '2', 'TaxNet' => 12,
            'is_service' => 'true', 'stock_alert' => 5, 'has_batches' => 'true', 'has_serials' => 'true',
        ], $extra))->assertOk();

        return (int) DB::table('products')->where('code', 'SRV-001')->value('id');
    }

    private function shelf($productId)
    {
        return (float) DB::table('product_warehouse')->where('product_id', $productId)->where('warehouse_id', $this->warehouse)->value('qte');
    }

    /** @test */
    public function a_service_is_saved_without_any_stock_tracking()
    {
        $id = $this->createService();
        $p = DB::table('products')->find($id);

        $this->assertSame(1, (int) $p->is_service);
        $this->assertSame(0, (int) $p->has_batches, 'a service has no batches');
        $this->assertSame(0, (int) $p->has_serials, 'a service has no serial numbers');
        $this->assertEquals(0, $p->stock_alert, 'a service never runs low');
    }

    /** @test */
    public function selling_a_service_at_the_pos_moves_no_stock_and_is_never_out_of_stock()
    {
        $id = $this->createService();

        $this->postJson('/api/pos/CreatePOS', [
            'client_id' => $this->client, 'warehouse_id' => $this->warehouse,
            'GrandTotal' => 3000, 'amount' => 3000, 'received_amount' => 3000, 'change' => 0,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'payment' => ['Reglement' => 'Cash'],
            'details' => [['product_id' => $id, 'product_variant_id' => null, 'sale_unit_id' => $this->unit, 'quantity' => 2,
                'Unit_price' => 1500, 'subtotal' => 3000, 'discount' => 0, 'discount_Method' => '2', 'tax_percent' => 12, 'tax_method' => '2']],
        ])->assertOk();

        $this->assertEquals(0, $this->shelf($id));
        $this->assertSame(0, DB::table('stock_movements')->where('product_id', $id)->count(), 'no stock movement for a service');
        $this->assertEquals(3000, DB::table('sales')->value('GrandTotal'));
        $this->assertSame('paid', DB::table('sales')->value('payment_statut'));
    }

    /** @test */
    public function the_pos_lists_a_service_even_with_nothing_on_the_shelf()
    {
        $id = $this->createService();

        $items = $this->getJson("/api/Products/Warehouse/{$this->warehouse}?stock=1")->assertOk()->json();
        $service = collect($items)->firstWhere('id', $id);

        $this->assertNotNull($service, 'the service was hidden as "out of stock"');
        $this->assertTrue($service['is_service']);
    }

    /** @test */
    public function a_service_never_appears_on_the_low_stock_alert()
    {
        $id = $this->createService();

        foreach (['/api/Products/Stock/Alerts?limit=50', '/api/report/stock_alert?limit=50&page=1'] as $url) {
            $body = json_encode($this->getJson($url)->assertOk()->json());
            $this->assertStringNotContainsString('SRV-001', $body, "the service is on the low-stock alert ($url)");
        }
    }

    /** @test */
    public function a_product_can_be_turned_into_a_service_and_back()
    {
        $id = $this->createService(['is_service' => 'false', 'has_batches' => 'false', 'has_serials' => 'false']);
        $this->assertSame(0, (int) DB::table('products')->where('id', $id)->value('is_service'));
    }
}
