<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W4Helpers;

/**
 * K76 — The same user can hold two open tills in one warehouse by typing
 * another terminal name (R19). Decision: one open till per user per warehouse.
 * Red until repaired (.flow/DATATEST-2026-10-06.md).
 */
class K76SecondOpenTillTest extends DatatestCase
{
    use W4Helpers;

    public function test_a_second_till_for_the_same_user_and_warehouse_is_refused_under_another_terminal_name()
    {
        $this->as($this->owner)->openTill($this->main, 1000)->assertOk();

        $second = $this->openTill($this->main, 500, 'COUNTER-2');

        $status = $second->getStatusCode();
        $this->assertTrue($status >= 400 && $status < 500,
            'K76: one open till per user per warehouse; a second one on terminal COUNTER-2 was opened (got HTTP '
            . $status . ': ' . substr($second->getContent(), 0, 200) . ')');
        $this->assertSame(1, DB::table('till_sessions')->whereNull('deleted_at')->where('status', 'open')
            ->where('user_id', $this->owner->id)->where('warehouse_id', $this->main)->count(),
            'K76: exactly one open session for the owner at Main');
    }
}
