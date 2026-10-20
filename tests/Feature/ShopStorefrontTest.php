<?php

namespace Tests\Feature;

use App\Models\product_warehouse;
use App\utils\ReservationService;
use App\utils\ShopPaymentService;
use App\utils\XenditCheckout;
use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\GrantsPermissions;

class ShopStorefrontTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private function fixture()
    {
        $warehouse = DB::table('warehouses')->insertGetId([
            'name' => 'Online fulfillment', 'code' => 'WEB', 'site_type' => 'central_warehouse',
        ]);
        DB::table('settings')->insert([
            'CompanyName' => 'Test Store', 'shop_name' => 'Test Store',
            'shop_enabled' => 1, 'shop_warehouse_id' => $warehouse,
        ]);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'SHOP', 'name' => 'Hardware']);
        $product = DB::table('products')->insertGetId([
            'code' => 'SHOP-001', 'Type_barcode' => 'CODE128', 'name' => 'Hammer',
            'cost' => 50, 'price' => 120, 'category_id' => $category,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2', 'web_published' => 1,
        ]);
        $hidden = DB::table('products')->insertGetId([
            'code' => 'PRIVATE-001', 'Type_barcode' => 'CODE128', 'name' => 'Private stock',
            'cost' => 50, 'price' => 120, 'category_id' => $category,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2', 'web_published' => 0,
        ]);
        $stock = product_warehouse::ensure($product, $warehouse);
        $stock->qte = 3;
        $stock->save();
        return compact('warehouse', 'product', 'hidden', 'unit');
    }

    private function checkout($product, $quantity, $key, $preorder = false)
    {
        return ['checkout_key' => $key, 'name' => 'Test Customer',
            'email' => 'customer@example.test', 'phone' => '09171234567',
            'address' => '123 Main Street', 'city' => 'Manila',
            'payment_method' => 'cash_on_delivery', 'allow_preorder' => $preorder,
            'items' => [['product_id' => $product, 'quantity' => $quantity]]];
    }

    /** @test */
    public function storefront_only_exposes_published_products_and_checkout_reserves_exact_stock()
    {
        $f = $this->fixture();
        $this->get('/shop')->assertOk()->assertSee('Test Store');
        $this->getJson('/shop/catalog')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.available', 3);
        $key = 'b8e7f375-7cd2-42b4-a5c9-6da3a8302333';
        $payload = $this->checkout($f['product'], 2, $key);
        $response = $this->postJson('/shop/checkout', $payload)->assertCreated()
            ->assertJsonPath('status', 'reserved');
        $id = $response->json('id');
        $url = $response->json('url');
        $this->assertEquals(2, ReservationService::quantity($f['warehouse'], $f['product']));
        $this->getJson('/shop/catalog')->assertJsonPath('products.0.available', 1);
        $this->postJson('/shop/checkout', $payload)->assertCreated()->assertJsonPath('id', $id);
        $this->assertEquals(1, DB::table('customer_orders')->count());
        $this->get($url)->assertOk()->assertSee('SHOP-001')->assertSee('₱240.00');
        $this->post('/shop/order/' . substr($url, strrpos($url, '/') + 1) . '/cancel')
            ->assertRedirect($url);
        $this->assertEquals(3, ReservationService::available($f['warehouse'], $f['product']));
        $this->assertSame('cancelled', DB::table('customer_orders')->where('id', $id)->value('status'));
    }

    /** @test */
    public function preorder_is_explicit_and_unpublished_items_cannot_be_ordered()
    {
        $f = $this->fixture();
        $this->postJson('/shop/checkout', $this->checkout($f['hidden'], 1,
            'b8e7f375-7cd2-42b4-a5c9-6da3a8302334'))->assertStatus(422);
        $this->postJson('/shop/checkout', $this->checkout($f['product'], 4,
            'b8e7f375-7cd2-42b4-a5c9-6da3a8302335'))->assertStatus(422);
        $this->postJson('/shop/checkout', $this->checkout($f['product'], 4,
            'b8e7f375-7cd2-42b4-a5c9-6da3a8302335', true))
            ->assertCreated()->assertJsonPath('status', 'pending_stock');
        $this->assertEquals(0, ReservationService::quantity($f['warehouse'], $f['product']));
    }

    /** @test */
    public function store_pickup_records_the_method_and_rejects_cash_on_delivery()
    {
        $f = $this->fixture();
        $pickup = $this->checkout($f['product'], 1,
            'b8e7f375-7cd2-42b4-a5c9-6da3a8302339');
        $pickup['fulfillment_method'] = 'store_pickup';
        $pickup['address'] = '';
        $this->postJson('/shop/checkout', $pickup)->assertStatus(422);
        $pickup['payment_method'] = 'pay_in_store';
        $id = $this->postJson('/shop/checkout', $pickup)->assertCreated()->json('id');
        $this->assertSame('store_pickup', DB::table('customer_orders')
            ->where('id', $id)->value('fulfillment_method'));
    }

    /** @test */
    public function xendit_payment_is_only_paid_after_provider_capture_is_verified()
    {
        $f = $this->fixture();
        $settings = Setting::first();
        $settings->xendit_secret_key = 'xnd_development_test_only';
        $settings->save();
        $payload = $this->checkout($f['product'], 1,
            'b8e7f375-7cd2-42b4-a5c9-6da3a8302336');
        $payload['payment_method'] = 'xendit';
        $orderResponse = $this->postJson('/shop/checkout', $payload)->assertCreated();
        $orderId = $orderResponse->json('id');
        $mock = new MockHandler([
            new Response(200, [], json_encode(['payment_session_id' => 'ps-test-123',
                'payment_link_url' => 'https://dev.xen.to/test',
                'expires_at' => now()->addMinutes(30)->toIso8601String()])),
            new Response(200, [], json_encode(['status' => 'COMPLETED',
                'payment_id' => 'py-test-123', 'payment_request_id' => 'pr-test-123'])),
            new Response(200, [], json_encode(['status' => 'SUCCEEDED',
                'currency' => 'PHP', 'captures' => [['capture_amount' => 120]]])),
            new Response(200, [], json_encode(['id' => 'rf-test-123', 'status' => 'PENDING'])),
        ]);
        XenditCheckout::useHttp(new Client(['handler' => HandlerStack::create($mock)]));
        try {
            $payment = ShopPaymentService::start($orderId, url('/shop/order/test'));
            $this->assertSame('pending', $payment->status);
            $this->assertSame('https://dev.xen.to/test', $payment->payment_link_url);
            $payment = ShopPaymentService::refresh($payment);
            $this->assertSame('paid', $payment->status);
            $this->assertSame('py-test-123', $payment->payment_id);
            $this->postJson('/shop/checkout', $payload)->assertCreated()->assertJsonPath('id', $orderId);

            $this->actAsUserWith(['Sales_add', 'Sales_view', 'Sales_edit', 'products_view']);
            $order = DB::table('customer_orders')->find($orderId);
            $line = DB::table('customer_order_lines')->where('customer_order_id', $orderId)->first();
            $saleBody = [
                'date' => now()->toDateString(), 'client_id' => $order->client_id,
                'warehouse_id' => $f['warehouse'], 'statut' => 'completed',
                'customer_order_id' => $orderId,
                'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'GrandTotal' => 120, 'amount' => 0, 'received_amount' => 0, 'change' => 0,
                'payment' => ['status' => 'pending', 'Reglement' => 'Xendit'],
                'details' => [[
                    'product_id' => $f['product'], 'product_variant_id' => null,
                    'customer_order_line_id' => $line->id,
                    'sale_unit_id' => $f['unit'], 'quantity' => 1,
                    'Unit_price' => 120, 'subtotal' => 120,
                    'discount' => 0, 'discount_Method' => '2',
                    'tax_percent' => 0, 'tax_method' => '2',
                ]],
            ];
            $saleId = $this->postJson('/api/sales', $saleBody)->assertOk()->json('id');
            $this->assertEquals(120, DB::table('sales')->where('id', $saleId)->value('paid_amount'));
            $this->assertSame('paid', DB::table('sales')->where('id', $saleId)->value('payment_statut'));
            $this->assertEquals(120, DB::table('payment_sales')->where('sale_id', $saleId)->value('montant'));
            $this->assertTrue(XenditCheckout::saleHoldsXenditMoney($saleId));
            $this->postJson('/api/customer_orders/' . $orderId . '/fulfillment',
                ['status' => 'ready'])->assertOk();
            $this->postJson('/api/customer_orders/' . $orderId . '/fulfillment',
                ['status' => 'delivered'])->assertStatus(422);
            $this->postJson('/api/customer_orders/' . $orderId . '/fulfillment',
                ['status' => 'dispatched', 'carrier' => 'Test carrier',
                    'tracking_reference' => 'TEST-123'])->assertOk();
            $this->postJson('/api/customer_orders/' . $orderId . '/fulfillment',
                ['status' => 'delivered'])->assertOk();
            $this->assertSame('delivered', DB::table('customer_orders')
                ->where('id', $orderId)->value('fulfillment_status'));
            $this->assertEquals(4, DB::table('customer_order_fulfillment_events')
                ->where('customer_order_id', $orderId)->count());
            $this->get($orderResponse->json('url'))->assertOk()->assertSee('TEST-123');
            \App\utils\ShopPaymentService::refund(DB::table('shop_payment_sessions')->find($payment->id));
            $this->assertSame('PENDING', DB::table('shop_payment_sessions')->where('id', $payment->id)->value('refund_status'));
            $this->assertFalse(XenditCheckout::saleHoldsXenditMoney($saleId));
        } finally {
            XenditCheckout::useHttp(null);
        }
    }

    /** @test */
    public function underpaid_provider_capture_is_held_for_review_and_cannot_be_cancelled()
    {
        $f = $this->fixture();
        $settings = Setting::first();
        $settings->xendit_secret_key = 'xnd_development_test_only';
        $settings->save();
        $payload = $this->checkout($f['product'], 1,
            'b8e7f375-7cd2-42b4-a5c9-6da3a8302337');
        $payload['payment_method'] = 'xendit';
        $response = $this->postJson('/shop/checkout', $payload)->assertCreated();
        $mock = new MockHandler([
            new Response(200, [], json_encode(['payment_session_id' => 'ps-review',
                'payment_link_url' => 'https://dev.xen.to/review'])),
            new Response(200, [], json_encode(['status' => 'COMPLETED', 'payment_id' => 'py-review'])),
            new Response(200, [], json_encode(['status' => 'SUCCEEDED', 'currency' => 'PHP',
                'captures' => [['capture_amount' => 100]]])),
            new Response(200, [], json_encode(['status' => 'COMPLETED', 'payment_id' => 'py-review'])),
            new Response(200, [], json_encode(['status' => 'SUCCEEDED', 'currency' => 'PHP',
                'captures' => [['capture_amount' => 120]]])),
        ]);
        XenditCheckout::useHttp(new Client(['handler' => HandlerStack::create($mock)]));
        try {
            $payment = ShopPaymentService::start($response->json('id'), $response->json('url'));
            $payment = ShopPaymentService::refresh($payment);
            $this->assertSame('review', $payment->status);
            $token = substr($response->json('url'), strrpos($response->json('url'), '/') + 1);
            $this->post('/shop/order/' . $token . '/cancel')->assertRedirect($response->json('url'));
            $this->assertSame('reserved', DB::table('customer_orders')->where('id', $response->json('id'))->value('status'));
            $this->actAsUserWith(['Sales_edit', 'Sales_view']);
            $this->getJson('/api/customer_orders/' . $response->json('id'))
                ->assertJsonPath('order.shop_payment.status', 'review')
                ->assertJsonPath('order.shop_payment.reference_id', $payment->reference_id);
            $this->postJson('/api/customer_orders/' . $response->json('id') . '/verify_payment')
                ->assertOk()->assertJsonPath('status', 'paid');
            $this->assertSame('paid', DB::table('shop_payment_sessions')
                ->where('id', $payment->id)->value('status'));
        } finally {
            XenditCheckout::useHttp(null);
        }
    }

    /** @test */
    public function xendit_order_cannot_be_fulfilled_before_the_payment_is_verified()
    {
        $f = $this->fixture();
        $settings = Setting::first();
        $settings->xendit_secret_key = 'xnd_development_test_only';
        $settings->save();
        $payload = $this->checkout($f['product'], 1,
            'b8e7f375-7cd2-42b4-a5c9-6da3a8302338');
        $payload['payment_method'] = 'xendit';
        $id = $this->postJson('/shop/checkout', $payload)->assertCreated()->json('id');
        $order = DB::table('customer_orders')->find($id);
        $line = DB::table('customer_order_lines')->where('customer_order_id', $id)->first();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        \App\utils\CustomerOrderService::validateSale($id, $order->client_id,
            $f['warehouse'], [[
                'customer_order_line_id' => $line->id, 'product_id' => $f['product'],
                'product_variant_id' => null, 'sale_unit_id' => $f['unit'],
                'quantity' => 1, 'Unit_price' => 120,
            ]]);
    }

    /** @test */
    public function administrators_can_choose_the_shop_location_and_disable_checkout()
    {
        $f = $this->fixture();
        $this->actAsUserWith(['setting_system']);
        $this->getJson('/api/shop/settings')->assertOk()
            ->assertJsonPath('shop_warehouse_id', $f['warehouse']);
        $this->putJson('/api/shop/settings', [
            'shop_enabled' => false, 'shop_warehouse_id' => $f['warehouse'],
            'shop_name' => 'Test Store',
        ])->assertOk()->assertJsonPath('shop_enabled', false);
        $this->getJson('/shop/catalog')->assertStatus(503);
        $this->putJson('/api/shop/settings', [
            'shop_enabled' => true, 'shop_warehouse_id' => $f['warehouse'],
            'shop_name' => 'Test Store',
        ])->assertOk()->assertJsonPath('shop_enabled', true);
        $this->getJson('/shop/catalog')->assertOk()->assertJsonPath('total', 1);
    }
}
