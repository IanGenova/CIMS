<?php

namespace Tests\Feature;

use App\utils\XenditCheckout;
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
 * Xendit, the payment gateway (it replaced Stripe, which charged in US dollars
 * and does not serve Philippine businesses).
 *
 * The cashier asks for an amount on a sale; Xendit hosts the checkout (GCash,
 * Maya, cards, QR Ph, banks, over the counter); the payment is recorded only
 * once Xendit itself says the money moved -- by its webhook, or when the
 * server asks. Never on the browser's word.
 *
 * Xendit is replaced by a scripted stand-in: every request the system sends
 * is captured and checked, and every answer is one Xendit documents.
 */
class XenditCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    const KEY = 'xnd_development_TestKeyForTheTestSuiteOnly000000000000';
    const TOKEN = 'webhook-token-for-tests';

    private $sent = [];
    private $saleId;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->actAsUserWith([
            'payment_sales_add', 'payment_sales_edit', 'payment_sales_delete', 'Sales_delete',
            'setting_system', 'record_view',
        ]);

        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC']);
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $client = DB::table('clients')->insertGetId(['name' => 'Juan Dela Cruz', 'code' => 1]);
        $this->saleId = DB::table('sales')->insertGetId([
            'user_id' => $this->user->id, 'date' => now()->toDateString(), 'Ref' => 'SL_0042', 'client_id' => $client,
            'warehouse_id' => $warehouse, 'GrandTotal' => 1500, 'paid_amount' => 0,
            'payment_statut' => 'unpaid', 'statut' => 'completed',
        ]);
    }

    protected function tearDown(): void
    {
        XenditCheckout::useHttp(null);
        parent::tearDown();
    }

    private function connect()
    {
        $this->postJson('/api/payment_gateway', ['secret_key' => self::KEY, 'webhook_token' => self::TOKEN])->assertOk();
    }

    private function xenditAnswers(array $responses)
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));
        XenditCheckout::useHttp(new Client(['handler' => $stack, 'http_errors' => false]));
    }

    private static function reply($status, array $body)
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private static function xsession($status, array $extra = [])
    {
        return self::reply(200, array_merge([
            'payment_session_id' => 'ps-6650f2c1a1b2c3d4e5f60718', 'reference_id' => 'x', 'status' => $status,
            'amount' => 1500, 'currency' => 'PHP', 'payment_link_url' => 'https://dev.xen.to/AbCd1234',
            'expires_at' => now()->addMinutes(30)->toIso8601ZuluString(),
            'payment_id' => null, 'payment_request_id' => null,
        ], $extra));
    }

    private function sentBody($i)
    {
        return json_decode((string) $this->sent[$i]['request']->getBody(), true);
    }

    private function startCheckout($amount = 1500)
    {
        $this->xenditAnswers([self::xsession('ACTIVE')]);

        return $this->postJson('/api/xendit/checkouts', ['sale_id' => $this->saleId, 'amount' => $amount]);
    }

    // ------------------------------------------------------------- settings

    /** @test */
    public function the_keys_are_stored_encrypted_and_never_sent_back()
    {
        $this->connect();

        $raw = DB::table('settings')->value('xendit_secret_key');
        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString('xnd_development', $raw);

        $gateway = $this->getJson('/api/Get_payment_gateway')->assertOk()->json('gateway');
        $this->assertTrue($gateway['has_secret_key']);
        $this->assertSame('test', $gateway['mode']);
        $this->assertSame('0000', $gateway['secret_key_hint']);
        $this->assertTrue($gateway['has_webhook_token']);
        $this->assertStringEndsWith('/api/xendit/webhook', $gateway['webhook_url']);
        $this->assertStringNotContainsString('TestKey', json_encode($gateway));
    }

    /** @test */
    public function saving_without_retyping_keeps_the_saved_keys_and_disconnect_removes_them()
    {
        $this->connect();
        $this->postJson('/api/payment_gateway', ['secret_key' => null, 'webhook_token' => null])->assertOk();
        $this->assertTrue(XenditCheckout::configured());

        $this->postJson('/api/payment_gateway', ['remove' => true])->assertOk();
        $this->assertFalse(XenditCheckout::configured());
    }

    /** @test */
    public function the_pos_wallet_buttons_use_the_same_saved_keys()
    {
        // Before: GCash/Maya at the till read their keys from .env only.
        config(['wallet.driver' => 'xendit', 'wallet.secret_key' => null, 'wallet.callback_token' => 'set-me-in-env']);
        $this->assertFalse(\App\utils\WalletPaymentService::isConfigured());

        $this->connect();

        $this->assertTrue(\App\utils\WalletPaymentService::isConfigured());
        $gateway = new \App\utils\Wallet\XenditGateway;
        $this->assertTrue($gateway->verifyCallback(self::TOKEN));
        $this->assertFalse($gateway->verifyCallback('guessed'));
    }

    /** @test */
    public function the_one_webhook_address_also_reaches_the_pos_wallet()
    {
        $this->connect();
        $this->xenditAnswers([]);
        $seen = [];
        \App\utils\WalletPaymentService::useGateway(new class($seen) extends \App\utils\Wallet\FakeGateway {
            private $seen;
            public function __construct(&$seen) { $this->seen = &$seen; }
            public function verifyCallback($token) { $this->seen[] = $token; return true; }
        });

        $this->postJson('/api/xendit/webhook', ['event' => 'payment.capture', 'data' => ['payment_request_id' => 'pr-9', 'status' => 'SUCCEEDED']],
            ['x-callback-token' => self::TOKEN])->assertOk();
        \App\utils\WalletPaymentService::useGateway(null);

        $this->assertSame([self::TOKEN], $seen);
    }

    // ------------------------------------------------------------- starting

    /** @test */
    public function a_checkout_asks_xendit_for_pesos_on_its_hosted_page()
    {
        $this->connect();
        $checkout = $this->startCheckout()->assertOk()->json('checkout');

        $request = $this->sent[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.xendit.co/sessions', (string) $request->getUri());
        $this->assertSame('Basic ' . base64_encode(self::KEY . ':'), $request->getHeaderLine('Authorization'));

        $body = $this->sentBody(0);
        $this->assertSame('PAY', $body['session_type']);
        $this->assertSame('PAYMENT_LINK', $body['mode']);
        $this->assertSame('PHP', $body['currency']);
        $this->assertSame('PH', $body['country']);
        $this->assertEquals(1500, $body['amount']);
        $this->assertStringContainsString('SL_0042', $body['reference_id']);
        $this->assertLessThanOrEqual(64, strlen($body['reference_id']));
        $this->assertStringContainsString('SL_0042', $body['description']);

        $this->assertSame('pending', $checkout['status']);
        $this->assertSame('https://dev.xen.to/AbCd1234', $checkout['payment_link_url']);
        $this->assertStringContainsString('<svg', $checkout['qr_svg']);
        $this->assertSame(0, DB::table('payment_sales')->count(), 'nothing is paid yet');
    }

    /** @test */
    public function it_cannot_ask_for_more_than_is_still_due()
    {
        $this->connect();
        $this->xenditAnswers([]);

        $this->postJson('/api/xendit/checkouts', ['sale_id' => $this->saleId, 'amount' => 1500.01])->assertStatus(422);
        $this->assertCount(0, $this->sent);
    }

    /** @test */
    public function without_xendit_set_up_the_cashier_is_told_so()
    {
        $this->xenditAnswers([]);

        $this->postJson('/api/xendit/checkouts', ['sale_id' => $this->saleId, 'amount' => 100])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Xendit is not set up yet. Add its secret key in Settings → System Settings → Payment Gateway.']);
    }

    /** @test */
    public function a_refusal_from_xendit_reaches_the_cashier_in_its_words()
    {
        $this->connect();
        $this->xenditAnswers([self::reply(400, ['error_code' => 'INVALID_AMOUNT', 'message' => 'Amount is below the minimum for the channels available'])]);

        $this->postJson('/api/xendit/checkouts', ['sale_id' => $this->saleId, 'amount' => 0.5])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Xendit refused: Amount is below the minimum for the channels available']);
    }

    // ------------------------------------------------------------- getting paid

    /** @test */
    public function when_xendit_says_completed_the_payment_is_recorded_once()
    {
        $this->connect();
        $id = $this->startCheckout()->json('checkout.id');

        $this->xenditAnswers([
            self::xsession('COMPLETED', ['payment_id' => 'py-1111', 'payment_request_id' => 'pr-2222']),
            self::reply(200, ['payment_id' => 'py-1111', 'status' => 'SUCCEEDED', 'channel_code' => 'GCASH', 'request_amount' => 1500,
                'captures' => [['capture_id' => 'cp-1', 'capture_amount' => 1500]]]),
        ]);
        $checkout = $this->getJson("/api/xendit/checkouts/{$id}")->assertOk()->json('checkout');

        $this->assertSame('paid', $checkout['status']);
        $this->assertSame('GCASH', $checkout['channel']);
        $this->assertSame('/v3/payments/py-1111', $this->sent[1]['request']->getUri()->getPath());

        $payment = DB::table('payment_sales')->first();
        $this->assertSame('Xendit', $payment->Reglement);
        $this->assertEquals(1500, $payment->montant);
        $this->assertSame($this->user->id, (int) $payment->user_id);
        $this->assertStringContainsString('GCASH', $payment->notes);
        $sale = DB::table('sales')->find($this->saleId);
        $this->assertEquals(1500, $sale->paid_amount);
        $this->assertSame('paid', $sale->payment_statut);

        // Asked again: already settled, Xendit is not asked and nothing doubles.
        $this->xenditAnswers([]);
        $this->getJson("/api/xendit/checkouts/{$id}")->assertOk();
        $this->assertSame(1, DB::table('payment_sales')->count());
        $this->assertCount(0, $this->sent);
    }

    /** @test */
    public function a_webhook_without_the_right_token_is_refused()
    {
        $this->connect();
        $this->startCheckout();
        $this->xenditAnswers([]);

        $this->postJson('/api/xendit/webhook', ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => 'ps-6650f2c1a1b2c3d4e5f60718']],
            ['x-callback-token' => 'guessed'])->assertStatus(401);

        $this->assertSame(0, DB::table('payment_sales')->count());
        $this->assertCount(0, $this->sent);
    }

    /** @test */
    public function a_genuine_webhook_is_checked_with_xendit_before_it_counts_and_counts_once()
    {
        $this->connect();
        $this->startCheckout();

        $completed = ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => 'ps-6650f2c1a1b2c3d4e5f60718', 'status' => 'COMPLETED']];
        $this->xenditAnswers([
            self::xsession('COMPLETED', ['payment_id' => 'py-1111', 'payment_request_id' => 'pr-2222']),
            self::reply(200, ['payment_id' => 'py-1111', 'status' => 'SUCCEEDED', 'channel_code' => 'PAYMAYA', 'captures' => [['capture_amount' => 1500]]]),
        ]);
        $this->postJson('/api/xendit/webhook', $completed, ['x-callback-token' => self::TOKEN])->assertOk();

        // Xendit retries; the second delivery changes nothing.
        $this->postJson('/api/xendit/webhook', $completed, ['x-callback-token' => self::TOKEN])->assertOk();

        $this->assertSame('/sessions/ps-6650f2c1a1b2c3d4e5f60718', $this->sent[0]['request']->getUri()->getPath());
        $this->assertSame(1, DB::table('payment_sales')->count());
        $this->assertSame('paid', DB::table('sales')->where('id', $this->saleId)->value('payment_statut'));
        $this->assertSame(1, DB::table('app_notifications')->where('type', 'xendit_paid')->where('user_id', $this->user->id)->count());
    }

    /** @test */
    public function a_webhook_claiming_completion_is_not_believed_over_xendit_itself()
    {
        $this->connect();
        $this->startCheckout();
        $this->xenditAnswers([self::xsession('ACTIVE')]);

        $this->postJson('/api/xendit/webhook', ['event' => 'payment_session.completed', 'data' => ['payment_session_id' => 'ps-6650f2c1a1b2c3d4e5f60718']],
            ['x-callback-token' => self::TOKEN])->assertOk();

        $this->assertSame(0, DB::table('payment_sales')->count());
    }

    /** @test */
    public function an_expired_checkout_records_nothing()
    {
        $this->connect();
        $id = $this->startCheckout()->json('checkout.id');
        $this->xenditAnswers([self::xsession('EXPIRED')]);

        $this->assertSame('expired', $this->getJson("/api/xendit/checkouts/{$id}")->json('checkout.status'));
        $this->assertSame(0, DB::table('payment_sales')->count());
    }

    // ------------------------------------------------------------- cancelling

    /** @test */
    public function the_cashier_can_cancel_a_checkout_nobody_paid()
    {
        $this->connect();
        $id = $this->startCheckout()->json('checkout.id');
        $this->xenditAnswers([self::xsession('CANCELED')]);

        $this->assertSame('cancelled', $this->postJson("/api/xendit/checkouts/{$id}/cancel")->assertOk()->json('checkout.status'));
        $this->assertSame('/sessions/ps-6650f2c1a1b2c3d4e5f60718/cancel', $this->sent[0]['request']->getUri()->getPath());
    }

    /** @test */
    public function paid_at_the_moment_of_cancelling_is_still_paid()
    {
        $this->connect();
        $id = $this->startCheckout()->json('checkout.id');
        $this->xenditAnswers([
            self::reply(422, ['error_code' => 'INVALID_SESSION_STATUS', 'message' => 'Only ACTIVE sessions can be canceled']),
            self::xsession('COMPLETED', ['payment_id' => 'py-1111', 'payment_request_id' => 'pr-2222']),
            self::reply(200, ['status' => 'SUCCEEDED', 'channel_code' => 'CARDS', 'captures' => [['capture_amount' => 1500]]]),
        ]);

        $this->assertSame('paid', $this->postJson("/api/xendit/checkouts/{$id}/cancel")->assertOk()->json('checkout.status'));
        $this->assertSame(1, DB::table('payment_sales')->count());
    }

    // ------------------------------------------------------------- after payment

    private function paidCheckout()
    {
        $this->connect();
        $id = $this->startCheckout()->json('checkout.id');
        $this->xenditAnswers([
            self::xsession('COMPLETED', ['payment_id' => 'py-1111', 'payment_request_id' => 'pr-2222']),
            self::reply(200, ['status' => 'SUCCEEDED', 'channel_code' => 'GCASH', 'captures' => [['capture_amount' => 1500]]]),
        ]);
        $this->getJson("/api/xendit/checkouts/{$id}");

        return DB::table('payment_sales')->value('id');
    }

    /** @test */
    public function deleting_a_xendit_payment_refunds_it_through_xendit()
    {
        $paymentId = $this->paidCheckout();
        $this->xenditAnswers([self::reply(200, ['id' => 'rfd-1', 'status' => 'PENDING', 'amount' => 1500])]);

        $this->deleteJson("/api/payment/sale/{$paymentId}")->assertOk();

        $body = $this->sentBody(0);
        $this->assertSame('/refunds', $this->sent[0]['request']->getUri()->getPath());
        $this->assertSame('pr-2222', $body['payment_request_id']);
        $this->assertSame('REQUESTED_BY_CUSTOMER', $body['reason']);
        $this->assertEquals(1500, $body['amount']);
        $this->assertNotNull(DB::table('payment_sales')->where('id', $paymentId)->value('deleted_at'));
        $this->assertSame('unpaid', DB::table('sales')->where('id', $this->saleId)->value('payment_statut'));
        $this->assertSame('rfd-1', DB::table('xendit_checkouts')->value('refund_id'));
    }

    /** @test */
    public function a_refund_xendit_refuses_keeps_the_payment_and_says_why()
    {
        $paymentId = $this->paidCheckout();
        $this->xenditAnswers([self::reply(403, ['error_code' => 'REFUND_NOT_SUPPORTED', 'message' => 'Refund is not supported for this channel'])]);

        $this->deleteJson("/api/payment/sale/{$paymentId}")
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'XENDIT_REFUND_REFUSED']);

        $this->assertNull(DB::table('payment_sales')->where('id', $paymentId)->value('deleted_at'));
        $this->assertSame('paid', DB::table('sales')->where('id', $this->saleId)->value('payment_statut'));
    }

    /** @test */
    public function refunded_another_way_it_can_be_removed_without_asking_xendit()
    {
        $paymentId = $this->paidCheckout();
        $this->xenditAnswers([]);

        $this->deleteJson("/api/payment/sale/{$paymentId}?refunded_outside=1")->assertOk();

        $this->assertCount(0, $this->sent);
        $this->assertNotNull(DB::table('payment_sales')->where('id', $paymentId)->value('deleted_at'));
    }

    /** @test */
    public function a_xendit_payment_cannot_be_typed_in_or_edited_by_hand()
    {
        $this->postJson('/api/payment/sale', ['sale_id' => $this->saleId, 'date' => now()->toDateString(),
            'montant' => 100, 'Reglement' => 'Xendit'])->assertStatus(422);
        $this->assertSame(0, DB::table('payment_sales')->count());

        $paymentId = $this->paidCheckout();
        $this->putJson("/api/payment/sale/{$paymentId}", ['sale_id' => $this->saleId, 'date' => now()->toDateString(),
            'montant' => 10, 'Reglement' => 'Cash'])->assertStatus(422);
        $this->assertEquals(1500, DB::table('payment_sales')->where('id', $paymentId)->value('montant'));
    }

    /** @test */
    public function a_sale_with_a_xendit_payment_still_held_cannot_be_deleted()
    {
        $this->paidCheckout();
        $this->xenditAnswers([]);

        $this->deleteJson("/api/sales/{$this->saleId}")->assertStatus(422);
        $this->assertNull(DB::table('sales')->where('id', $this->saleId)->value('deleted_at'));
    }
}
