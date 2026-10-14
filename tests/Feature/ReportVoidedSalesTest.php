<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * A voided sale is not revenue and nobody owes it.
 *
 * Found in beta round 2. Voiding keeps the invoice, marks it `voided` and
 * reverses its payment, so it sits with GrandTotal intact and paid_amount 0.
 * The report queries summed GrandTotal for every non-deleted sale with no
 * status filter, so each void showed up twice over: as takings on the
 * Dashboard and Profit and Loss, and as an unpaid debt on the customer reports.
 * The walk-in customer appeared to owe ₱3,691.08 -- two voided sales.
 *
 * Fixture: one completed sale of 100 (fully paid) and one voided sale of 40,
 * both today, same customer.
 */
class ReportVoidedSalesTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->actAsUserWith(['Reports_customers', 'Reports_profit', 'record_view']);

        $warehouseId = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->clientId = DB::table('clients')->insertGetId([
            'name' => 'Dela Rosa Builders', 'code' => 7, 'email' => 'dr@example.invalid',
            'country' => 'Philippines', 'city' => 'Iloilo City', 'phone' => '0917', 'adresse' => 'Jaro',
        ]);
        $unitId = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc']);
        $categoryId = DB::table('categories')->insertGetId(['code' => 'PLB', 'name' => 'Plumbing']);
        $productId = DB::table('products')->insertGetId([
            'code' => 'HW-T1', 'Type_barcode' => 'CODE128', 'name' => 'Elbow 1/2 inch',
            'cost' => 30, 'price' => 50, 'category_id' => $categoryId,
            'unit_id' => $unitId, 'unit_sale_id' => $unitId, 'unit_purchase_id' => $unitId,
        ]);

        $today = now()->toDateString();
        foreach ([['SL_T1', 'completed', 100, 100, 'paid'], ['SL_T2', 'voided', 40, 0, 'unpaid']] as [$ref, $statut, $total, $paid, $ps]) {
            $saleId = DB::table('sales')->insertGetId([
                'user_id' => $user->id, 'date' => $today, 'Ref' => $ref, 'client_id' => $this->clientId,
                'warehouse_id' => $warehouseId, 'GrandTotal' => $total, 'paid_amount' => $paid,
                'payment_statut' => $ps, 'statut' => $statut,
            ]);
            DB::table('sale_details')->insert([
                'date' => $today, 'sale_id' => $saleId, 'product_id' => $productId,
                'price' => $total / 2, 'total' => $total, 'quantity' => 2, 'sale_unit_id' => $unitId,
            ]);
            if ($paid > 0) {
                DB::table('payment_sales')->insert([
                    'user_id' => $user->id, 'date' => $today, 'Ref' => 'INV/' . $ref,
                    'sale_id' => $saleId, 'montant' => $paid, 'Reglement' => 'Cash',
                ]);
            }
        }
    }

    /** @test */
    public function the_customer_report_does_not_count_a_void_as_owed()
    {
        $row = collect($this->getJson('/api/report/client?SortField=name&SortType=asc&limit=10&page=1')
            ->assertOk()->json('report'))->firstWhere('name', 'Dela Rosa Builders');

        $this->assertEquals(1, $row['total_sales']);
        $this->assertEqualsWithDelta(100, $row['total_amount'], 0.001);
        $this->assertEqualsWithDelta(0, $row['due'], 0.001);
    }

    /** @test */
    public function one_customers_statement_does_not_count_a_void_as_owed()
    {
        $report = $this->getJson('/api/report/client/' . $this->clientId)->assertOk()->json('report');

        $this->assertEquals(1, $report['total_sales']);
        $this->assertEqualsWithDelta(100, $report['total_amount'], 0.001);
        $this->assertEqualsWithDelta(0, $report['due'], 0.001);
    }

    /** @test */
    public function the_dashboard_does_not_count_a_void_as_revenue()
    {
        $report = $this->getJson('/api/report/report_dashboard')->assertOk()->json('report');

        $this->assertEqualsWithDelta(100, $report['today_sales'], 0.001);
        $this->assertEqualsWithDelta(100, $report['revenue'], 0.001);
    }

    /** @test */
    public function profit_and_loss_does_not_count_a_void_as_a_sale()
    {
        $today = now()->toDateString();
        $data = $this->getJson("/api/report/ProfitAndLoss?from={$today}&to={$today}")->assertOk()->json('data');

        $this->assertEqualsWithDelta(100, $data['sales']['sum'], 0.001);
        $this->assertEquals(1, $data['sales']['nmbr']);
    }
}
