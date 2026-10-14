<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K64 — The self-deactivation guard compares the body `id` (absent) with the
 * current user, not the route id.
 */
class K64SelfDeactivationTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K64 — The owner deactivates their own account through PUT users/Activated/{own id} without "id" in the body.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_cannot_deactivate_their_own_account()
    {
        $response = $this->actAs($this->owner)->putJson("/api/users/Activated/{$this->owner->id}", ['statut' => 0]);

        $this->assertNotTrue((bool) $response->json('success'), 'deactivating yourself must be refused');
        $this->assertSame(1, (int) DB::table('users')->where('id', $this->owner->id)->value('statut'),
            'the owner locked themselves out');
    }
}
