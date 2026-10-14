<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K58 — Uniqueness is not enforced on users (username, and email on profile
 * update), suppliers and currencies. Decision: exact duplicates ignoring case
 * and spaces are refused for usernames, emails and codes.
 */
class K58UniquenessTest extends DatatestCase
{
    use W3Helpers;

    private function profile($user, array $changes)
    {
        $row = DB::table('users')->find($user->id);

        return $this->actAs($user)->putJson("/api/updateProfile/{$user->id}", array_merge(['firstname' => $row->firstname,
            'lastname' => $row->lastname, 'username' => $row->username, 'email' => $row->email, 'phone' => '',
            'NewPassword' => 'undefined', 'avatar' => $row->avatar], $changes));
    }

    /**
     * K58 — A user can take another user's username through the profile screen.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_profile_screen_cannot_take_another_users_username()
    {
        $taken = $this->cashier->username;

        $response = $this->profile($this->nobody, ['username' => $taken]);

        $this->assertRefused($response, "username $taken belongs to the cashier");
        $this->assertSame(1, DB::table('users')->where('username', $taken)->count(), 'two users would share one username');
    }

    /**
     * K58 — A user can take another user's email (in capitals) through the profile screen.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_profile_screen_cannot_take_another_users_email_in_another_case()
    {
        $taken = $this->cashier->email;

        $response = $this->profile($this->nobody, ['email' => strtoupper($taken)]);

        $this->assertRefused($response, "email $taken belongs to the cashier, whatever its case");
        $this->assertSame(1, DB::table('users')->whereRaw('lower(email) = ?', [strtolower($taken)])->count());
    }

    /**
     * K58 — A new user is created with an existing username (create checks email only).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_new_user_cannot_reuse_an_existing_username()
    {
        $taken = $this->cashier->username;

        $response = $this->actAs($this->owner)->postJson('/api/users', ['firstname' => 'Ana', 'lastname' => 'Reyes',
            'username' => ' ' . strtoupper($taken) . ' ', 'email' => 'ana.reyes@example.invalid', 'password' => 'Str0ng-Enough!2026',
            'phone' => '0917', 'role' => $this->cashier->role_id, 'is_all_warehouses' => 1]);

        $this->assertRefused($response, "username $taken is taken (ignoring case and spaces)");
        $this->assertSame(0, DB::table('users')->where('email', 'ana.reyes@example.invalid')->count());
    }

    /**
     * K58 — A currency code that already exists is accepted again in another case with a trailing space.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_currency_code_cannot_be_duplicated_ignoring_case_and_spaces()
    {
        $this->currency('USD', 58);

        $response = $this->actAs($this->owner)->postJson('/api/currencies', ['code' => 'usd ', 'name' => 'US Dollar',
            'symbol' => '$', 'exchange_rate' => 57]);

        $this->assertRefused($response, 'USD already exists');
        $this->assertSame(1, DB::table('currencies')->whereNull('deleted_at')->whereRaw('upper(trim(code)) = ?', ['USD'])->count());
    }

    /**
     * K58 — A currency rate of 0.000000001 passes gt:0 and is stored as 0.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_currency_rate_that_rounds_to_zero_is_refused()
    {
        $response = $this->actAs($this->owner)->postJson('/api/currencies', ['code' => 'JPY', 'name' => 'Yen',
            'symbol' => 'Y', 'exchange_rate' => 0.000000001]);

        $this->assertRefused($response, 'a rate that is stored as 0 would price everything at nothing');
        $this->assertSame(0, DB::table('currencies')->where('code', 'JPY')->count());
    }

    /**
     * K58 — A second supplier is created with an existing supplier's email.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_supplier_email_cannot_be_duplicated()
    {
        $response = $this->actAs($this->owner)->postJson('/api/providers', $this->providerBody(['email' => 'L@EXAMPLE.INVALID']));

        $this->assertRefused($response, 'l@example.invalid is already Luzon Builders Supply');
        $this->assertSame(1, DB::table('providers')->whereRaw('lower(email) = ?', ['l@example.invalid'])->count());
    }
}
