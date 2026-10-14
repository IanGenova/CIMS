<?php

namespace Tests\Feature;

use App\Http\Controllers\BaseController;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * The outgoing-mail (SMTP) password.
 *
 * It was stored as plain text and sent to the browser every time System
 * Settings opened. Now it is encrypted with the app key, never leaves the
 * server, and a blank box on save keeps the one already saved.
 */
class ServerSmtpPasswordTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['setting_system']);
        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC']);
        $this->id = Server::create(['host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'shop@gmail.com',
            'password' => 'app-pass-1234', 'encryption' => 'tls'])->id;
    }

    /** @test */
    public function the_password_is_encrypted_at_rest()
    {
        $raw = DB::table('servers')->where('id', $this->id)->value('password');

        $this->assertNotSame('app-pass-1234', $raw);
        $this->assertSame('app-pass-1234', Server::find($this->id)->password);
    }

    /** @test */
    public function the_settings_screen_never_receives_it()
    {
        $server = $this->getJson('/api/getSMTP')->assertOk()->json('server');

        $this->assertArrayNotHasKey('password', $server);
        $this->assertTrue($server['has_password']);
        $this->assertSame('smtp.gmail.com', $server['host']);
    }

    /** @test */
    public function saving_with_a_blank_password_keeps_the_saved_one()
    {
        $this->putJson("/api/SMTP/{$this->id}", ['host' => 'smtp.gmail.com', 'port' => 465, 'username' => 'shop@gmail.com',
            'password' => '', 'encryption' => 'ssl'])->assertOk();

        $this->assertSame('app-pass-1234', Server::find($this->id)->password);
        $this->assertSame(465, Server::find($this->id)->port);
    }

    /** @test */
    public function a_new_password_replaces_it()
    {
        $this->putJson("/api/SMTP/{$this->id}", ['host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'shop@gmail.com',
            'password' => 'new-app-pass', 'encryption' => 'tls'])->assertOk();

        $this->assertSame('new-app-pass', Server::find($this->id)->password);
    }

    /** @test */
    public function a_password_saved_before_encryption_still_works()
    {
        // An older install, or a database restored from an old backup.
        DB::table('servers')->where('id', $this->id)->update(['password' => 'old-plain-pass']);

        $this->assertSame('old-plain-pass', Server::find($this->id)->password);
        $this->getJson('/api/getSMTP')->assertOk();
    }

    /** @test */
    public function mail_is_sent_with_the_real_password()
    {
        $controller = new class extends BaseController {};
        $controller->Set_config_mail();

        $this->assertSame('app-pass-1234', config('mail.password'));
        $this->assertSame('smtp.gmail.com', config('mail.host'));
    }
}
