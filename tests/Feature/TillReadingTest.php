<?php

namespace Tests\Feature;

use App\utils\TillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the drawer should hold when the till is closed.
 *
 * Found in beta round 2: a ₱807.16 cash sale paid with ₱1,000 left the
 * Z-reading's expected cash ₱192.84 short. The payment row stores the amount
 * applied to the sale (montant = 807.16) and, separately, the change handed
 * back (change = 192.84). The reading subtracted the change from montant as if
 * montant were the amount tendered, so every sale that gave change understated
 * the drawer by that change.
 */
class TillReadingTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;
    private int $warehouseId;
    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = DB::table('roles')->insertGetId(['name' => 'till_probe', 'label' => 'Till Probe']);
        $this->userId = DB::table('users')->insertGetId([
            'firstname' => 'Till', 'lastname' => 'Probe', 'username' => 'till_probe',
            'email' => 'till@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $roleId,
        ]);
        $this->warehouseId = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $clientId = DB::table('clients')->insertGetId(['name' => 'walk-in-customer', 'code' => 1]);

        $this->sessionId = DB::table('till_sessions')->insertGetId([
            'warehouse_id' => $this->warehouseId,
            'user_id' => $this->userId,
            'terminal' => 'MAIN',
            'opening_float' => 5000,
            'opened_at' => now()->subHour(),
        ]);

        // A cash sale for 807.16, paid with 1,000 and 192.84 handed back.
        $saleId = DB::table('sales')->insertGetId([
            'user_id' => $this->userId, 'date' => now()->toDateString(), 'Ref' => 'SL_9001',
            'client_id' => $clientId, 'warehouse_id' => $this->warehouseId,
            'GrandTotal' => 807.16, 'paid_amount' => 807.16,
            'payment_statut' => 'paid', 'statut' => 'completed',
            'till_session_id' => $this->sessionId,
        ]);
        DB::table('payment_sales')->insert([
            'user_id' => $this->userId, 'date' => now()->toDateString(), 'Ref' => 'INV/SL_9001',
            'sale_id' => $saleId, 'montant' => 807.16, 'change' => 192.84, 'Reglement' => 'Cash',
        ]);
    }

    /** @test */
    public function cash_sales_count_the_amount_applied_not_the_amount_less_change()
    {
        $reading = TillService::reading($this->sessionId);

        $this->assertEqualsWithDelta(807.16, $reading['cash']['cash_sales'], 0.001);
    }

    /** @test */
    public function expected_cash_is_the_float_plus_what_the_sales_actually_brought_in()
    {
        $reading = TillService::reading($this->sessionId);

        // 5,000 float + 807.16: the 1,000 handed over less the 192.84 given back.
        $this->assertEqualsWithDelta(5807.16, $reading['cash']['expected'], 0.001);
    }

    /** @test */
    public function the_payment_breakdown_reports_the_same_figure()
    {
        $reading = TillService::reading($this->sessionId);
        $cash = collect($reading['payments'])->firstWhere('method', 'Cash');

        $this->assertEqualsWithDelta(807.16, $cash['amount'], 0.001);
    }
}
