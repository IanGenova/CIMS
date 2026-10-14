<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * The Edit Product screen gets back every setting it saves.
 *
 * Found while adding service items: the edit payload left out batch tracking,
 * the expiry alert, the VAT classification and the SC/PWD flag. The screen
 * then sent its blank defaults back, so opening a batch-tracked medicine and
 * fixing its price quietly switched batch tracking off and made a VAT-exempt
 * item vatable. The new service / serial / warranty settings come back too.
 */
class ProductsControllerEditFieldsTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function the_edit_screen_receives_the_tracking_tax_and_kind_settings()
    {
        $this->actAsUserWith(['products_edit', 'products_view']);
        $unit = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'MED', 'name' => 'Medicine']);
        $id = DB::table('products')->insertGetId([
            'code' => 'MED-001', 'Type_barcode' => 'CODE128', 'name' => 'Amoxicillin 500mg', 'cost' => 5, 'price' => 9,
            'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2', 'has_batches' => 1, 'expiry_alert_days' => 90,
            'vat_class' => 'vat_exempt', 'sc_pwd_eligible' => 0, 'warranty_months' => null,
        ]);

        $product = $this->getJson("/api/Products/{$id}/edit")->assertOk()->json('product');

        $this->assertTrue($product['has_batches']);
        $this->assertEquals(90, $product['expiry_alert_days']);
        $this->assertEquals('vat_exempt', $product['vat_class']);
        $this->assertFalse($product['sc_pwd_eligible']);
        $this->assertFalse($product['is_service']);
        $this->assertFalse($product['has_serials']);
        $this->assertSame('', $product['warranty_months']);
    }
}
