<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K86 — "Force password change" is not enforced: the owner sets the flag
 * (Security → User security → Force password change), the sign-in answers
 * must_change_password=true, and nothing acts on it -- the flagged user goes on
 * using the whole API. LoginController::finishLogin stores
 * `password:must_change` in the session and nothing reads it.
 */
class K86ForcePasswordChangeTest extends DatatestCase
{
    use W5Helpers;

    /**
     * K86 — After the owner forces a password change, the cashier still reads
     * the sales list as if nothing happened (falsify-G7: the purchaser signed in
     * and loaded his purchase list). Only the password-change and
     * own-security endpoints should answer until a new password is set.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_forced_to_change_password_cannot_use_the_api_until_he_does()
    {
        $this->actAs($this->owner)->postJson("/api/security/users/{$this->cashier->id}/force_password_change")
            ->assertOk();
        $this->assertSame(1, (int) DB::table('users')->where('id', $this->cashier->id)->value('must_change_password'),
            'fixture: the flag is set through the owner\'s Security screen');

        $list = $this->actAs($this->cashier)->getJson('/api/sales?limit=10&page=1&SortField=id&SortType=desc&search=');
        $this->assertRefused($list, 'a user who must change his password must not read the sales list first');
    }
}
