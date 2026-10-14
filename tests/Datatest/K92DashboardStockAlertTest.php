<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K92 — A user with no permissions sees stock-alert data on the dashboard:
 * GET chart/report_with_echart returns report_dashboard.stock_alert (product
 * code, name, warehouse, quantity, alert level) with no permission check,
 * while every other figure on the same dashboard is empty for that user.
 */
class K92DashboardStockAlertTest extends DatatestCase
{
    use W5Helpers;

    /** The stock_alert rows of a dashboard response, wherever the nesting puts them. */
    private function stockAlert($response)
    {
        $json = $response->json();

        return $json['report_dashboard']['original']['stock_alert'] ?? $json['report_dashboard']['stock_alert'] ?? null;
    }

    /**
     * K92 — A user with no permissions gets no stock-alert rows.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_with_no_permissions_gets_no_stock_alert_data()
    {
        // Cement is below its alert level at both warehouses (Main 50, Branch 20).
        DB::table('products')->where('id', $this->cement)->update(['stock_alert' => 100]);
        $owner = $this->stockAlert($this->actAs($this->owner)->getJson('/api/chart/report_with_echart')->assertOk());
        $this->assertContains('HW-0001', array_column($owner ?: [], 'code'),
            'fixture: the owner\'s dashboard lists cement on the stock alert');

        $response = $this->actAs($this->nobody)->getJson('/api/chart/report_with_echart');

        $this->assertLessThan(500, $response->getStatusCode(), 'the dashboard must not crash for this user');
        $this->assertEmpty($response->getStatusCode() === 200 ? $this->stockAlert($response) : [],
            'A1: a user with no permissions must not see product codes, warehouses and quantities on the stock alert');
    }
}
