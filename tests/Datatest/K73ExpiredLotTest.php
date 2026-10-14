<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K73: an already-expired lot can be added to stock through an adjustment.
 * Default applied: adding an already-expired lot is refused.
 */
class K73ExpiredLotTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K73 — a lot that expired in 2020 is added as sellable stock.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_adjustment_refuses_an_expired_lot()
    {
        $sealant = DB::table('products')->insertGetId([
            'code' => 'HW-0200', 'Type_barcode' => 'CODE128', 'name' => 'Silicone Sealant 300ml', 'cost' => 120, 'price' => 160,
            'category_id' => DB::table('categories')->value('id'), 'unit_id' => $this->bag, 'unit_sale_id' => $this->bag,
            'unit_purchase_id' => $this->bag, 'TaxNet' => 0, 'tax_method' => '2', 'has_batches' => 1,
        ]);
        DB::table('product_warehouse')->insert(['product_id' => $sealant, 'warehouse_id' => $this->main, 'qte' => 0]);

        $response = $this->as($this->owner)->adjust([$this->adjLine(2, 'add', ['product_id' => $sealant,
            'batch_number' => 'SS-EXP', 'expiry_date' => '2020-01-01'])]);

        $this->assertRefused($response, 'the lot expired in 2020');
        $this->assertEquals(0, (float) DB::table('batch_stock')->sum('qte'), 'no expired lot is put on the shelf');
        $this->assertEquals(0, $this->shelf($this->main, $sealant));
    }
}
