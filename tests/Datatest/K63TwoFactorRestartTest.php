<?php

namespace Tests\Datatest;

use App\utils\TotpService;
use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K63 — Restarting 2FA setup clears two_factor_confirmed_at without the
 * password, which turns 2FA off while "disable" demands the password.
 */
class K63TwoFactorRestartTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K63 — POST two_factor/start on an account with 2FA on switches 2FA off without any password.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_restarting_setup_cannot_turn_two_factor_off_without_the_password()
    {
        $secret = $this->actAs($this->owner)->postJson('/api/security/two_factor/start')->assertOk()->json('secret');
        $this->postJson('/api/security/two_factor/confirm', ['code' => TotpService::codeAt($secret)])->assertOk();
        $this->postJson('/api/security/two_factor/disable', ['password' => 'wrong'])->assertStatus(422); // fixture: disable needs it
        $this->assertNotNull(DB::table('users')->where('id', $this->owner->id)->value('two_factor_confirmed_at'));

        $response = $this->postJson('/api/security/two_factor/start');

        $this->assertRefused($response, 'restarting setup while 2FA is on must need the password, as disable does');
        $this->assertNotNull(DB::table('users')->where('id', $this->owner->id)->value('two_factor_confirmed_at'),
            'two-factor must still be on: sign-in would otherwise ask for the password only');
    }
}
