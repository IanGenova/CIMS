<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W4Helpers;

/**
 * K66 — Offline idempotency keys are global, not per user, and the status call
 * returns other users' keys (R18).
 * Red until repaired (.flow/DATATEST-2026-10-06.md).
 */
class K66OfflineKeyScopeTest extends DatatestCase
{
    use W4Helpers;

    public function test_another_users_key_does_not_swallow_a_cashiers_offline_sale()
    {
        $key = 'k66-dup-key-001';
        $this->as($this->owner)->offlineSync($key, $this->posPayload([$this->line(1)]))->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');
        $ownerSale = $this->lastId('sales');

        $response = $this->as($this->cashier)->offlineSync($key, $this->posPayload([$this->line(3)], $this->branch))->assertOk();

        $this->assertSame('accepted', $response->json('results.0.status'),
            "R18: the Branch cashier's own offline sale must bank; it was answered \"" . $response->json('results.0.status')
            . "\" and pointed at sale " . $response->json('results.0.sale_id') . " (the owner's Main sale is $ownerSale)");
        $this->assertSame(1, DB::table('sales')->whereNull('deleted_at')->where('warehouse_id', $this->branch)->count(),
            'R18: the Branch sale must exist');
        $this->assertEquals(17, $this->shelf($this->branch));
    }

    public function test_the_status_call_does_not_reveal_another_users_keys()
    {
        $key = 'k66-owner-key';
        $this->as($this->owner)->offlineSync($key, $this->posPayload([$this->line(1)]))->assertOk();

        $known = $this->as($this->cashier)->postJson('/api/offline/sales/status', ['keys' => [$key]])->assertOk()->json('known');

        $this->assertSame([], $known,
            "R18: a Branch cashier asking about the owner's key must learn nothing; got " . json_encode($known));
    }
}
