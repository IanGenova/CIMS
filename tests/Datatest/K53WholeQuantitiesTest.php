<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K53: fractional quantities of piece-counted goods are accepted everywhere.
 * Default applied: whole numbers only, unless the unit is marked as divisible
 * (kg, m, L). A bag of cement is not divisible.
 */
class K53WholeQuantitiesTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K53 — the till sells half a bag.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_till_refuses_half_a_bag()
    {
        $response = $this->as($this->owner)->pos([$this->line(0.5)]);

        $this->assertRefused($response, 'a bag is sold whole');
        $this->assertEquals(50, $this->shelf());
        $this->assertSame(0, DB::table('sales')->count());
    }

    /**
     * K53 — a transfer moves a quarter of a bag.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_transfer_refuses_a_quarter_bag()
    {
        $response = $this->as($this->owner)->transfer([$this->moveLine(0.25)]);

        $this->assertRefused($response, 'a bag moves whole');
        $this->assertEquals(50, $this->shelf($this->main));
        $this->assertEquals(20, $this->shelf($this->branch));
    }

    /**
     * K53 — an adjustment adds a third of a bag.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_adjustment_refuses_a_third_of_a_bag()
    {
        $response = $this->as($this->owner)->adjust([$this->adjLine(0.333333333, 'add')]);

        $this->assertRefused($response, 'a bag is counted whole');
        $this->assertEquals(50, $this->shelf());
    }
}
