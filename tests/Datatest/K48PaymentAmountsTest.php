<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K48 — payment amounts are not validated as money: sub-centavo and zero-value
 * payment rows are stored, and split-tender rows do not add up to paid_amount.
 */
class K48PaymentAmountsTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K48 — a sale payment of 0.00001 is accepted and stored as a zero-value row.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_payment_below_a_centavo_is_refused()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)])->assertOk();
        $saleId = $this->lastId('sales');

        $response = $this->paySale($saleId, 0.00001);

        $rows = DB::table('payment_sales')->where('sale_id', $saleId)->pluck('montant')->all();
        $this->assertEmpty($rows, 'K48: a 0.00001 payment was stored as ' . implode(', ', $rows)
            . ' (HTTP ' . $response->status() . ')');
        $response->assertStatus(422);
    }

    /**
     * K48 — split tender 100.004 + 165.00 on a 265.00 sale stores rows summing 265.004 against paid_amount 265.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_split_tender_rows_add_up_to_the_amount_paid()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->owner)->pos([$this->line(1)], null, ['amount' => 265, 'payments' => [
            ['Reglement' => 'Cash', 'amount' => 100.004, 'change' => 0],
            ['Reglement' => 'credit card', 'amount' => 165, 'change' => 0],
        ]]);

        $sale = DB::table('sales')->where('id', '>', $before)->first();
        if (! $sale) {
            $response->assertStatus(422);

            return;
        }
        $rows = DB::table('payment_sales')->where('sale_id', $sale->id)->pluck('montant')->all();
        foreach ($rows as $montant) {
            $this->assertEquals(round((float) $montant, 2), (float) $montant, "K48: a payment row of {$montant} is not whole centavos");
        }
        $this->assertEquals((float) $sale->paid_amount, (float) array_sum($rows),
            'K48: payment rows sum to ' . array_sum($rows) . " but the sale records {$sale->paid_amount} paid");
    }
}
