<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K54: a transfer from a warehouse to itself is accepted (map L5: from ≠ to).
 */
class K54TransferToItselfTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K54 — Main → Main is saved as a ₱690 transfer with two cancelling movements.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_transfer_must_go_to_another_warehouse()
    {
        $response = $this->as($this->owner)->transfer([$this->moveLine(3)], 'completed', $this->main, $this->main);

        $this->assertRefused($response, 'from and to are the same warehouse');
        $this->assertSame(0, DB::table('transfers')->count(), 'no transfer document is written');
        $this->assertSame(0, DB::table('stock_movements')->where('reference_type', 'Transfer')->count());
        $this->assertEquals(50, $this->shelf());
    }
}
