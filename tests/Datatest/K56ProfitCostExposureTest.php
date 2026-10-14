<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W3Helpers;

/**
 * K56 — The dashboard and product detail expose profit and product cost to
 * users without Reports_profit.
 */
class K56ProfitCostExposureTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K56 — The dashboard shows a cashier without Reports_profit today's profit.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_dashboard_does_not_show_profit_to_a_cashier()
    {
        $this->actAs($this->cashier)->pos([$this->line(2)], $this->branch)->assertOk();
        $this->getJson('/api/report/ProfitAndLoss')->assertForbidden(); // fixture: the cashier may not see profit

        $report = $this->getJson('/api/report/report_dashboard')->assertOk()->json('report');

        $this->assertNull($report['profit'] ?? null,
            'without Reports_profit the dashboard must not carry profit (got ' . json_encode($report['profit'] ?? null) . ')');
    }

    /**
     * K56 — Product detail shows a cashier without a cost permission the product's cost.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_product_detail_does_not_show_cost_to_a_cashier()
    {
        $response = $this->actAs($this->cashier)->getJson("/api/Products/{$this->cement}");

        if ($response->getStatusCode() === 403) {
            $this->addToAssertionCount(1); // refused outright: no cost shown

            return;
        }
        $body = $response->json();
        foreach (['Unit_cost', 'fix_cost', 'Total_cost', 'Net_cost', 'tax_cost'] as $key) {
            $this->assertArrayNotHasKey($key, (array) $body,
                "cost field $key must not reach a user without Reports_profit (value " . json_encode($body[$key] ?? null) . ')');
        }
    }
}
