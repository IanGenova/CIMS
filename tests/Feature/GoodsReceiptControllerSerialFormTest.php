<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * The Goods Receipt screen knows which lines need serial numbers, and how many.
 *
 * A serial-tracked line needs one serial per unit in the base unit. Phones
 * bought by the box of 2 need two serials for every box received, so the
 * screen is told how many units one purchase unit holds.
 */
class GoodsReceiptControllerSerialFormTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function each_outstanding_line_says_whether_it_needs_serials_and_how_many_per_unit()
    {
        $this->actAsUserWith(['goods_receipt_add', 'Purchases_add']);
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $supplier = DB::table('providers')->insertGetId(['name' => 'Phone Distributors', 'code' => 1,
            'email' => 'p@example.invalid', 'phone' => '0917', 'country' => 'PH', 'city' => 'QC', 'adresse' => 'x']);
        $piece = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $box = DB::table('units')->insertGetId(['name' => 'Box of 2', 'ShortName' => 'box', 'base_unit' => $piece,
            'operator' => '*', 'operator_value' => 2]);
        $category = DB::table('categories')->insertGetId(['code' => 'PH', 'name' => 'Phones']);
        $make = function ($code, $name, $serials) use ($piece, $box, $category) {
            return DB::table('products')->insertGetId([
                'code' => $code, 'Type_barcode' => 'CODE128', 'name' => $name, 'cost' => 5000, 'price' => 7000,
                'category_id' => $category, 'unit_id' => $piece, 'unit_sale_id' => $piece, 'unit_purchase_id' => $box,
                'TaxNet' => 0, 'tax_method' => '2', 'has_serials' => $serials,
            ]);
        };
        $phone = $make('PH-1', 'Android Phone 128GB', 1);
        $case = $make('PH-2', 'Phone Case', 0);
        $purchase = DB::table('purchases')->insertGetId([
            'user_id' => auth()->id(), 'Ref' => 'PR_1', 'date' => now()->toDateString(), 'provider_id' => $supplier,
            'warehouse_id' => $warehouse, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid', 'statut' => 'ordered',
        ]);
        foreach ([$phone, $case] as $p) {
            DB::table('purchase_details')->insert(['purchase_id' => $purchase, 'product_id' => $p, 'purchase_unit_id' => $box,
                'quantity' => 3, 'cost' => 10000, 'total' => 30000, 'TaxNet' => 0, 'tax_method' => '2', 'discount' => 0,
                'discount_method' => '2']);
        }

        $lines = collect($this->getJson("/api/goods_receipts/create?purchase_id={$purchase}")->assertOk()->json('details'))
            ->keyBy('product_id');

        $this->assertTrue($lines[$phone]['has_serials']);
        $this->assertEquals(2, $lines[$phone]['base_units_per']);
        $this->assertFalse($lines[$case]['has_serials']);
    }
}
