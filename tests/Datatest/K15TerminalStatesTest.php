<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K15: voided and Z-closed are not terminal states. Only VoidService enforces
 * them; payment, sale edit and sale delete do not.
 */
class K15TerminalStatesTest extends DatatestCase
{
    use W1Helpers;

    private function voidedSale($qty = 4)
    {
        $id = $this->as($this->owner)->sale([$this->line($qty)])->assertOk()->json('id');
        $this->postJson("/api/sales/{$id}/void", ['reason' => 'rung up by mistake'])->assertOk();
        $this->assertSame('voided', DB::table('sales')->where('id', $id)->value('statut'));

        return $id;
    }

    /**
     * K15 — a voided sale accepts a payment.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_voided_sale_cannot_be_paid()
    {
        $id = $this->voidedSale();

        $response = $this->postJson('/api/payment/sale', ['sale_id' => $id, 'date' => $this->today(), 'montant' => 100,
            'Reglement' => 'Cash', 'change' => 0]);

        $this->assertRefused($response, 'a voided sale takes no money');
        $this->assertSame(0, DB::table('payment_sales')->where('sale_id', $id)->whereNull('deleted_at')->count());
        $this->assertEquals(0, (float) DB::table('sales')->where('id', $id)->value('paid_amount'));
    }

    /**
     * K15 — a voided sale can be edited back to completed, taking stock again.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_voided_sale_cannot_be_edited()
    {
        $id = $this->voidedSale(4);
        $this->assertEquals(50, $this->shelf());

        $response = $this->editSale($id, [$this->savedSaleLine($id, 12)], 'completed');

        $this->assertRefused($response, 'a voided sale is final');
        $this->assertSame('voided', DB::table('sales')->where('id', $id)->value('statut'));
        $this->assertEquals(50, $this->shelf(), 'no stock leaves for a voided sale');
    }

    /**
     * K15 — a sale in a Z-closed shift can still be deleted (void refuses it; delete does not).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_in_a_closed_shift_cannot_be_deleted()
    {
        $this->as($this->owner);
        $session = $this->postJson('/api/till/open', ['warehouse_id' => $this->main, 'opening_float' => 500])->assertOk()
            ->json('session.id');
        $this->pos([$this->line(3)])->assertOk();
        $saleId = $this->lastId('sales');
        $this->assertEquals($session, DB::table('sales')->where('id', $saleId)->value('till_session_id'));
        $this->postJson("/api/till/sessions/{$session}/close", ['counted_cash' => 1295])->assertOk();
        $this->assertRefused($this->postJson("/api/sales/{$saleId}/void", ['reason' => 'late']), 'void already refuses a closed shift');

        $response = $this->deleteJson("/api/sales/{$saleId}");

        $this->assertRefused($response, 'a sale counted into a signed-off Z-reading cannot be deleted');
        $this->assertEquals(47, $this->shelf(), 'the 3 bags stay sold');
        $this->assertNull(DB::table('sales')->where('id', $saleId)->value('deleted_at'));
    }
}
