<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The front door.
 *
 * This file shipped as the stock Laravel scaffold, asserting that GET /
 * returns 200. It never did in this application: / is the Vue catch-all behind
 * the auth and Is_Active middleware, so a guest is sent to /login. The test had
 * been failing since the day the route was written, which is worse than having
 * no test -- a suite with a permanent red in it stops being read.
 *
 * It now asserts what the route actually promises, in both directions.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(int $statut = 1): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'owner_probe',
            'label' => 'Owner Probe',
        ]);

        return User::create([
            'firstname' => 'Front',
            'lastname' => 'Door',
            'username' => 'front_door_probe',
            'email' => 'frontdoor@example.invalid',
            'password' => bcrypt('Str0ngEnough'),
            'statut' => $statut,
            'role_id' => $roleId,
        ]);
    }

    /** @test */
    public function a_guest_is_sent_to_the_sign_in_screen()
    {
        $this->get('/')->assertRedirect('/login');
    }

    /** @test */
    public function the_sign_in_screen_is_reachable_without_signing_in()
    {
        // The form itself is drawn by Vue, so "Sign In" is not in the HTML the
        // server sends. What the server promises is the mount point and the
        // bundle that fills it.
        $this->get('/login')
            ->assertStatus(200)
            ->assertSee('<login-component>', false)
            ->assertSee('/js/login.min.js', false);
    }

    /** @test */
    public function a_signed_in_user_is_served_the_application()
    {
        $this->actingAs($this->makeUser())
            ->get('/')
            ->assertStatus(200);
    }

    /**
     * Is_Active is the middleware that keeps a deactivated account out. Marking
     * someone inactive rather than deleting them is what the guide tells an
     * owner to do, so it has to actually shut the door.
     *
     * @test
     */
    public function a_deactivated_user_is_not_served_the_application()
    {
        $this->actingAs($this->makeUser(0))
            ->get('/')
            ->assertStatus(302);
    }
}
