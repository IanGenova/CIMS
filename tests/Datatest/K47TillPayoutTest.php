<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K47 — the till accepts a payout larger than the drawer holds (expected cash
 * goes negative) and amounts below a centavo.
 */
class K47TillPayoutTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function openTill()
    {
        return $this->as($this->owner)->postJson('/api/till/open', ['warehouse_id' => $this->branch,
            'opening_float' => 1000, 'terminal' => 'BT2'])->assertOk()->json('session.id');
    }

    /**
     * K47 — a 5,000.00 cash-out from a drawer holding 1,000.00 is accepted; the drawer reads -4,000.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_payout_cannot_exceed_the_cash_in_the_drawer()
    {
        $session = $this->openTill();

        $response = $this->postJson('/api/till/cash', ['till_session_id' => $session, 'type' => 'cash_out',
            'amount' => 5000, 'reason' => 'payout']);

        $expected = $this->getJson("/api/till/sessions/$session/reading")->assertOk()->json('reading.cash.expected');
        $this->assertGreaterThanOrEqual(0, (float) $expected,
            "K47: a 5,000.00 payout from a 1,000.00 drawer was accepted (HTTP {$response->status()}); expected cash is now {$expected}");
        $response->assertStatus(422);
    }

    /**
     * K47 — a cash movement of 0.001 is accepted and stored below a centavo.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_cash_movement_must_be_whole_centavos()
    {
        $session = $this->openTill();

        $response = $this->postJson('/api/till/cash', ['till_session_id' => $session, 'type' => 'cash_in',
            'amount' => 0.001, 'reason' => 'coins']);

        $stored = DB::table('till_movements')->where('till_session_id', $session)->pluck('amount')->all();
        $this->assertEmpty($stored, 'K47: a 0.001 cash movement was stored as ' . implode(', ', $stored)
            . ' (HTTP ' . $response->status() . ')');
        $response->assertStatus(422);
    }
}
