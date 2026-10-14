<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K22 — Batch tracking can be switched with tracked or untracked stock on hand:
 * the product edit has no guard and turns tracking off when the field is
 * omitted, and the tracking endpoint guards only "on".
 */
class K22BatchTrackingToggleTest extends DatatestCase
{
    use W3Helpers;

    /** A batch-tracked product whose 12 bags at Main sit in lot LOT-A. */
    private function trackedProduct()
    {
        $id = $this->productRow(['has_batches' => 1, 'name' => 'Tile adhesive'], 12, 0);
        $lot = DB::table('product_batches')->insertGetId(['product_id' => $id, 'batch_number' => 'LOT-A',
            'expiry_date' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('batch_stock')->insert(['product_batch_id' => $lot, 'warehouse_id' => $this->main, 'qte' => 12,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * K22 — The product edit turns batch tracking on while 70 untracked bags are on hand.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_product_edit_cannot_turn_batch_tracking_on_over_untracked_stock()
    {
        $this->actAs($this->owner)->putJson("/api/batches/tracking/{$this->cement}", ['has_batches' => true])->assertStatus(422);

        $response = $this->putJson("/api/Products/{$this->cement}", $this->productBody(['has_batches' => 'true']));

        $this->assertRefused($response, 'the edit screen must apply the same guard as the tracking endpoint');
        $this->assertSame(0, (int) DB::table('products')->where('id', $this->cement)->value('has_batches'),
            '70 bags belong to no lot; tracking must stay off');
    }

    /**
     * K22 — Saving a product edit without the has_batches field turns batch tracking off.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_edit_that_omits_has_batches_keeps_batch_tracking_on()
    {
        $product = $this->trackedProduct();

        $this->actAs($this->owner)->putJson("/api/Products/{$product}", $this->productBody(['note' => 'renamed shelf'], $product))
            ->assertOk();

        $this->assertSame(1, (int) DB::table('products')->where('id', $product)->value('has_batches'),
            'an absent field must keep the flag as it was, not switch tracking off over 12 bags in lot LOT-A');
    }

    /**
     * K22 — The tracking endpoint turns batch tracking off while lots still hold stock.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_batch_tracking_cannot_be_turned_off_while_lots_hold_stock()
    {
        $product = $this->trackedProduct();

        $response = $this->actAs($this->owner)->putJson("/api/batches/tracking/{$product}", ['has_batches' => false]);

        $this->assertRefused($response, 'tracking may go off only at zero tracked stock');
        $this->assertSame(1, (int) DB::table('products')->where('id', $product)->value('has_batches'));
    }
}
