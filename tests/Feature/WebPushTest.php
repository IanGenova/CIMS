<?php

namespace Tests\Feature;

use App\utils\NotificationService;
use App\utils\WebPush;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Notifications that reach a phone, or a computer whose browser is closed.
 *
 * Web Push: each notice is encrypted for the device (RFC 8291, aes128gcm),
 * signed with this server's VAPID key (RFC 8292), and handed to the device's
 * push service, which wakes the service worker. Built on PHP's OpenSSL, no
 * library; the encryption is checked against the RFC's own published example
 * so it is right by the standard, not merely consistent with itself.
 */
class WebPushTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $history = [];

    protected function tearDown(): void
    {
        WebPush::useHttp(null);
        parent::tearDown();
    }

    private static function b64u($s)
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    private function pushServiceAnswers(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        WebPush::useHttp(new Client(['handler' => $stack, 'http_errors' => false]));
    }

    // ------------------------------------------------------------- the crypto

    /** @test */
    public function the_encryption_matches_rfc_8291_appendix_a()
    {
        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            self::b64u('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            self::b64u('BTBZMqHH6r4Tts7J_aSIgg'),
            self::b64u('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
            self::b64u('DGv6ra1nlYgDCS1FRnbzlw')
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            rtrim(strtr(base64_encode($body), '+/', '-_'), '=')
        );
    }

    /** @test */
    public function the_vapid_signature_verifies_with_the_published_key()
    {
        $header = WebPush::vapidHeader('https://fcm.googleapis.com/fcm/send/abc');
        $this->assertMatchesRegularExpression('/^vapid t=([^,]+), k=([A-Za-z0-9_-]+)$/', $header);
        preg_match('/^vapid t=([^,]+), k=([A-Za-z0-9_-]+)$/', $header, $m);
        [$h, $c, $s] = explode('.', $m[1]);

        $claims = json_decode(self::b64u($c), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 86400, $claims['exp']);
        $this->assertSame($m[2], WebPush::publicKey());

        // The raw r||s signature back to DER, checked against the public key.
        $sig = self::b64u($s);
        $der = function ($i) { $i = ltrim($i, "\0"); if (ord($i[0]) > 0x7f) { $i = "\0" . $i; } return "\x02" . chr(strlen($i)) . $i; };
        $seq = $der(substr($sig, 0, 32)) . $der(substr($sig, 32));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . self::b64u($m[2])), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $this->assertSame(1, openssl_verify("$h.$c", "\x30" . chr(strlen($seq)) . $seq, $pem, OPENSSL_ALGO_SHA256));
    }

    // ------------------------------------------------------------- subscribing

    /** @test */
    public function a_device_subscribes_and_the_server_key_is_public()
    {
        $user = $this->actAsUserWith(['purchases_approve']);

        $key = $this->getJson('/api/push/key')->assertOk()->json('key');
        $this->assertSame(65, strlen(self::b64u($key)));

        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1',
            'keys' => ['p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'auth' => 'BTBZMqHH6r4Tts7J_aSIgg'],
        ])->assertOk();

        $this->assertSame($user->id, (int) DB::table('push_subscriptions')->value('user_id'));
    }

    /** @test */
    public function a_push_service_endpoint_must_be_https()
    {
        $this->actAsUserWith(['purchases_approve']);

        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'http://127.0.0.1:9/steal',
            'keys' => ['p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'auth' => 'BTBZMqHH6r4Tts7J_aSIgg'],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------- sending

    private function subscribe($userId, $endpoint)
    {
        DB::table('push_subscriptions')->insert([
            'user_id' => $userId, 'endpoint' => $endpoint,
            'p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'auth' => 'BTBZMqHH6r4Tts7J_aSIgg', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test */
    public function a_notice_is_pushed_to_the_devices_of_the_people_it_is_for()
    {
        $approver = $this->actAsUserWith(['purchases_approve']);
        $roleId = DB::table('roles')->insertGetId(['name' => 'stock', 'label' => 'Stock']);
        $stockman = \App\Models\User::create(['firstname' => 'S', 'lastname' => 'M', 'username' => 'sm', 'email' => 'sm@example.invalid',
            'password' => bcrypt('Str0ngEnough'), 'statut' => 1, 'role_id' => $roleId, 'phone' => '0917']);
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $stockman->id]);

        $this->subscribe($approver->id, 'https://fcm.googleapis.com/fcm/send/approver-phone');
        $this->subscribe($stockman->id, 'https://fcm.googleapis.com/fcm/send/stockman-phone');
        $this->pushServiceAnswers([new Response(201)]);

        $id = NotificationService::broadcast('purchases_approve', 'purchase_approval', 'PR_1118 needs approval',
            '12,639.86 from Luzon Builders Supply', '/app/purchases/approvals', 'warning');
        WebPush::sendForNotification($id);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('https://fcm.googleapis.com/fcm/send/approver-phone', (string) $request->getUri());
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        $this->assertGreaterThan(0, (int) $request->getHeaderLine('TTL'));
        $this->assertNotNull(DB::table('app_notifications')->where('id', $id)->value('pushed_at'));
    }

    /** @test */
    public function the_person_who_did_it_gets_no_push_either()
    {
        $approver = $this->actAsUserWith(['purchases_approve']);
        $this->subscribe($approver->id, 'https://fcm.googleapis.com/fcm/send/approver-phone');
        $this->pushServiceAnswers([]);

        $id = NotificationService::broadcast('purchases_approve', 'purchase_approval', 'PR_1 needs approval', null, null, 'info', null, $approver->id);
        WebPush::sendForNotification($id);

        $this->assertCount(0, $this->history);
    }

    /** @test */
    public function a_device_that_has_gone_away_is_forgotten()
    {
        $approver = $this->actAsUserWith(['purchases_approve']);
        $this->subscribe($approver->id, 'https://fcm.googleapis.com/fcm/send/old-phone');
        $this->pushServiceAnswers([new Response(410)]);

        WebPush::sendForNotification(NotificationService::toUser($approver->id, 'x', 'Hello'));

        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }
}
