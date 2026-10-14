<?php

namespace Tests\Feature;

use App\utils\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * The bell is not buried by yesterday's warnings.
 *
 * Found on the live bell: 11 of the owner's 15 notices were the same low-stock
 * warning, one per day and another whenever the count moved ("84 products…",
 * "85 products…", "86 products…"). A standing alert -- low stock, expiry,
 * purchases waiting, customers over their limit -- describes the situation
 * NOW, so only its latest version is shown. Activity notices (a purchase
 * approved, goods received) are events and all stay.
 */
class NotificationServiceAlertsTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private function alert($type, $title, $minutesAgo, $permission = 'Reports_quantity_alerts')
    {
        return DB::table('app_notifications')->insertGetId([
            'user_id' => null, 'type' => $type, 'title' => $title, 'level' => 'warning',
            'permission' => $permission, 'link' => '/app/reports/quantity_alerts',
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /** @test */
    public function only_the_latest_of_a_standing_alert_is_shown()
    {
        $user = $this->actAsUserWith(['Reports_quantity_alerts', 'purchases_approve']);
        $this->alert('stock_alert', '84 product(s) at or below their alert level', 3000);
        $this->alert('stock_alert', '86 product(s) at or below their alert level', 1500);
        $latest = $this->alert('stock_alert', '85 product(s) at or below their alert level', 60);
        $e1 = $this->alert('purchase_approval', 'PR_1117 needs approval', 2000, 'purchases_approve');
        $e2 = $this->alert('purchase_approval', 'PR_1118 needs approval', 30, 'purchases_approve');

        $shown = NotificationService::forUser($user->id, ['Reports_quantity_alerts', 'purchases_approve']);
        $ids = array_column($shown, 'id');

        $this->assertContains($latest, $ids);
        $this->assertCount(1, array_filter($shown, function ($n) { return $n['type'] === 'stock_alert'; }));
        // Events are not standing alerts: both approvals stay.
        $this->assertContains($e1, $ids);
        $this->assertContains($e2, $ids);
        $this->assertSame(3, NotificationService::unreadCount($user->id, ['Reports_quantity_alerts', 'purchases_approve']));
    }

    /** @test */
    public function each_notice_carries_its_time_for_the_bell()
    {
        $user = $this->actAsUserWith(['Reports_quantity_alerts']);
        $this->alert('stock_alert', '85 product(s) at or below their alert level', 90);

        $shown = $this->getJson('/api/notifications')->assertOk()->json('notifications');

        $this->assertNotEmpty($shown[0]['created_at']);
    }
}
