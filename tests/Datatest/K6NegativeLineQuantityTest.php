<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K6: line quantities on returns, transfers and adjustment edits are not
 * validated (> 0), so a negative line reverses the stock direction.
 */
class K6NegativeLineQuantityTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K6 — a negative transfer line lets a Main-only stockman drain Branch.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_transfer_line_quantity_must_be_positive()
    {
        $response = $this->as($this->stockman)->transfer([$this->moveLine(-5)]);

        $this->assertRefused($response, 'a transfer of -5 bags is refused');
        $this->assertEquals(20, $this->shelf($this->branch), 'Branch, which the stockman cannot work in, keeps its 20');
        $this->assertEquals(50, $this->shelf($this->main));
        $this->assertSame(0, DB::table('transfers')->count());
    }

    /**
     * K6 — an adjustment edit accepts a negative quantity (store has gt:0, update does not).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_adjustment_edit_line_quantity_must_be_positive()
    {
        $this->as($this->owner)->adjust([$this->adjLine(10, 'add')])->assertOk();
        $id = $this->lastId('adjustments');
        $this->assertEquals(60, $this->shelf());
        $detail = DB::table('adjustment_details')->where('adjustment_id', $id)->value('id');

        $response = $this->editAdjustment($id, [$this->adjLine(-50, 'add', ['id' => $detail])]);

        $this->assertRefused($response, 'an "add" of -50 is refused');
        $this->assertEquals(60, $this->shelf(), 'the adjustment stays +10');
    }

    /**
     * K6 — a supplier return of a negative quantity adds stock and a negative total.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_return_line_quantity_must_be_positive()
    {
        $this->as($this->owner);
        $purchaseId = $this->purchase([$this->buyLine(10, 230)], 'ordered');
        $this->receive($purchaseId, 10)->assertOk();
        $this->assertEquals(60, $this->shelf());

        $response = $this->purchaseReturn([$this->buyLine(-20, 230)], ['purchase_id' => $purchaseId]);

        $this->assertRefused($response, 'returning -20 bags to the supplier is refused');
        $this->assertEquals(60, $this->shelf(), 'a return to the supplier never adds stock');
        $this->assertSame(0, DB::table('purchase_returns')->count());
    }

    /**
     * K6 — a customer return of a negative quantity takes stock out.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_return_line_quantity_must_be_positive()
    {
        $this->as($this->owner);
        $saleId = $this->sale([$this->line(5)])->assertOk()->json('id');
        $this->assertEquals(45, $this->shelf());

        $response = $this->saleReturn([$this->line(-5)], ['sale_id' => $saleId]);

        $this->assertRefused($response, 'a customer return of -5 bags is refused');
        $this->assertEquals(45, $this->shelf(), 'a customer return never removes stock');
        $this->assertSame(0, DB::table('sale_returns')->count());
    }
}
