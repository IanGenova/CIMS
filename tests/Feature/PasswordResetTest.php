<?php

namespace Tests\Feature;

use App\Mail\Password_Reset_Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The password reset request, which was reaching nobody.
 *
 * phpunit.xml points the suite at sqlite :memory:, so these run against a
 * schema of their own and never touch the shop's database or its real
 * outgoing-mail settings.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /** @var string */
    private $email = 'pwreset-test@example.invalid';

    private function makeUser(): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'probe',
            'label' => 'Probe',
        ]);

        return User::create([
            'firstname' => 'Reset',
            'lastname' => 'Probe',
            'username' => 'pwreset_probe',
            'email' => $this->email,
            'password' => bcrypt('Str0ngEnough'),
            'statut' => 1,
            'role_id' => $roleId,
        ]);
    }

    /**
     * Set_config_mail() only applies when both rows exist, which is what makes
     * the reset mail go over SMTP at all.
     */
    private function mailSettings(string $host, int $port): void
    {
        DB::table('servers')->insert([
            'host' => $host,
            'port' => $port,
            'username' => 'probe',
            'password' => 'probe',
            'encryption' => '',
        ]);
        DB::table('settings')->insert([
            'email' => 'shop@example.invalid',
            'CompanyName' => 'Probe Hardware',
        ]);
    }

    /**
     * config/mail.php listed ses, mailgun, postmark, sendmail, log and array --
     * but not smtp, while 'default' falls back to 'smtp'. Anyone configuring
     * mail the documented way got "Mailer [smtp] is not defined."
     *
     * @test
     */
    public function the_smtp_mailer_is_defined()
    {
        $this->assertArrayHasKey(
            'smtp',
            Config::get('mail.mailers'),
            'config/mail.php must define an smtp mailer: mail.default falls back to it.'
        );
        $this->assertSame('smtp', Config::get('mail.mailers.smtp.transport'));
    }

    /**
     * The 'from' block was commented out, so every message left with no sender.
     *
     * @test
     */
    public function a_from_address_is_configured()
    {
        $this->assertNotNull(
            Config::get('mail.from.address'),
            'mail.from.address must not be null: SMTP servers reject a null sender.'
        );
    }

    /** @test */
    public function it_sends_a_reset_link_to_a_known_address()
    {
        Mail::fake();
        $this->makeUser();

        $response = $this->postJson('/api/password/create', ['email' => $this->email]);

        $response->assertStatus(200)->assertJson(['status' => true]);
        Mail::assertSent(Password_Reset_Request::class);
        $this->assertDatabaseHas('password_resets', ['email' => $this->email]);
    }

    /** @test */
    public function it_refuses_an_address_with_no_account()
    {
        $response = $this->postJson('/api/password/create', ['email' => 'nobody@example.invalid']);

        $response->assertStatus(200)->assertJson(['status' => false]);
        $this->assertDatabaseCount('password_resets', 0);
    }

    /**
     * A transport failure used to escape as a 500, and the sign-in screen
     * blamed authentication for every cause of it. It must come back as a
     * handled answer that names where to fix the problem.
     *
     * @test
     */
    public function it_reports_a_transport_failure_instead_of_throwing()
    {
        $this->makeUser();
        // A port nothing listens on: the same class of failure as the demo
        // mailtrap.io credentials that shipped with the system.
        $this->mailSettings('127.0.0.1', 1);

        $response = $this->postJson('/api/password/create', ['email' => $this->email]);

        $response->assertStatus(200)->assertJson(['status' => false]);
        $this->assertStringContainsString('SMTP', $response->json('message'));

        // No live reset link may be left behind for a mail that never went.
        $this->assertDatabaseMissing('password_resets', ['email' => $this->email]);
    }
}
