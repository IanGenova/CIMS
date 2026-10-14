<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K31 — customer balance ignores sale returns: CreditService counts returns
 * with statut 'completed', which returns never have, and the client report
 * subtracts none (M5: balance = sales - payments - returns).
 * Customer owes 2,650.00 (10 bags, unpaid) and returns 2 bags (530.00): owes 2,120.00.
 */
class K31CustomerBalanceReturnsTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function saleAndReturn()
    {
        $this->as($this->owner)->storeSale([$this->line(10)])->assertOk();
        $this->saleReturn($this->lastId('sales'), [$this->line(2)])->assertOk();
        $this->assertEquals(530, $this->money(DB::table('sale_returns')->orderByDesc('id')->value('GrandTotal')), 'fixture');
    }

    /**
     * K31 — the credit statement shows returned 0 and balance 2,650.00 after a 530.00 return.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_credit_statement_subtracts_returns()
    {
        $this->saleAndReturn();

        $balance = $this->getJson("/api/credit/statement/{$this->client}")->assertOk()->json('balance');

        $this->assertEquals(2120, $this->money($balance['balance']),
            "K31: the statement says the customer owes {$balance['balance']} (returned {$balance['returned']})");
    }

    /**
     * K31 — the customer report shows due 2,650.00 after a 530.00 return.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_customer_report_subtracts_returns()
    {
        $this->saleAndReturn();

        $due = $this->getJson("/api/report/client/{$this->client}")->assertOk()->json('report.due');

        $this->assertEquals(2120, $this->money($due), "K31: the customer report says {$due} is due");
    }

    /**
     * K31 — with a 2,500.00 limit, a 265.00 sale is refused although the customer owes 2,120.00 after the return.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_credit_limit_counts_returns()
    {
        $this->saleAndReturn();
        DB::table('clients')->where('id', $this->client)->update(['credit_limit' => 2500]);

        $response = $this->storeSale([$this->line(1)]);

        $this->assertSame(200, $response->status(),
            'K31: a sale taking the customer to 2,385.00 (under 2,500.00) was refused: ' . $response->getContent());
    }
}
