<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K67 — An inactive user gets a 500 "RequestGuard::logout does not exist"
 * instead of a 403, and GET /api/user still answers 200 (map A3).
 */
class K67InactiveUserTest extends DatatestCase
{
    use W3Helpers;

    private function inactiveOwner()
    {
        DB::table('users')->where('id', $this->owner->id)->update(['statut' => 0]);
        $this->owner->statut = 0;

        return $this->owner;
    }

    /**
     * K67 — An inactive user's write is answered with a 500 instead of a 403.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_inactive_user_is_refused_with_403()
    {
        $units = DB::table('units')->count();

        $response = $this->actAs($this->inactiveOwner())->postJson('/api/units', ['name' => 'zzU', 'ShortName' => 'zz']);

        $this->assertForbiddenStatus($response, 'A3: an inactive account is told it is inactive');
        $this->assertSame($units, DB::table('units')->count(), 'a refusal writes nothing');
    }

    /**
     * K67 — GET /api/user answers 200 with the profile of an inactive user.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_inactive_user_cannot_read_their_profile_through_the_api()
    {
        $response = $this->actAs($this->inactiveOwner())->getJson('/api/user');

        $this->assertForbiddenStatus($response, 'A3: an inactive user cannot use the API');
    }
}
