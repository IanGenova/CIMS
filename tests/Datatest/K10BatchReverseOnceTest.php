<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K10: BatchService::reverse() has no once-only guard. Each call re-inverts
 * every non-reversal batch movement of the document, so a lot drifts on every
 * edit and delete (map S3).
 */
class K10BatchReverseOnceTest extends DatatestCase
{
    use W1Helpers;

    private $sealant;
    private $lot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sealant = DB::table('products')->insertGetId([
            'code' => 'HW-0200', 'Type_barcode' => 'CODE128', 'name' => 'Silicone Sealant 300ml', 'cost' => 120, 'price' => 160,
            'category_id' => DB::table('categories')->value('id'), 'unit_id' => $this->bag, 'unit_sale_id' => $this->bag,
            'unit_purchase_id' => $this->bag, 'TaxNet' => 0, 'tax_method' => '2', 'has_batches' => 1,
        ]);
        $this->lot = DB::table('product_batches')->insertGetId(['product_id' => $this->sealant, 'batch_number' => 'SS-2027-01',
            'expiry_date' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('batch_stock')->insert(['product_batch_id' => $this->lot, 'warehouse_id' => $this->main, 'qte' => 12,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_warehouse')->insert(['product_id' => $this->sealant, 'warehouse_id' => $this->main, 'qte' => 12]);
        \App\utils\BatchService::forget();
    }

    private function lotQty()
    {
        return (float) DB::table('batch_stock')->where('product_batch_id', $this->lot)->where('warehouse_id', $this->main)->value('qte');
    }

    private function addOne()
    {
        $this->as($this->owner)->adjust([$this->adjLine(1, 'add', ['product_id' => $this->sealant, 'batch_id' => $this->lot])])->assertOk();
        $id = $this->lastId('adjustments');

        return [$id, DB::table('adjustment_details')->where('adjustment_id', $id)->value('id')];
    }

    /**
     * K10 — saving an adjustment unchanged three times drifts its lot 13 → 13 → 12 → 10.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_saving_a_batch_adjustment_unchanged_leaves_the_lot_as_it_was()
    {
        [$id, $detail] = $this->addOne();
        $this->assertEquals(13, $this->lotQty());

        $same = [$this->adjLine(1, 'add', ['id' => $detail, 'product_id' => $this->sealant, 'batch_id' => $this->lot])];
        $seen = [];
        foreach ([1, 2, 3] as $i) {
            $this->editAdjustment($id, $same)->assertOk();
            $seen[] = $this->lotQty();
        }

        $this->assertSame([13.0, 13.0, 13.0], $seen, 'an unchanged +1 keeps the lot at 13 however often it is saved');
        $this->assertEquals(13, $this->shelf($this->main, $this->sealant), 'the warehouse total follows the lot');
    }

    /**
     * K10 — deleting a batch adjustment after one edit reverses its +1 twice.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_an_edited_batch_adjustment_reverses_it_once()
    {
        [$id, $detail] = $this->addOne();
        $this->editAdjustment($id, [$this->adjLine(1, 'add', ['id' => $detail, 'product_id' => $this->sealant, 'batch_id' => $this->lot])])
            ->assertOk();

        $this->deleteJson("/api/adjustments/{$id}")->assertOk();

        $this->assertEquals(12, $this->lotQty(), 'the lot is back where it started, 12');
        $this->assertEquals(12, $this->shelf($this->main, $this->sealant));
    }
}
