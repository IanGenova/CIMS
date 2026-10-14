<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K1: stock movements written outside store() carry no document reference, and
 * void reverses only the movements that name the sale, so after an edit it
 * restores the wrong quantity (map S2, L1).
 */
class K1EditMovementReferencesTest extends DatatestCase
{
    use W1Helpers;

    private function completedSale($qty, $statut = 'completed')
    {
        return $this->as($this->owner)->sale([$this->line($qty)], $statut)->assertOk()->json('id');
    }

    /**
     * K1 — a void after an edit that raised the quantity leaves stock short.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_void_after_raising_a_line_restores_every_unit()
    {
        $id = $this->completedSale(10);
        $this->editSale($id, [$this->savedSaleLine($id, 12)])->assertOk();
        $this->assertEquals(38, $this->shelf(), 'the edit took 12 in all');

        $this->postJson("/api/sales/{$id}/void", ['reason' => 'void after edit'])->assertOk();

        $this->assertEquals(50, $this->shelf(), 'a voided sale gives back all 12 bags it holds');
    }

    /**
     * K1 — a void after an edit that lowered the quantity creates phantom stock.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_void_after_lowering_a_line_restores_only_what_the_sale_held()
    {
        $id = $this->completedSale(10);
        $this->editSale($id, [$this->savedSaleLine($id, 5)])->assertOk();
        $this->assertEquals(45, $this->shelf());

        $this->postJson("/api/sales/{$id}/void", ['reason' => 'void after edit'])->assertOk();

        $this->assertEquals(50, $this->shelf(), 'a voided sale of 5 bags gives back 5, not the 10 it once had');
    }

    /**
     * K1 — a sale completed by edit and then voided returns nothing.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_void_of_a_sale_completed_by_edit_restores_its_stock()
    {
        $id = $this->completedSale(7, 'pending');
        $this->assertEquals(50, $this->shelf(), 'a pending sale moves nothing');
        $this->editSale($id, [$this->savedSaleLine($id, 7)], 'completed')->assertOk();
        $this->assertEquals(43, $this->shelf());

        $this->postJson("/api/sales/{$id}/void", ['reason' => 'void after completing'])->assertOk();

        $this->assertEquals(50, $this->shelf(), 'the 7 bags taken by the completing edit come back on void');
    }

    /**
     * K1 — the movements an edit writes name no document (reference NULL).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_every_movement_of_a_sale_edit_names_the_sale()
    {
        $id = $this->completedSale(10);
        $before = $this->lastMovementId();

        $this->editSale($id, [$this->savedSaleLine($id, 12)])->assertOk();

        $rows = $this->movements($this->main, null, $before);
        $this->assertNotEmpty($rows, 'the edit moved stock');
        foreach ($rows as $row) {
            $this->assertSame('Sale', $row->reference_type, "movement {$row->id} ({$row->quantity}) names its document type");
            $this->assertEquals($id, $row->reference_id, "movement {$row->id} names sale {$id}");
        }
        $this->assertEquals(-2, $rows->sum('quantity'), 'the edit nets to the 2 extra bags');
    }

    /**
     * K1 (M20) — a purchase received by edit sets no unit cost, so the average never moves.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_received_by_edit_moves_the_average_cost()
    {
        $this->as($this->owner);
        $id = $this->purchase([$this->buyLine(10, 300)], 'ordered');
        $line = $this->buyLine(10, 300, ['id' => $this->purchaseDetailId($id)]);

        $edit = $this->editPurchase($id, [$line], 'received');
        if (! $edit->isSuccessful()) {
            // A repair may send all receiving through goods receipts instead (K7).
            $this->assertRefused($edit, 'a refused edit is a 4xx');
            $this->assertEquals(50, $this->shelf());
            $this->assertEqualsWithDelta(230, $this->cost(), 0.001);

            return;
        }

        $this->assertEquals(60, $this->shelf(), 'the 10 bags arrived');
        // 70 bags on hand at 230 plus 10 at 300.
        $this->assertEqualsWithDelta(238.75, $this->cost(), 0.001, 'the receipt is folded into the moving average');
        $row = DB::table('stock_movements')->where('product_id', $this->cement)->orderByDesc('id')->first();
        $this->assertSame('Purchase', $row->reference_type, 'the receipt movement names the purchase');
        $this->assertEquals($id, $row->reference_id);
        $this->assertEqualsWithDelta(300, (float) $row->unit_cost, 0.001, 'valued at the price paid');
    }
}
