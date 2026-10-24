<?php

namespace Tests\Feature;

use App\utils\BatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

class CustomerOrderReservationTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function online_retry_preorder_and_reserved_sale_do_not_sell_the_same_last_lot_twice()
    {
        $this->actAsUserWith(['Sales_add', 'Sales_view', 'Sales_edit', 'products_view']);
        $warehouse = DB::table('warehouses')->insertGetId([
            'name' => 'Branch', 'code' => 'BR-C', 'site_type' => 'branch',
        ]);
        $client = DB::table('clients')->insertGetId([
            'name' => 'Order customer', 'code' => 303, 'email' => 'order@example.invalid',
            'phone' => '0917', 'country' => 'PH', 'city' => 'Manila', 'adresse' => 'Branch',
        ]);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'CO', 'name' => 'Customer orders']);
        $product = DB::table('products')->insertGetId([
            'code' => 'CO-LOT', 'Type_barcode' => 'CODE128', 'name' => 'Promised lot',
            'cost' => 100, 'price' => 150, 'category_id' => $category,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2', 'has_batches' => 1,
        ]);
        $lot = DB::table('product_batches')->insertGetId([
            'product_id' => $product, 'batch_number' => 'CO-LOT-1',
            'expiry_date' => now()->addMonth()->toDateString(),
        ]);
        BatchService::receive($lot, $warehouse, 3, 'receipt');
        DB::table('webhooks')->insert([
            'name' => 'Customer website', 'url' => 'https://example.invalid/cims',
            'events' => 'customer_order.status', 'secret' => str_repeat('a', 48),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = [
            'client_id' => $client, 'warehouse_id' => $warehouse,
            'source_channel' => 'web', 'external_order_id' => 'WEB-001',
            'allow_preorder' => false,
            'lines' => [['product_id' => $product, 'quantity' => 2]],
        ];
        $orderId = $this->postJson('/api/customer_orders', $payload)
            ->assertCreated()->assertJsonPath('status', 'reserved')->json('id');
        $this->assertEquals($orderId, $this->postJson('/api/customer_orders', $payload)
            ->assertCreated()->json('id'));
        $this->assertEquals(1, DB::table('customer_orders')->count());
        $this->assertEquals(1, DB::table('webhook_deliveries')->where('event', 'customer_order.status')->count());
        $this->postJson('/api/customer_orders', array_replace($payload,
            ['lines' => [['product_id' => $product, 'quantity' => 1]]]))->assertStatus(422);

        $pending = array_replace($payload, ['external_order_id' => 'WEB-002',
            'allow_preorder' => true, 'lines' => [['product_id' => $product, 'quantity' => 2]]]);
        $pendingId = $this->postJson('/api/customer_orders', $pending)
            ->assertCreated()->assertJsonPath('status', 'pending_stock')->json('id');
        $this->assertEquals(1, $this->getJson('/api/catalog/availability?warehouse_id=' . $warehouse)
            ->assertOk()->json('products.0.available_to_promise'));

        $orderLine = DB::table('customer_order_lines')->where('customer_order_id', $orderId)->first();
        $saleBody = [
            'date' => now()->toDateString(), 'client_id' => $client,
            'warehouse_id' => $warehouse, 'statut' => 'completed',
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 300, 'amount' => 0, 'received_amount' => 0, 'change' => 0,
            'payment' => ['status' => 'pending', 'Reglement' => 'Cash'],
            'details' => [[
                'product_id' => $product, 'product_variant_id' => null,
                'sale_unit_id' => $unit, 'quantity' => 2, 'Unit_price' => 150,
                'subtotal' => 300, 'discount' => 0, 'discount_Method' => '2',
                'tax_percent' => 0, 'tax_method' => '2',
            ]],
        ];
        $this->postJson('/api/sales', $saleBody)->assertStatus(422);
        $saleBody['customer_order_id'] = $orderId;
        $saleBody['details'][0]['customer_order_line_id'] = $orderLine->id;
        $saleId = $this->postJson('/api/sales', $saleBody)->assertOk()->json('id');
        $this->assertSame('fulfilled', DB::table('customer_orders')->where('id', $orderId)->value('status'));
        $this->assertEquals($saleId, DB::table('customer_orders')->where('id', $orderId)->value('sale_id'));
        $this->assertEquals($orderLine->id, DB::table('sale_details')->where('sale_id', $saleId)
            ->value('customer_order_line_id'));
        $this->assertEquals(1, DB::table('batch_stock')->where('product_batch_id', $lot)->sum('qte'));
        $this->assertEquals(0, DB::table('stock_reservations')->where('owner_id', $orderLine->id)
            ->where('owner_type', 'customer_order_line')->where('status', 'active')->count());

        BatchService::receive($lot, $warehouse, 1, 'replacement');
        $pendingLine = DB::table('customer_order_lines')->where('customer_order_id', $pendingId)->first();
        $this->postJson('/api/customer_order_lines/' . $pendingLine->id . '/allocate')->assertOk();
        $this->assertSame('reserved', DB::table('customer_orders')->where('id', $pendingId)->value('status'));
        $this->postJson('/api/customer_orders/' . $pendingId . '/cancel')->assertOk();
        $this->assertSame('cancelled', DB::table('customer_orders')->where('id', $pendingId)->value('status'));
        $this->assertEquals(2, DB::table('batch_stock')->where('product_batch_id', $lot)->sum('qte'));
        $this->assertEquals(5, DB::table('webhook_deliveries')->where('event', 'customer_order.status')->count());
        $last = DB::table('webhook_deliveries')->orderByDesc('id')->value('payload');
        $this->assertSame('cancelled', json_decode($last, true)['data']['status']);
    }
}
