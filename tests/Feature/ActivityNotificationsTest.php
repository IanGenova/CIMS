<?php

namespace Tests\Feature;

use App\utils\ActivityNotifier;
use App\utils\TillService;
use App\utils\VoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\NotificationFixtures;
use Tests\TestCase;

/**
 * A notice for each activity, to the people who have to act on it -- the
 * counter and the stock count. Purchasing, receiving, adjustments, transfers
 * and returns each have their own test file driving their endpoint
 * (*NotificationsTest.php).
 *
 * The bell only ever carried four daily summaries produced by the scheduler;
 * nothing told a manager the moment a till closed short or a sale was voided.
 */
class ActivityNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
    }

    private function openTill()
    {
        return DB::table('till_sessions')->insertGetId([
            'warehouse_id' => $this->warehouseId, 'user_id' => $this->cashier->id, 'terminal' => 'MAIN',
            'opening_float' => 1000, 'opened_at' => now()->subHour(),
        ]);
    }

    /** @test */
    public function a_till_closed_short_tells_the_managers()
    {
        $session = $this->openTill();
        Auth::login($this->cashier);

        TillService::close($session, 980); // 1,000 float, nothing sold: 20 short

        $n = $this->bellOf($this->manager)->first(fn ($n) => str_contains($n['title'], 'short'));
        $this->assertNotNull($n);
        $this->assertStringContainsString('20.00 short', $n['title']);
        $this->assertSame('danger', $n['level']);
        $this->assertFalse($this->seesMention($this->cashier, 'short'));
        $this->assertFalse($this->seesMention($this->stockman, 'short'));
    }

    /** @test */
    public function a_till_that_balances_raises_nothing()
    {
        $session = $this->openTill();
        Auth::login($this->cashier);

        TillService::close($session, 1000);

        $this->assertCount(0, $this->bellOf($this->manager));
    }

    /** @test */
    public function a_voided_sale_tells_the_managers()
    {
        $sale = DB::table('sales')->insertGetId([
            'user_id' => $this->cashier->id, 'date' => now()->toDateString(), 'Ref' => 'SL_7001',
            'client_id' => $this->clientId, 'warehouse_id' => $this->warehouseId, 'GrandTotal' => 276.08,
            'paid_amount' => 0, 'payment_statut' => 'unpaid', 'statut' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Auth::login($this->cashier);

        $result = VoidService::void($sale, 'Wrong item rung');
        $this->assertArrayNotHasKey('error', $result);

        $n = $this->bellOf($this->manager)->first(fn ($n) => str_contains($n['title'], 'SL_7001 was voided'));
        $this->assertNotNull($n);
        $this->assertStringContainsString('Wrong item rung', $n['body']);
        $this->assertFalse($this->seesMention($this->cashier, 'SL_7001'));
    }

    /** @test */
    public function a_stock_count_is_news_only_when_it_changed_the_shelf()
    {
        $id = DB::table('stock_counts')->insertGetId([
            'Ref' => 'SC_7001', 'date' => now()->toDateString(), 'warehouse_id' => $this->warehouseId,
            'user_id' => $this->stockman->id, 'status' => 'completed',
        ]);
        Auth::login($this->stockman);

        ActivityNotifier::stockCountCompleted($id, 2);
        ActivityNotifier::stockCountCompleted($id, 0);

        $this->assertCount(1, $this->bellOf($this->manager)->filter(fn ($n) => str_contains($n['title'], 'SC_7001')));
        $this->assertFalse($this->seesMention($this->stockman, 'SC_7001'));
    }
}
