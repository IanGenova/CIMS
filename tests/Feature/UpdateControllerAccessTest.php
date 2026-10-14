<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * The "update" screens only open for a signed-in administrator.
 *
 * Found preparing CIMS for a public HTTPS address: /update and
 * POST /update/lastStep sat outside the signed-in routes. Anyone on the
 * internet could load the page for a form token, then make the server run
 * config:cache and migrate --force, and read the raw error back.
 */
class UpdateControllerAccessTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function a_visitor_who_is_not_signed_in_is_sent_to_the_sign_in_page_and_nothing_runs()
    {
        Artisan::shouldReceive('call')->never();

        $this->get('/update')->assertRedirect('/login');
        $this->post('/update/lastStep')->assertRedirect('/login');
    }

    /** @test */
    public function a_signed_in_user_without_system_settings_is_refused_and_nothing_runs()
    {
        // Every install has this permission; the cashier's role just lacks it.
        \Illuminate\Support\Facades\DB::table('permissions')->insert(['name' => 'setting_system', 'label' => 'setting_system']);
        $user = $this->actAsUserWith(['Pos_view']);
        $this->actingAs($user);
        Artisan::shouldReceive('call')->never();

        $this->get('/update')->assertForbidden();
        $this->post('/update/lastStep')->assertForbidden();
    }

    /** @test */
    public function an_administrator_can_still_run_the_update()
    {
        $user = $this->actAsUserWith(['setting_system']);
        $this->actingAs($user);
        Artisan::shouldReceive('call')->with('migrate --force')->once()->andReturn(0);
        Artisan::shouldReceive('call')->withAnyArgs()->andReturn(0);

        $this->get('/update')->assertOk();
        $this->post('/update/lastStep')->assertOk();
    }

    /** @test */
    public function a_failed_update_does_not_show_its_internals()
    {
        $user = $this->actAsUserWith(['setting_system']);
        $this->actingAs($user);
        Artisan::shouldReceive('call')->andThrow(new \RuntimeException('SQLSTATE[HY000] secret-db-host:3306'));

        $response = $this->post('/update/lastStep');

        $this->assertStringNotContainsString('secret-db-host', $response->getContent());
        $this->assertStringContainsString('Something went wrong', $response->getContent());
    }
}
