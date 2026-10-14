<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K9: core documents have no SoftDeletes, so deleted rows are still found,
 * edited, voided, paid, deleted again and counted in reports.
 */
class K9DeletedDocumentsTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K9 — a deleted purchase can be edited to "received" and brings in stock.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_deleted_purchase_cannot_be_edited()
    {
        $this->as($this->owner);
        $id = $this->purchase([$this->buyLine(5, 10)], 'ordered');
        $detail = $this->purchaseDetailId($id);
        $this->deleteJson("/api/purchases/{$id}")->assertOk();

        $response = $this->editPurchase($id, [$this->buyLine(50, 10, ['id' => $detail])], 'received');

        $this->assertSame(404, $response->getStatusCode(), 'a deleted purchase is not found');
        $this->assertEquals(50, $this->shelf(), 'no stock arrives under a deleted purchase');
    }

    /**
     * K9 — a deleted sale can still be voided, which returns its stock a second time.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_deleted_sale_cannot_be_voided()
    {
        $this->as($this->owner);
        $id = $this->sale([$this->line(5)])->assertOk()->json('id');
        $this->deleteJson("/api/sales/{$id}")->assertOk();
        $this->assertEquals(50, $this->shelf());

        $response = $this->postJson("/api/sales/{$id}/void", ['reason' => 'after delete']);

        $this->assertRefused($response, 'a deleted sale cannot be voided');
        $this->assertEquals(50, $this->shelf(), 'the 5 bags came back once, on the delete');
    }

    /**
     * K9 — a deleted adjustment can still be edited and moves stock.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_deleted_adjustment_cannot_be_edited()
    {
        $this->as($this->owner)->adjust([$this->adjLine(10, 'add')])->assertOk();
        $id = $this->lastId('adjustments');
        $detail = DB::table('adjustment_details')->where('adjustment_id', $id)->value('id');
        $this->deleteJson("/api/adjustments/{$id}")->assertOk();
        $this->assertEquals(50, $this->shelf());

        $response = $this->editAdjustment($id, [$this->adjLine(30, 'add', ['id' => $detail])]);

        $this->assertSame(404, $response->getStatusCode(), 'a deleted adjustment is not found');
        $this->assertEquals(50, $this->shelf(), 'no stock moves under a deleted adjustment');
    }

    /**
     * K9 — a deleted expense is still counted in the profit and loss report.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_deleted_expense_is_not_counted_in_profit_and_loss()
    {
        $category = DB::table('expense_categories')->insertGetId(['name' => 'Utilities', 'user_id' => $this->owner->id]);
        $this->as($this->owner)->postJson('/api/expenses', ['expense' => ['date' => $this->today(), 'warehouse_id' => $this->main,
            'category_id' => $category, 'amount' => 500, 'details' => 'Power bill']])->assertOk();
        $id = $this->lastId('expenses');
        $this->deleteJson("/api/expenses/{$id}")->assertOk();

        $report = $this->getJson('/api/report/ProfitAndLoss?from=' . $this->today() . '&to=' . $this->today())->assertOk();

        $this->assertEquals(0, (float) $report->json('data.expenses.sum') + (float) $report->json('expenses.sum'),
            'the deleted ₱500 expense is not in the report');
    }
}
