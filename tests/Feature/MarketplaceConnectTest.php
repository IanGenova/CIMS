<?php

namespace Tests\Feature;

use App\utils\Marketplace\MarketplaceAdapter;
use App\utils\MarketplaceAuth;
use App\utils\MarketplaceSyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Connecting a Shopee or Lazada shop: the seller approves the app on the
 * marketplace, comes back with a one-time code, and the code becomes tokens.
 *
 * Before this the screen could add a shop but nothing could ever authorise it,
 * so no shop could sync. The platforms are not reachable from a test; every
 * request goes to a mocked HTTP client and is inspected here, signature
 * included.
 */
class MarketplaceConnectTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $user;
    private $history = [];
    private $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->actAsUserWith(['products_view', 'products_edit']);
        $this->warehouseId = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);

        config([
            'marketplace.redirect_base' => null,
            'marketplace.shopee.partner_id' => '2001',
            'marketplace.shopee.partner_key' => 'test-partner-key',
            'marketplace.shopee.host' => 'https://partner.test',
            'marketplace.lazada.app_key' => 'L100',
            'marketplace.lazada.app_secret' => 'lazada-secret',
            'marketplace.lazada.auth_url' => 'https://auth.lazada.test/oauth/authorize',
            'marketplace.lazada.auth_host' => 'https://auth.lazada.test/rest',
        ]);
    }

    protected function tearDown(): void
    {
        MarketplaceAuth::useHttp(null);
        MarketplaceSyncService::useAdapter(null);
        parent::tearDown();
    }

    private function account($channel = 'shopee', array $extra = [])
    {
        return DB::table('marketplace_accounts')->insertGetId(array_merge([
            'channel' => $channel, 'name' => ucfirst($channel) . ' Store',
            'region' => 'PH', 'warehouse_id' => $this->warehouseId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function platformAnswers(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        MarketplaceAuth::useHttp(new Client(['handler' => $stack, 'http_errors' => false]));
    }

    private function answer(array $body)
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** Ask for the link and return [url, query, state]. */
    private function connect($accountId)
    {
        $url = $this->postJson("/api/marketplace/accounts/{$accountId}/connect")->assertOk()->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $back = $query['redirect'] ?? $query['redirect_uri'];
        parse_str((string) parse_url($back, PHP_URL_QUERY), $backQuery);
        $state = $backQuery['state'] ?? $query['state'] ?? null;

        return [$url, $query, $state];
    }

    // ------------------------------------------------------------- the link

    /** @test */
    public function the_shopee_link_is_signed_and_carries_a_single_use_state()
    {
        $id = $this->account('shopee');
        [$url, $q, $state] = $this->connect($id);

        $this->assertStringStartsWith('https://partner.test/api/v2/shop/auth_partner?', $url);
        $this->assertSame('2001', $q['partner_id']);
        $this->assertSame(
            hash_hmac('sha256', '2001/api/v2/shop/auth_partner' . $q['timestamp'], 'test-partner-key'),
            $q['sign']
        );
        $this->assertStringStartsWith(url('/marketplace/callback/shopee') . '?state=', $q['redirect']);
        $this->assertGreaterThanOrEqual(40, strlen($state));

        // Only a hash of the state is kept, bound to this shop and this person.
        $row = DB::table('marketplace_auth_states')->first();
        $this->assertSame(hash('sha256', $state), $row->state_hash);
        $this->assertEquals($id, $row->marketplace_account_id);
        $this->assertEquals($this->user->id, $row->user_id);
    }

    /** @test */
    public function the_lazada_link_names_the_app_and_where_to_come_back()
    {
        $id = $this->account('lazada');
        [$url, $q, $state] = $this->connect($id);

        $this->assertStringStartsWith('https://auth.lazada.test/oauth/authorize?', $url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('L100', $q['client_id']);
        $this->assertSame(url('/marketplace/callback/lazada'), $q['redirect_uri']);
        $this->assertNotEmpty($state);
    }

    /** @test */
    public function no_link_without_the_partner_keys()
    {
        config(['marketplace.shopee.partner_key' => null]);
        $id = $this->account('shopee');

        $this->postJson("/api/marketplace/accounts/{$id}/connect")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Shopee is not set up: the partner id and key are missing.']);
        $this->assertSame(0, DB::table('marketplace_auth_states')->count());
    }

    // --------------------------------------------------------- the way back

    /** @test */
    public function a_valid_return_stores_the_tokens_encrypted()
    {
        $id = $this->account('shopee');
        [, , $state] = $this->connect($id);
        $this->platformAnswers([$this->answer([
            'access_token' => 'AT-1', 'refresh_token' => 'RT-1', 'expire_in' => 14400,
            'error' => '', 'message' => '',
        ])]);

        $this->actingAs($this->user)
            ->get("/marketplace/callback/shopee?state={$state}&code=CODE-9&shop_id=777")
            ->assertRedirect("/app/products/marketplace?connected={$id}");

        $account = DB::table('marketplace_accounts')->find($id);
        $this->assertSame('777', $account->external_shop_id);
        $this->assertNotSame('AT-1', $account->access_token);
        $this->assertSame('AT-1', Crypt::decryptString($account->access_token));
        $this->assertSame('RT-1', Crypt::decryptString($account->refresh_token));
        $this->assertEqualsWithDelta(now()->addSeconds(14400)->timestamp, strtotime($account->token_expires_at), 5);
        $this->assertNull($account->auth_error);

        // What was sent: the code, the shop, and a token request signed with the partner key.
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v2/auth/token/get', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $sent);
        $this->assertSame(hash_hmac('sha256', '2001/api/v2/auth/token/get' . $sent['timestamp'], 'test-partner-key'), $sent['sign']);
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['code' => 'CODE-9', 'shop_id' => 777, 'partner_id' => 2001], $body);
    }

    /** @test */
    public function a_state_cannot_be_used_twice()
    {
        $id = $this->account('shopee');
        [, , $state] = $this->connect($id);
        $this->platformAnswers([
            $this->answer(['access_token' => 'AT-1', 'refresh_token' => 'RT-1', 'expire_in' => 14400, 'error' => '']),
            $this->answer(['access_token' => 'AT-2', 'refresh_token' => 'RT-2', 'expire_in' => 14400, 'error' => '']),
        ]);

        $this->actingAs($this->user)->get("/marketplace/callback/shopee?state={$state}&code=A&shop_id=777");
        $second = $this->actingAs($this->user)->get("/marketplace/callback/shopee?state={$state}&code=B&shop_id=777");

        $this->assertStringContainsString('connect_error=', $second->headers->get('Location'));
        $this->assertCount(1, $this->history);
        $this->assertSame('AT-1', Crypt::decryptString(DB::table('marketplace_accounts')->find($id)->access_token));
    }

    /** @test */
    public function an_expired_state_is_refused()
    {
        $id = $this->account('shopee');
        [, , $state] = $this->connect($id);
        $this->platformAnswers([]);
        $this->travel(16)->minutes();

        $response = $this->actingAs($this->user)->get("/marketplace/callback/shopee?state={$state}&code=A&shop_id=777");

        $this->assertStringContainsString('connect_error=', $response->headers->get('Location'));
        $this->assertCount(0, $this->history);
        $this->assertNull(DB::table('marketplace_accounts')->find($id)->access_token);
    }

    /** @test */
    public function an_unknown_state_is_refused()
    {
        $id = $this->account('shopee');
        $this->platformAnswers([]);

        $response = $this->actingAs($this->user)->get('/marketplace/callback/shopee?state=forged&code=A&shop_id=777');

        $this->assertStringContainsString('connect_error=', $response->headers->get('Location'));
        $this->assertCount(0, $this->history);
        $this->assertNull(DB::table('marketplace_accounts')->find($id)->access_token);
    }

    /** @test */
    public function a_state_started_by_someone_else_is_refused()
    {
        $id = $this->account('shopee');
        [, , $state] = $this->connect($id);
        $this->platformAnswers([]);

        $other = \App\Models\User::create([
            'firstname' => 'Other', 'lastname' => 'Person', 'username' => 'other_person',
            'email' => 'other@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $this->user->role_id,
        ]);
        // Same role, so it is the state check refusing them, not a permission.
        DB::table('role_user')->insert(['role_id' => $this->user->role_id, 'user_id' => $other->id]);

        $response = $this->actingAs($other)->get("/marketplace/callback/shopee?state={$state}&code=A&shop_id=777");

        $this->assertStringContainsString('connect_error=', $response->headers->get('Location'));
        $this->assertCount(0, $this->history);
    }

    /** @test */
    public function a_refusal_from_the_platform_is_reported_and_nothing_is_stored()
    {
        $id = $this->account('shopee');
        [, , $state] = $this->connect($id);
        $this->platformAnswers([$this->answer(['error' => 'error_auth', 'message' => 'Invalid code'])]);

        $response = $this->actingAs($this->user)->get("/marketplace/callback/shopee?state={$state}&code=BAD&shop_id=777");

        $this->assertStringContainsString('connect_error=', $response->headers->get('Location'));
        $account = DB::table('marketplace_accounts')->find($id);
        $this->assertNull($account->access_token);
        $this->assertStringContainsString('Invalid code', $account->auth_error);
    }

    /** @test */
    public function a_shop_already_connected_elsewhere_is_refused()
    {
        $this->account('shopee', ['name' => 'First', 'external_shop_id' => '777']);
        $id = $this->account('shopee', ['name' => 'Second']);
        [, , $state] = $this->connect($id);
        $this->platformAnswers([]);

        $response = $this->actingAs($this->user)->get("/marketplace/callback/shopee?state={$state}&code=A&shop_id=777");

        $this->assertStringContainsString('connect_error=', $response->headers->get('Location'));
        $this->assertCount(0, $this->history);
        $this->assertNull(DB::table('marketplace_accounts')->find($id)->external_shop_id);
    }

    /** @test */
    public function the_lazada_code_becomes_tokens_and_the_seller_id_becomes_the_shop()
    {
        $id = $this->account('lazada');
        [, , $state] = $this->connect($id);
        $this->platformAnswers([$this->answer([
            'code' => '0', 'access_token' => 'LAT', 'refresh_token' => 'LRT',
            'expires_in' => 604800, 'refresh_expires_in' => 2592000,
            'country_user_info' => [['country' => 'ph', 'seller_id' => '5005', 'user_id' => '1']],
        ])]);

        $this->actingAs($this->user)
            ->get("/marketplace/callback/lazada?state={$state}&code=LZ-CODE")
            ->assertRedirect("/app/products/marketplace?connected={$id}");

        $account = DB::table('marketplace_accounts')->find($id);
        $this->assertSame('5005', $account->external_shop_id);
        $this->assertSame('LAT', Crypt::decryptString($account->access_token));

        $request = $this->history[0]['request'];
        $this->assertSame('/rest/auth/token/create', $request->getUri()->getPath());
        parse_str((string) $request->getBody(), $sent);
        if (! $sent) {
            parse_str($request->getUri()->getQuery(), $sent);
        }
        $this->assertSame('LZ-CODE', $sent['code']);
        $signed = $sent;
        unset($signed['sign']);
        ksort($signed);
        $base = '/auth/token/create';
        foreach ($signed as $k => $v) {
            $base .= $k . $v;
        }
        $this->assertSame(strtoupper(hash_hmac('sha256', $base, 'lazada-secret')), $sent['sign']);
    }

    // -------------------------------------------------------- staying connected

    /** @test */
    public function the_screen_never_sees_a_token()
    {
        $id = $this->account('shopee', [
            'external_shop_id' => '777',
            'access_token' => Crypt::encryptString('AT-SECRET'),
            'refresh_token' => Crypt::encryptString('RT-SECRET'),
            'token_expires_at' => now()->addHours(3),
        ]);

        $response = $this->getJson('/api/marketplace')->assertOk();
        $raw = $response->getContent();

        $this->assertStringNotContainsString('AT-SECRET', $raw);
        $this->assertStringNotContainsString('RT-SECRET', $raw);
        $this->assertStringNotContainsString(DB::table('marketplace_accounts')->find($id)->access_token, $raw);
        $shop = collect($response->json('accounts'))->firstWhere('id', $id);
        $this->assertTrue($shop['authorised']);
    }

    /** @test */
    public function a_sync_renews_a_token_about_to_expire_and_hands_the_adapter_the_new_one()
    {
        $id = $this->account('shopee', [
            'external_shop_id' => '777',
            'access_token' => Crypt::encryptString('OLD'),
            'refresh_token' => Crypt::encryptString('RT-OLD'),
            'token_expires_at' => now()->addMinute(),
        ]);
        $this->platformAnswers([$this->answer([
            'access_token' => 'NEW', 'refresh_token' => 'RT-NEW', 'expire_in' => 14400, 'error' => '',
        ])]);
        $adapter = new RecordingAdapter;
        MarketplaceSyncService::useAdapter($adapter);

        $result = MarketplaceSyncService::importOrders($id);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('/api/v2/auth/access_token/get', $this->history[0]['request']->getUri()->getPath());
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(['refresh_token' => 'RT-OLD', 'shop_id' => 777, 'partner_id' => 2001], $body);
        $this->assertSame('NEW', $adapter->seen['access_token']);
        $this->assertSame('RT-NEW', Crypt::decryptString(DB::table('marketplace_accounts')->find($id)->refresh_token));
    }

    /** @test */
    public function a_failed_renewal_stops_the_sync_and_asks_for_a_reconnect()
    {
        $id = $this->account('shopee', [
            'external_shop_id' => '777',
            'access_token' => Crypt::encryptString('OLD'),
            'refresh_token' => Crypt::encryptString('RT-OLD'),
            'token_expires_at' => now()->subMinute(),
        ]);
        $this->platformAnswers([$this->answer(['error' => 'error_auth', 'message' => 'Refresh token expired'])]);
        $adapter = new RecordingAdapter;
        MarketplaceSyncService::useAdapter($adapter);

        $result = MarketplaceSyncService::importOrders($id);

        $this->assertArrayHasKey('error', $result);
        $this->assertNull($adapter->seen);
        $this->assertStringContainsString('Reconnect', DB::table('marketplace_accounts')->find($id)->auth_error);

        // And whoever looks after the catalogue is told, not just the record.
        $bell = collect($this->getJson('/api/notifications')->assertOk()->json('notifications'));
        $this->assertTrue($bell->contains(fn ($n) => str_contains($n['title'], 'Shopee Store needs reconnecting')));
    }

    /** @test */
    public function a_newly_held_order_is_announced_once_not_on_every_poll()
    {
        $id = $this->account('shopee', [
            'external_shop_id' => '777',
            'access_token' => Crypt::encryptString('STILL-GOOD'),
            'refresh_token' => Crypt::encryptString('RT'),
            'token_expires_at' => now()->addHours(3),
        ]);
        $this->platformAnswers([]);
        MarketplaceSyncService::useAdapter(new OneUnmappedOrderAdapter);

        MarketplaceSyncService::importOrders($id);
        MarketplaceSyncService::importOrders($id); // the same order, polled again

        $bell = collect($this->getJson('/api/notifications')->assertOk()->json('notifications'));
        $this->assertCount(1, $bell->filter(fn ($n) => str_contains($n['title'], 'Shopee order(s) waiting')));
    }

    /** @test */
    public function a_token_with_hours_left_is_used_as_it_is()
    {
        $id = $this->account('shopee', [
            'external_shop_id' => '777',
            'access_token' => Crypt::encryptString('STILL-GOOD'),
            'refresh_token' => Crypt::encryptString('RT'),
            'token_expires_at' => now()->addHours(3),
        ]);
        $this->platformAnswers([]);
        $adapter = new RecordingAdapter;
        MarketplaceSyncService::useAdapter($adapter);

        MarketplaceSyncService::pushStock($id);
        MarketplaceSyncService::importOrders($id);

        $this->assertCount(0, $this->history);
        $this->assertSame('STILL-GOOD', $adapter->seen['access_token']);
    }
}

/** Remembers the account it was handed; never reaches a marketplace. */
class RecordingAdapter implements MarketplaceAdapter
{
    public $seen = null;

    public function fetchOrders(array $account, $since)
    {
        $this->seen = $account;

        return ['ok' => true, 'orders' => [], 'error' => null];
    }

    public function pushStock(array $account, array $listing, $quantity)
    {
        $this->seen = $account;

        return ['ok' => true, 'error' => null];
    }
}

/** One order whose SKU nobody has mapped, the same on every poll. */
class OneUnmappedOrderAdapter implements MarketplaceAdapter
{
    public function fetchOrders(array $account, $since)
    {
        return ['ok' => true, 'error' => null, 'orders' => [[
            'external_order_id' => 'SHP-1', 'status' => 'open', 'external_status' => 'READY_TO_SHIP',
            'ordered_at' => now()->toDateTimeString(), 'total' => 150.0, 'currency' => 'PHP', 'buyer_name' => 'juan',
            'lines' => [['sku' => 'NOT-MAPPED', 'quantity' => 1, 'unit_price' => 150.0]], 'raw' => [],
        ]]];
    }

    public function pushStock(array $account, array $listing, $quantity)
    {
        return ['ok' => true, 'error' => null];
    }
}
