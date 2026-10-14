<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K91 — The supplier detail report's Payments tab is HTTP 500 for a user
 * without record_view (the purchaser): ReportController::Payments_Provider
 * adds `where user_id = ?` to a query that joins payment_purchases and
 * purchases, both of which have user_id ("Column 'user_id' in where clause is
 * ambiguous"). The purchaser loses his own payments; the owner is unaffected.
 */
class K91SupplierPaymentsReportTest extends DatatestCase
{
    use W5Helpers;

    /**
     * K91 — The purchaser sees his own payments to a supplier on its detail report.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_purchaser_sees_his_payments_on_the_supplier_report()
    {
        $purchaser = $this->person(['Purchases_add', 'Purchases_view', 'payment_purchases_add', 'payment_purchases_view',
            'Suppliers_view', 'Reports_suppliers']);

        $this->actAs($purchaser)->purchase([$this->buyLine(2)])->assertOk();
        $purchase = $this->lastId('purchases');
        DB::table('payment_purchases')->insert(['user_id' => $purchaser->id, 'date' => $this->today(),
            'Ref' => 'INV/PR_K91', 'purchase_id' => $purchase, 'montant' => 460, 'Reglement' => 'Cash']);

        $response = $this->actAs($purchaser)->getJson("/api/report/provider_payments?id={$this->supplier}&limit=10&page=1");

        $this->assertSame(200, $response->getStatusCode(), 'the purchaser\'s Payments tab must load (got HTTP '
            . $response->getStatusCode() . ': ' . $this->peek($response) . ')');
        $this->assertContains('INV/PR_K91', array_column($response->json('payments'), 'Ref'),
            'the purchaser\'s own payment to the supplier must be listed');
    }
}
