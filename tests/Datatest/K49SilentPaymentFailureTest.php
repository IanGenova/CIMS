<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K49 — a failed sale payment answers 200 "Payment Create successfully": the
 * exception is caught inside the transaction closure and its return value discarded.
 */
class K49SilentPaymentFailureTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K49 — a sale payment with no method (Reglement null) answers 200 and writes nothing.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_payment_with_no_method_is_refused_not_reported_as_saved()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)])->assertOk();
        $saleId = $this->lastId('sales');

        $response = $this->postJson('/api/payment/sale', ['sale_id' => $saleId, 'date' => now()->toDateString(),
            'montant' => 1, 'Reglement' => null, 'change' => 0, 'notes' => null]);

        $written = DB::table('payment_sales')->where('sale_id', $saleId)->count();
        $this->assertSame(0, $written, 'fixture: nothing may be written without a method');
        $this->assertSame(422, $response->status(),
            "K49: the server answered {$response->status()} '" . $response->json('message') . "' but wrote no payment");
    }
}
