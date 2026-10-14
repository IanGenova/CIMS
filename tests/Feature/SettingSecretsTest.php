<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The payment gateway's keys sit in the settings row. They are encrypted with
 * the app key at rest, and the row never carries them into a JSON response,
 * whatever screen asks for the settings.
 */
class SettingSecretsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function xendit_keys_are_encrypted_at_rest_and_never_serialised()
    {
        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC']);

        $setting = Setting::first();
        $setting->xendit_secret_key = 'xnd_development_abc123';
        $setting->xendit_webhook_token = 'tok-456';
        $setting->save();

        $raw = DB::table('settings')->first();
        $this->assertStringNotContainsString('xnd_development_abc123', (string) $raw->xendit_secret_key);
        $this->assertStringNotContainsString('tok-456', (string) $raw->xendit_webhook_token);

        $fresh = Setting::first();
        $this->assertSame('xnd_development_abc123', $fresh->xendit_secret_key);
        $this->assertSame('tok-456', $fresh->xendit_webhook_token);

        $json = json_encode($fresh);
        $this->assertStringNotContainsString('xendit', $json);
    }
}
