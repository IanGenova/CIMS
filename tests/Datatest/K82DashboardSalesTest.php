<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W2Helpers;

/**
 * K82 — dashboard "today's sales" and top products include ordered and pending
 * sales, and add up quantities in mixed sale units (L1: only completed sales are sales).
 */
class K82DashboardSalesTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function dashboard()
    {
        return $this->getJson('/api/report/report_dashboard')->assertOk()->json();
    }

    /**
     * K82 — an ordered (not completed) 265.00 sale is counted in today's sales.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_todays_sales_count_only_completed_sales()
    {
        $this->as($this->owner)->storeSale([$this->line(1)], ['statut' => 'ordered'])->assertOk();
        $this->storeSale([$this->line(2)], ['statut' => 'pending'])->assertOk();

        $today = $this->dashboard()['report']['today_sales'];

        $this->assertEquals(0, $this->money($today),
            "K82: today's sales read {$today} with no completed sale (one ordered 265.00, one pending 530.00)");
    }

    /**
     * K82 — top products count an ordered sale as a sale.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_top_products_leave_out_sales_not_completed()
    {
        $this->as($this->owner)->storeSale([$this->line(1)])->assertOk();
        $this->storeSale([$this->line(3)], ['statut' => 'ordered'])->assertOk();

        $top = collect($this->dashboard()['products'])->firstWhere('name', 'Portland Cement 40kg');

        $this->assertEquals(1, (int) $top['count'], "K82: top products count {$top['count']} sales of cement; one was completed");
        $this->assertEquals(1, (float) $top['quantity'], "K82: top products show {$top['quantity']} bags; one completed bag was sold");
    }

    /**
     * K82 — one box of 10 bags shows in top products as quantity 1 "bag".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_top_products_quantity_is_in_the_products_unit()
    {
        $this->as($this->owner)->posUnpaid([$this->line(1, 2650, ['sale_unit_id' => $this->box])])->assertOk();

        $top = collect($this->dashboard()['products'])->firstWhere('name', 'Portland Cement 40kg');

        $this->assertSame('bag', $top['unit_product'], 'fixture: the dashboard labels cement in bags');
        $this->assertEquals(10, (float) $top['quantity'], "K82: one box of 10 bags shows as {$top['quantity']} bag(s)");
    }

    public function test_monthly_top_products_are_ranked_by_completed_sales_value()
    {
        $this->as($this->owner);
        $expensive = $this->product(['name' => 'Expensive single sale', 'price' => 1000], 10, 10);
        $this->storeSale([$this->line(1)])->assertOk();
        $this->storeSale([$this->line(1)])->assertOk();
        $this->storeSale([$this->lineOf($expensive, 1, 1000)])->assertOk();

        $products = $this->dashboard()['products'];
        $this->assertSame('Expensive single sale', $products[0]['name']);
        $this->assertEquals(1000, $products[0]['total']);
    }

    public function test_stock_alerts_prioritize_empty_shelves_and_keep_zero_threshold_meaningful()
    {
        $this->as($this->owner);
        for ($i = 0; $i < 5; $i++) {
            $this->product(['name' => 'Moderate shortage ' . $i, 'stock_alert' => 2], 1, 10);
        }
        $empty = $this->product(['name' => 'Empty shelf', 'stock_alert' => 0], 0, 10);
        $code = \Illuminate\Support\Facades\DB::table('products')->where('id', $empty)->value('code');

        $alerts = $this->dashboard()['stock_alert'];
        $this->assertCount(5, $alerts);
        $this->assertSame($code, $alerts[0]['code']);
        $this->assertSame('out_of_stock', $alerts[0]['alert_status']);
        $this->assertEquals(0, $alerts[0]['stock_alert']);

        $full = $this->getJson('/api/report/stock_alert')->assertOk()->json('products.data');
        $this->assertSame($code, $full[0]['code']);
        $this->assertSame('out_of_stock', $full[0]['alert_status']);
    }
}
