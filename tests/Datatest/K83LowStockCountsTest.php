<?php

namespace Tests\Datatest;

use App\utils\NotificationService;
use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K83 — low-stock figures disagree between the bell, the badge and the list,
 * services are counted, and the badge endpoint has no permission check
 * (S5: a service is never on the low-stock alert).
 * Products are inserted directly with no image; nothing is saved through ProductsController.
 */
class K83LowStockCountsTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function badge()
    {
        return (int) $this->getJson('/api/report/count_quantity_alert')->assertOk()->json();
    }

    private function listed()
    {
        return (int) $this->getJson('/api/report/stock_alert?limit=100')->assertOk()->json('products.total');
    }

    /**
     * K83 — a service product (qte 0, alert 0) adds 2 to the badge while the list leaves it out.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_service_is_never_counted_on_the_low_stock_badge()
    {
        $this->product(['name' => 'Delivery fee', 'is_service' => 1, 'stock_alert' => 0], 0, 0);
        $this->product(['name' => 'Rebar 10mm', 'stock_alert' => 10], 5, 50);   // one real low-stock row

        $this->as($this->owner);
        $list = $this->listed();
        $badge = $this->badge();

        $this->assertEquals(1, $list, 'fixture: the list shows only the rebar at Main');
        $this->assertEquals($list, $badge, "K83: the badge says {$badge} low-stock items, the list {$list}; the service was counted");
    }

    /**
     * K83 — the bell counts only items with an alert level above 0, so it disagrees with the badge and the list.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_bell_agrees_with_the_badge_and_the_list()
    {
        $this->product(['name' => 'Rebar 10mm', 'stock_alert' => 10], 5, 50);
        $this->product(['name' => 'Tie wire', 'stock_alert' => 0], 0, 20);

        NotificationService::generateAlerts();
        $title = (string) DB::table('app_notifications')->where('title', 'like', '%alert level%')->value('title');
        $bell = (int) $title;

        $this->as($this->owner);
        $badge = $this->badge();
        $list = $this->listed();

        $this->assertSame($list, $badge, "K83: badge {$badge} and list {$list} disagree");
        $this->assertSame($list, $bell, "K83: the bell says '{$title}' while the list shows {$list} and the badge {$badge}");
    }

    /**
     * K83 — a user with no permissions reads the low-stock count (200) although the list is refused (403).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_badge_needs_the_stock_alert_permission()
    {
        $this->as($this->nobody)->getJson('/api/report/stock_alert?limit=10')->assertStatus(403);   // control

        $response = $this->getJson('/api/report/count_quantity_alert');

        $this->assertSame(403, $response->status(),
            "K83: a user with no permissions got HTTP {$response->status()} and the count " . $response->getContent());
    }
}
