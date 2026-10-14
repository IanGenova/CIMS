<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K37 — MySQL strict mode is off: invalid dates are stored as 0000-00-00,
 * out-of-range numbers are clamped and long strings truncated, all with a 200.
 */
class K37NonStrictStorageTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K37 — An adjustment dated 2026-02-30 is saved as 0000-00-00 while the stock moves.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_adjustment_with_an_impossible_date_is_refused()
    {
        $response = $this->actAs($this->owner)->adjust([$this->adjLine(1)], ['date' => '2026-02-30']);

        $this->assertRefused($response, '30 February is not a date');
        $this->assertSame(0, DB::table('adjustments')->count(), 'no adjustment may be stored as 0000-00-00');
        $this->assertEquals(50, $this->shelf($this->main), 'no stock may move under an impossible date');
    }

    /**
     * K37 — A transfer dated 2026-02-30 is saved as 0000-00-00 while the stock moves.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_transfer_with_an_impossible_date_is_refused()
    {
        $response = $this->actAs($this->owner)->transfer([$this->moveLine(1)], ['date' => '2026-02-30']);

        $this->assertRefused($response, '30 February is not a date');
        $this->assertSame(0, DB::table('transfers')->count(), 'no transfer may be stored as 0000-00-00');
        $this->assertEquals(50, $this->shelf($this->main));
    }

    /**
     * K37 — An expense dated "hello" is saved as 0000-00-00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_expense_with_a_word_for_a_date_is_refused()
    {
        $response = $this->actAs($this->owner)->expense(['date' => 'hello']);

        $this->assertRefused($response, '"hello" is not a date');
        $this->assertSame(0, DB::table('expenses')->count(), 'no expense may be stored as 0000-00-00');
    }

    /**
     * K37 — An expense of 99,999,999,999,999,999 is clamped to the column maximum and saved.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_expense_amount_beyond_the_column_is_refused_not_clamped()
    {
        $response = $this->actAs($this->owner)->expense(['amount' => 99999999999999999]);

        $this->assertRefused($response, 'an amount the column cannot hold must be refused');
        $this->assertSame(0, DB::table('expenses')->count(), 'no clamped amount may be stored');
    }

    /**
     * K37 — A 300-character customer name is truncated to 191 characters and saved.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_customer_name_longer_than_the_column_is_refused_not_truncated()
    {
        $response = $this->actAs($this->owner)->postJson('/api/clients', $this->clientBody(['name' => str_repeat('N', 300)]));

        $this->assertRefused($response, 'a name the column cannot hold must be refused');
        $this->assertSame(0, DB::table('clients')->where('name', 'like', 'NNNN%')->count(), 'no truncated name may be stored');
    }
}
