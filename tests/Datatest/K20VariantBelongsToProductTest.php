<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K20: product_variant_id is not validated to belong to the product, nor
 * required for a variant product.
 *
 * Fixture: "Latex Paint 4L" in White and Red (20 White at Main), and "Primer
 * 4L" in Gray -- Gray is not a colour of the paint.
 */
class K20VariantBelongsToProductTest extends DatatestCase
{
    use W1Helpers;

    private $paint;
    private $white;
    private $gray;

    protected function setUp(): void
    {
        parent::setUp();
        $category = DB::table('categories')->value('id');
        $make = function ($code, $name) use ($category) {
            return DB::table('products')->insertGetId(['code' => $code, 'Type_barcode' => 'CODE128', 'name' => $name,
                'cost' => 600, 'price' => 750, 'category_id' => $category, 'unit_id' => $this->bag, 'unit_sale_id' => $this->bag,
                'unit_purchase_id' => $this->bag, 'TaxNet' => 0, 'tax_method' => '2', 'is_variant' => 1]);
        };
        $this->paint = $make('HW-0300', 'Latex Paint 4L');
        $primer = $make('HW-0301', 'Primer 4L');
        $this->white = DB::table('product_variants')->insertGetId(['product_id' => $this->paint, 'name' => 'White']);
        DB::table('product_variants')->insertGetId(['product_id' => $this->paint, 'name' => 'Red']);
        $this->gray = DB::table('product_variants')->insertGetId(['product_id' => $primer, 'name' => 'Gray']);
        DB::table('product_warehouse')->insert(['product_id' => $this->paint, 'warehouse_id' => $this->main,
            'product_variant_id' => $this->white, 'qte' => 20]);
    }

    private function white($warehouse = null)
    {
        return (float) DB::table('product_warehouse')->where('product_id', $this->paint)
            ->where('warehouse_id', $warehouse ?: $this->main)->where('product_variant_id', $this->white)->value('qte');
    }

    /**
     * K20 — a transfer of the paint in the primer's colour credits Branch from nowhere.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_transfer_refuses_a_variant_of_another_product()
    {
        $response = $this->as($this->owner)->transfer([$this->moveLine(5, ['product_id' => $this->paint, 'product_variant_id' => $this->gray])]);

        $this->assertRefused($response, 'Gray is not a variant of the paint');
        $this->assertSame(0, DB::table('product_warehouse')->where('product_id', $this->paint)
            ->where('product_variant_id', $this->gray)->count(), 'no stock row appears for a variant the paint does not have');
        $this->assertSame(0, DB::table('transfers')->count());
    }

    /**
     * K20 — a variant product is sold with no variant named, and some colour is taken.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_variant_product_must_name_its_variant_at_the_till()
    {
        $response = $this->as($this->owner)->pos([$this->line(1, 750, ['product_id' => $this->paint, 'product_variant_id' => null])]);

        $this->assertRefused($response, 'the till must know which colour was sold');
        $this->assertEquals(20, $this->white(), 'no White tin leaves on an unnamed sale');
        $this->assertSame(0, DB::table('sales')->count());
    }

    /**
     * K20 — an adjustment records a variant of another product (and moves nothing).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_adjustment_refuses_a_variant_of_another_product()
    {
        $response = $this->as($this->owner)->adjust([$this->adjLine(10, 'add', ['product_id' => $this->paint, 'product_variant_id' => $this->gray])]);

        $this->assertRefused($response, 'Gray is not a variant of the paint');
        $this->assertSame(0, DB::table('adjustment_details')->count(), 'no line is recorded for it');
    }
}
