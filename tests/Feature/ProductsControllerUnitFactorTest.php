<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Adding a product to a line tells the screen how many pieces its default
 * units hold, so a serial-tracked phone sold by the box of 2 asks for 2
 * serials per box -- the count the server checks against.
 */
class ProductsControllerUnitFactorTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function the_product_lookup_says_how_many_base_units_its_sale_and_purchase_units_hold()
    {
        $this->actAsUserWith(['products_view']);
        $piece = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $box = DB::table('units')->insertGetId(['name' => 'Box of 2', 'ShortName' => 'box', 'base_unit' => $piece,
            'operator' => '*', 'operator_value' => 2]);
        $category = DB::table('categories')->insertGetId(['code' => 'PH', 'name' => 'Phones']);
        $id = DB::table('products')->insertGetId([
            'code' => 'PH-1', 'Type_barcode' => 'CODE128', 'name' => 'Android Phone 128GB', 'cost' => 5000, 'price' => 7000,
            'category_id' => $category, 'unit_id' => $piece, 'unit_sale_id' => $piece, 'unit_purchase_id' => $box,
            'TaxNet' => 0, 'tax_method' => '2', 'has_serials' => 1,
        ]);

        $product = $this->getJson("/api/Products/{$id}")->assertOk()->json();

        $this->assertEquals(1, $product['sale_unit_factor']);
        $this->assertEquals(2, $product['purchase_unit_factor']);
    }
}
