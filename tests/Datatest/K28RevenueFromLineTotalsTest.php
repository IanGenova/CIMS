<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W2Helpers;

/**
 * K28 — P&L and dashboard "revenue" is the sum of sale_details.total, which
 * ignores the order discount, the SC/PWD discount and VAT. Decision: revenue
 * is net of VAT and of every discount; the dashboard shows the same figure.
 */
class K28RevenueFromLineTotalsTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    /**
     * K28 — a 265.00 bag sold with a 15.00 order discount (total 250.00) shows revenue 265.00; net of VAT it is 223.21.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_revenue_is_net_of_the_order_discount_and_vat()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)], null, ['discount' => 15])->assertOk();
        $this->assertEquals(250, $this->money($this->lastSale()->GrandTotal), 'fixture: 265 less 15');

        $pnl = $this->profitAndLoss();

        $this->assertEquals(223.21, $this->money($pnl['revenue']),
            "K28: a 250.00 VAT-inclusive sale shows revenue {$pnl['revenue']}; 250 / 1.12 = 223.21");
    }

    /**
     * K28 — a senior sale of 265.00 (charged 189.29, VAT-exempt) shows revenue 265.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_revenue_is_net_of_the_senior_discount()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)], null, $this->senior())->assertOk();
        $this->assertEquals(189.29, $this->money($this->lastSale()->GrandTotal), 'fixture: senior total due');

        $pnl = $this->profitAndLoss();

        $this->assertEquals(189.29, $this->money($pnl['revenue']),
            "K28: a VAT-exempt senior sale charged 189.29 shows revenue {$pnl['revenue']}");
    }

    /**
     * K28 — the dashboard's revenue repeats the same line-total figure (265.00 for a 250.00 sale).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_dashboard_shows_the_same_net_revenue()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1)], null, ['discount' => 15])->assertOk();

        $revenue = $this->getJson('/api/report/report_dashboard')->assertOk()->json('report.revenue');

        $this->assertEquals(223.21, $this->money($revenue),
            "K28: the dashboard shows revenue {$revenue} for a 250.00 VAT-inclusive sale; expected 223.21");
    }
}
