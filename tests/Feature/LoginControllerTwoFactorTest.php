<?php

namespace Tests\Feature;

use App\Models\User;
use App\utils\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Signing in with two-factor turned on.
 *
 * The server half was built: a correct password holds the sign-in and answers
 * `two_factor_required`, and /two-factor-challenge completes it. The sign-in
 * page never asked for the code -- it reloaded as if signed in, and the person
 * landed back on the sign-in page, locked out of their own account. This pins
 * the server half the page now relies on (tests/js/signInFlow.test.js covers
 * the page's decision).
 */
class LoginControllerTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $roleId = DB::table('roles')->insertGetId(['name' => 'Cashier', 'label' => 'Cashier']);
        $this->secret = TotpService::generateSecret();
        $user = User::create(['firstname' => 'Ana', 'lastname' => 'Reyes', 'username' => 'ana', 'email' => 'ana@example.invalid',
            'password' => bcrypt('Str0ng-Enough!'), 'statut' => 1, 'role_id' => $roleId, 'phone' => '0917']);
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);
        DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => encrypt($this->secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['ABCD-EFGH-1234'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function password()
    {
        return $this->postJson('/login', ['email' => 'ana@example.invalid', 'password' => 'Str0ng-Enough!']);
    }

    /** @test */
    public function the_password_alone_does_not_sign_in()
    {
        $this->password()->assertOk()->assertJson(['two_factor_required' => true]);

        $this->assertGuest();
    }

    /** @test */
    public function the_authenticator_code_completes_the_sign_in()
    {
        $this->password();

        $this->postJson('/two-factor-challenge', ['code' => TotpService::codeAt($this->secret)])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertAuthenticated();
    }

    /** @test */
    public function a_recovery_code_works_once()
    {
        $this->password();
        $this->postJson('/two-factor-challenge', ['code' => 'abcd-efgh-1234'])->assertOk();
        $this->assertAuthenticated();

        auth()->logout();
        $this->password();
        $this->postJson('/two-factor-challenge', ['code' => 'ABCD-EFGH-1234'])->assertStatus(422);
    }

    /** @test */
    public function a_wrong_code_is_refused_in_words()
    {
        $this->password();

        $this->postJson('/two-factor-challenge', ['code' => '000000'])
            ->assertStatus(422)->assertJsonFragment(['code' => ['That code is not right.']]);
        $this->assertGuest();
    }
}
