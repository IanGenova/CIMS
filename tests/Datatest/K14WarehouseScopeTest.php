<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K14 — Warehouse access is enforced only by middleware reading the top-level
 * warehouse_id; id-only routes (holds, till, wallet) and read/report endpoints
 * are not scoped to the user's warehouses (map A2).
 */
class K14WarehouseScopeTest extends DatatestCase
{
    use W3Helpers;

    /** The owner holds 3 bags at Main; returns the hold id. */
    private function mainHold()
    {
        $this->actAs($this->owner)->postJson('/api/pos/holds', ['warehouse_id' => $this->main, 'client_id' => $this->client,
            'label' => 'Mang Jose', 'total' => 795, 'cart' => [$this->line(3)]])->assertOk();
        $this->assertEquals(47, $this->shelf($this->main), 'fixture: the hold sets 3 bags aside at Main');

        return $this->lastId('pos_holds');
    }

    /** The owner opens a drawer at Main; returns the session id. */
    private function mainTill()
    {
        $this->actAs($this->owner)->postJson('/api/till/open', ['warehouse_id' => $this->main, 'opening_float' => 1000,
            'terminal' => 'W3-MAIN'])->assertOk();

        return $this->lastId('till_sessions');
    }

    /**
     * K14 — A Branch-only cashier can discard a hold that belongs to Main.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_branch_cashier_cannot_discard_a_main_hold()
    {
        $hold = $this->mainHold();

        $response = $this->actAs($this->cashier)->deleteJson("/api/pos/holds/{$hold}");

        $this->assertRefused($response, 'A2: a Branch-only cashier must not discard a Main hold');
        $this->assertNull(DB::table('pos_holds')->where('id', $hold)->value('deleted_at'), 'the Main hold must still be there');
        $this->assertEquals(47, $this->shelf($this->main), 'the 3 bags must stay set aside at Main');
    }

    /**
     * K14 — A Branch-only cashier can recall a hold that belongs to Main.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_branch_cashier_cannot_recall_a_main_hold()
    {
        $hold = $this->mainHold();

        $response = $this->actAs($this->cashier)->postJson("/api/pos/holds/{$hold}/recall");

        $this->assertRefused($response, 'A2: a Branch-only cashier must not recall a Main hold');
        $this->assertNull(DB::table('pos_holds')->where('id', $hold)->value('deleted_at'), 'the Main hold must still be there');
    }

    /**
     * K14 — The hold list shows a Branch-only cashier the holds of Main.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_hold_list_shows_a_branch_cashier_only_branch_holds()
    {
        $hold = $this->mainHold();

        $holds = $this->actAs($this->cashier)->getJson('/api/pos/holds')->assertOk()->json('holds');

        $this->assertNotContains($hold, array_map('intval', array_column($holds, 'id')),
            'A2: the Main hold must not be listed for a Branch-only cashier');
    }

    /**
     * K14 — A Branch-only cashier can move cash out of a Main drawer.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_branch_cashier_cannot_move_cash_through_a_main_till()
    {
        $session = $this->mainTill();

        $response = $this->actAs($this->cashier)->postJson('/api/till/cash', ['till_session_id' => $session,
            'type' => 'cash_out', 'amount' => 500, 'reason' => 'W3']);

        $this->assertRefused($response, 'A2: a Branch-only cashier must not take cash out of a Main drawer');
        $this->assertSame(0, DB::table('till_movements')->where('till_session_id', $session)->where('type', 'cash_out')->count(),
            'no cash movement may be written to the Main session');
    }

    /**
     * K14 — A Branch-only cashier can close (Z-read) a Main drawer.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_branch_cashier_cannot_close_a_main_till()
    {
        $session = $this->mainTill();

        $response = $this->actAs($this->cashier)->postJson("/api/till/sessions/{$session}/close", ['counted_cash' => 500]);

        $this->assertRefused($response, 'A2: a Branch-only cashier must not close a Main drawer');
        $this->assertSame('open', DB::table('till_sessions')->where('id', $session)->value('status'),
            'the Main session must still be open');
    }

    /**
     * K14 — The till session list shows a Branch-only cashier the Main drawers.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_till_session_list_shows_a_branch_cashier_only_branch_sessions()
    {
        $session = $this->mainTill();

        $sessions = $this->actAs($this->cashier)->getJson('/api/till/sessions')->assertOk()->json('sessions');

        $this->assertNotContains($session, array_column($sessions, 'id'),
            'A2: the Main session must not be listed for a Branch-only cashier');
    }

    /**
     * K14 — A Branch-only cashier can attach a wallet payment to a Main sale.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_branch_cashier_cannot_attach_a_wallet_payment_to_a_main_sale()
    {
        $this->actAs($this->owner);
        $sale = $this->unpaidSale($this->main);
        $wallet = DB::table('wallet_payments')->insertGetId(['provider' => 'xendit', 'channel' => 'GCASH',
            'reference_id' => 'w3-' . uniqid(), 'amount' => 530, 'currency' => 'PHP', 'status' => 'paid',
            'warehouse_id' => $this->branch, 'user_id' => $this->cashier->id, 'settled_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actAs($this->cashier)->postJson("/api/wallet/{$wallet}/attach", ['sale_id' => $sale]);

        $this->assertRefused($response, 'A2: a Branch-only cashier must not pay a Main sale');
        $this->assertSame(0, DB::table('payment_sales')->where('sale_id', $sale)->count(), 'no payment may land on the Main sale');
        $this->assertEquals(0, (float) DB::table('sales')->where('id', $sale)->value('paid_amount'));
    }

    /**
     * K14 — The dashboard stock alerts show a Branch-only cashier the shelves of Main.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_dashboard_stock_alerts_show_a_branch_cashier_only_branch_stock()
    {
        DB::table('products')->where('id', $this->cement)->update(['stock_alert' => 100]); // both shelves are low

        $alerts = $this->actAs($this->cashier)->getJson('/api/report/report_dashboard')->assertOk()->json('stock_alert');

        $this->assertContains('Branch Warehouse', array_column($alerts, 'warehouse'), 'fixture: Branch is low and is shown');
        $this->assertNotContains('Main Warehouse', array_column($alerts, 'warehouse'),
            'A2: the Main shelf must not be shown to a Branch-only cashier');
    }
}
