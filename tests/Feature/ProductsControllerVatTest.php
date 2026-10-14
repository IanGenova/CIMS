<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * The till is told each product's VAT class and SC/PWD eligibility.
 *
 * Found testing CIMS as a pharmacy: a Senior Citizen bought Losartan (a
 * VAT-exempt maintenance medicine), Ascorbic Acid and a shampoo sachet. The
 * server charged the lawful ₱131.57; the POS screen showed ₱119.28, because
 * the product it was handed carried neither field and it assumed "vatable,
 * eligible" -- stripping VAT the medicine never had and discounting the
 * shampoo. The customer paid the screen's figure and the sale was left
 * partial, ₱12.29 short.
 */
class ProductsControllerVatTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private function product($name, $vat, $eligible)
    {
        $unit = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'X', 'name' => 'Any']);

        return DB::table('products')->insertGetId(['code' => uniqid(), 'Type_barcode' => 'CODE128', 'name' => $name,
            'cost' => 5, 'price' => 12, 'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit,
            'unit_purchase_id' => $unit, 'TaxNet' => $vat === 'vat_exempt' ? 0 : 12, 'tax_method' => '2',
            'vat_class' => $vat, 'sc_pwd_eligible' => $eligible]);
    }

    /** @test */
    public function a_vat_exempt_medicine_says_so()
    {
        $this->actAsUserWith(['products_view']);
        $id = $this->product('Losartan 50mg Tablet', 'vat_exempt', 1);

        $this->getJson("/api/Products/{$id}")->assertOk()
            ->assertJson(['vat_class' => 'vat_exempt', 'sc_pwd_eligible' => true]);
    }

    /** @test */
    public function an_item_outside_the_sc_pwd_discount_says_so()
    {
        $this->actAsUserWith(['products_view']);
        $id = $this->product('Anti-Dandruff Shampoo Sachet', 'vatable', 0);

        $this->getJson("/api/Products/{$id}")->assertOk()
            ->assertJson(['vat_class' => 'vatable', 'sc_pwd_eligible' => false]);
    }
}
