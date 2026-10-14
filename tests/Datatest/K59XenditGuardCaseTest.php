<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K59 — The manual-Xendit guard is case-sensitive (=== 'Xendit') while MySQL
 * groups payment methods case-insensitively.
 */
class K59XenditGuardCaseTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K59 — A hand-typed payment with Reglement "xendit" (lower case) passes the Xendit guard.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_hand_typed_xendit_payment_is_refused_in_any_case()
    {
        $this->actAs($this->owner);
        $sale = $this->unpaidSale();
        $this->postJson('/api/payment/sale', ['sale_id' => $sale, 'date' => $this->today(), 'montant' => 10,
            'Reglement' => 'Xendit', 'change' => 0])->assertStatus(422); // fixture: the guard exists

        $response = $this->postJson('/api/payment/sale', ['sale_id' => $sale, 'date' => $this->today(), 'montant' => 10,
            'Reglement' => 'xendit', 'change' => 0]);

        $this->assertRefused($response, 'Xendit payments are recorded only by Xendit, whatever the case typed');
        $this->assertSame(0, DB::table('payment_sales')->where('sale_id', $sale)->count(), 'no hand-typed xendit row may be written');
    }
}
