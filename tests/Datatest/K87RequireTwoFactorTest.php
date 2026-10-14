<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K87 — The security policy "Require two-factor for every account" is not
 * enforced: with it switched on, a user who never set up two-factor signs in
 * and uses the app normally. The sign-in answers two_factor_setup_required=true
 * and nothing acts on it.
 */
class K87RequireTwoFactorTest extends DatatestCase
{
    use W5Helpers;

    /**
     * K87 — With "require two-factor" on, the cashier (no 2FA set up) still reads
     * the sales list; only the own-security endpoints (2FA setup) should answer.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_without_two_factor_cannot_use_the_api_when_the_policy_requires_it()
    {
        $policy = (array) DB::table('security_settings')->first();
        $this->actAs($this->owner)->putJson('/api/security/policy', array_merge($policy, ['require_two_factor' => true]))
            ->assertOk();
        $this->assertSame(1, (int) DB::table('security_settings')->value('require_two_factor'),
            'fixture: the owner switched the policy on through the Security screen');
        $this->assertNull(DB::table('users')->where('id', $this->cashier->id)->value('two_factor_confirmed_at'),
            'fixture: the cashier has no two-factor');

        $list = $this->actAs($this->cashier)->getJson('/api/sales?limit=10&page=1&SortField=id&SortType=desc&search=');

        $this->assertRefused($list, 'a user without two-factor must set it up before using the app when the policy requires it');
    }
}
