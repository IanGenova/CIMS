<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K32 — the supplier report and P&L purchases include rejected and unreceived
 * orders and ignore purchase returns. Decision: payables = received purchases
 * minus purchase returns (minus payments); open orders are "on order".
 * Baseline: 10 bags received at 230 = 2,300.00, paid 1,000.00.
 */
class K32SupplierPayablesTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function receivedAndPartPaid()
    {
        $this->as($this->owner)->storePurchase([$this->purchaseLine(10)])->assertOk();
        $this->postJson('/api/payment/purchase', ['purchase_id' => $this->lastId('purchases'), 'date' => now()->toDateString(),
            'montant' => 1000, 'change' => 0, 'Reglement' => 'Cash'])->assertOk();
    }

    private function supplierDue()
    {
        return $this->getJson("/api/report/provider/{$this->supplier}")->assertOk()->json('report');
    }

    /**
     * K32 — a rejected 11,500.00 order is counted as owed to the supplier (due 12,800.00, should be 1,300.00).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_rejected_purchase_is_not_owed()
    {
        $this->receivedAndPartPaid();
        $this->storePurchase([$this->purchaseLine(50)], 'ordered')->assertOk();
        $rejected = $this->lastId('purchases');
        $this->assertSame('pending', DB::table('purchases')->where('id', $rejected)->value('approval_status'), 'fixture: above threshold');
        $this->postJson("/api/purchase_approvals/$rejected/reject", ['reason' => 'too many'])->assertOk();

        $report = $this->supplierDue();

        $this->assertEquals(1300, $this->money($report['due']),
            "K32: the supplier report says {$report['due']} is owed, counting a rejected order ({$report['total_amount']} in total)");
    }

    /**
     * K32 — a 1,000.00 order not yet received is counted as owed (due 2,300.00, should be 1,300.00).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_unreceived_order_is_not_owed()
    {
        $this->receivedAndPartPaid();
        $this->storePurchase([$this->purchaseLine(4, 250)], 'ordered')->assertOk();

        $report = $this->supplierDue();

        $this->assertEquals(1300, $this->money($report['due']),
            "K32: the supplier report says {$report['due']} is owed, counting an order not yet received");
    }

    /**
     * K32 — a 460.00 purchase return does not reduce what is owed (due 1,300.00, should be 840.00).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_return_reduces_what_is_owed()
    {
        $this->receivedAndPartPaid();
        $this->postJson('/api/returns/purchase', ['supplier_id' => $this->supplier, 'warehouse_id' => $this->main,
            'purchase_id' => $this->lastId('purchases'), 'date' => now()->toDateString(), 'statut' => 'completed',
            'notes' => null, 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 460,
            'details' => [$this->purchaseLine(2)]])->assertOk();
        $this->assertEquals(460, $this->money(DB::table('purchase_returns')->orderByDesc('id')->value('GrandTotal')), 'fixture');

        $report = $this->supplierDue();

        $this->assertEquals(840, $this->money($report['due']),
            "K32: after a 460.00 purchase return the supplier report still says {$report['due']} is owed");
    }

    /**
     * K32 — the P&L purchases total counts a rejected order (13,800.00 instead of 2,300.00).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_profit_and_loss_purchases_leave_out_rejected_orders()
    {
        $this->receivedAndPartPaid();
        $this->storePurchase([$this->purchaseLine(50)], 'ordered')->assertOk();
        $this->postJson('/api/purchase_approvals/' . $this->lastId('purchases') . '/reject', ['reason' => 'too many'])->assertOk();

        $pnl = $this->profitAndLoss();

        $this->assertEquals(2300, $this->money($pnl['purchases']['sum']),
            "K32: the P&L purchases total reads {$pnl['purchases']['sum']}, including a rejected order");
    }
}
