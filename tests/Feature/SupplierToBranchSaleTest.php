<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

class SupplierToBranchSaleTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function selected_lot_is_traced_from_supplier_through_qc_branch_reservation_and_sale()
    {
        $clerk = $this->actAsUserWith([
            'goods_receipt_add', 'goods_receipt_view', 'goods_receipt_qc_approve',
            'goods_receipt_qc_disposition_request', 'goods_receipt_qc_disposition_approve',
            'transfer_view', 'transfer_add', 'transfer_edit', 'branch_request_approve',
            'Sales_add', 'Sales_view', 'Sales_edit', 'Sales_delete', 'products_view',
        ]);
        $central = DB::table('warehouses')->insertGetId([
            'name' => 'Central', 'code' => 'CENTRAL', 'site_type' => 'central_warehouse',
        ]);
        $branch = DB::table('warehouses')->insertGetId([
            'name' => 'Branch B', 'code' => 'BR-B', 'site_type' => 'branch',
        ]);
        $supplier = DB::table('providers')->insertGetId([
            'name' => 'Source Supplier', 'code' => 8801, 'email' => 'supplier@example.invalid',
            'phone' => '0917', 'country' => 'PH', 'city' => 'Manila', 'adresse' => 'x',
        ]);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'CHAIN', 'name' => 'Traceable goods']);
        $product = DB::table('products')->insertGetId([
            'code' => 'CHAIN-001', 'Type_barcode' => 'CODE128', 'name' => 'Tracked item',
            'cost' => 100, 'price' => 150, 'category_id' => $category,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2', 'has_batches' => 1, 'requires_qc' => 1,
        ]);
        $customer = DB::table('clients')->insertGetId(['name' => 'Branch customer', 'code' => 8802]);
        $po = DB::table('purchases')->insertGetId([
            'user_id' => $clerk->id, 'Ref' => 'PO-CHAIN', 'date' => now()->toDateString(),
            'provider_id' => $supplier, 'warehouse_id' => $central,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 1000, 'paid_amount' => 0, 'payment_statut' => 'unpaid',
            'statut' => 'ordered',
        ]);
        $poLine = DB::table('purchase_details')->insertGetId([
            'purchase_id' => $po, 'product_id' => $product, 'purchase_unit_id' => $unit,
            'quantity' => 10, 'cost' => 100, 'total' => 1000, 'TaxNet' => 0,
            'tax_method' => '2', 'discount' => 0, 'discount_method' => '2',
        ]);

        $firstReceipt = $this->postJson('/api/goods_receipts', [
            'purchase_id' => $po, 'date' => now()->toDateString(),
            'details' => [['purchase_detail_id' => $poLine, 'received_qte' => 8,
                'rejected_qte' => 0, 'batch_number' => 'SAME-PRINTED-LOT']],
        ])->assertSuccessful()->json('id');
        $firstLine = DB::table('goods_receipt_details')->where('goods_receipt_id', $firstReceipt)->first();
        $this->assertEquals(0, DB::table('product_warehouse')->where('product_id', $product)->sum('qte'));
        $this->postJson("/api/goods_receipts/{$firstReceipt}/qc", [
            'receipt_detail_id' => $firstLine->id, 'passed_qte' => 6,
            'failed_qte' => 2, 'notes' => 'Two damaged units',
        ])->assertSuccessful();
        $firstLot = DB::table('product_batches')->where('source_receipt_detail_id', $firstLine->id)->value('id');

        $disposition = $this->postJson("/api/goods_receipts/{$firstReceipt}/qc_dispositions", [
            'receipt_detail_id' => $firstLine->id, 'action' => 'supplier_return',
            'quantity' => 2, 'reason' => 'Supplier will replace damaged units',
        ])->assertCreated()->json('id');
        $this->postJson("/api/qc_dispositions/{$disposition}/decision", ['decision' => 'approve'])
            ->assertStatus(422);

        $manager = User::create([
            'firstname' => 'Separate', 'lastname' => 'Manager', 'username' => 'chain_manager',
            'email' => 'chain.manager@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $clerk->role_id,
        ]);
        DB::table('role_user')->insert(['role_id' => $clerk->role_id, 'user_id' => $manager->id]);
        $this->actingAs($manager, 'api');
        $this->postJson("/api/qc_dispositions/{$disposition}/decision", ['decision' => 'approve'])
            ->assertOk();

        $secondReceipt = $this->postJson('/api/goods_receipts', [
            'purchase_id' => $po, 'date' => now()->toDateString(),
            'details' => [['purchase_detail_id' => $poLine, 'received_qte' => 4,
                'rejected_qte' => 0, 'batch_number' => 'SAME-PRINTED-LOT']],
        ])->assertSuccessful()->json('id');
        $secondLine = DB::table('goods_receipt_details')->where('goods_receipt_id', $secondReceipt)->first();
        $this->postJson("/api/goods_receipts/{$secondReceipt}/qc", [
            'receipt_detail_id' => $secondLine->id, 'passed_qte' => 4, 'failed_qte' => 0,
        ])->assertSuccessful();
        $secondLot = DB::table('product_batches')->where('source_receipt_detail_id', $secondLine->id)->value('id');
        $this->assertNotEquals($firstLot, $secondLot);
        $this->assertEquals(10, DB::table('product_warehouse')->where('product_id', $product)->sum('qte'));

        $this->actingAs($clerk, 'api');
        $requestId = $this->postJson('/api/branch_requests', [
            'from_warehouse_id' => $central, 'to_warehouse_id' => $branch,
            'lines' => [['product_id' => $product, 'quantity' => 4]],
        ])->assertCreated()->json('id');
        $requestLine = DB::table('branch_request_lines')->where('branch_request_id', $requestId)->value('id');
        $this->actingAs($manager, 'api');
        $shipment = $this->postJson("/api/branch_requests/{$requestId}/allocate", [
            'line_id' => $requestLine, 'quantity' => 4,
        ])->assertCreated()->json('shipment_id');
        $this->postJson("/api/branch_shipments/{$shipment}/dispatch")->assertOk();
        $shipmentItems = DB::table('branch_shipment_items')->where('branch_shipment_id', $shipment)->get();
        $this->assertCount(1, $shipmentItems);
        $this->assertEquals($firstLot, $shipmentItems[0]->product_batch_id);
        $this->postJson("/api/branch_shipments/{$shipment}/receive", [
            'items' => [['id' => $shipmentItems[0]->id, 'received_qte' => 4]],
        ])->assertOk();
        $this->assertEquals(4, DB::table('batch_stock')->where('warehouse_id', $branch)
            ->where('product_batch_id', $firstLot)->value('qte'));

        $this->actingAs($clerk, 'api');
        $order = $this->postJson('/api/customer_orders', [
            'client_id' => $customer, 'warehouse_id' => $branch,
            'source_channel' => 'staff', 'allow_preorder' => false,
            'lines' => [['product_id' => $product, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('status', 'reserved')->json('id');
        $orderLine = DB::table('customer_order_lines')->where('customer_order_id', $order)->value('id');
        $sale = $this->postJson('/api/sales', [
            'date' => now()->toDateString(), 'client_id' => $customer,
            'warehouse_id' => $branch, 'statut' => 'completed',
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 150, 'amount' => 0, 'received_amount' => 0, 'change' => 0,
            'payment' => ['status' => 'pending', 'Reglement' => 'Cash'],
            'customer_order_id' => $order,
            'details' => [[
                'product_id' => $product, 'product_variant_id' => null,
                'sale_unit_id' => $unit, 'quantity' => 1, 'Unit_price' => 150,
                'subtotal' => 150, 'discount' => 0, 'discount_Method' => '2',
                'tax_percent' => 0, 'tax_method' => '2',
                'customer_order_line_id' => $orderLine,
            ]],
        ])->assertOk()->json('id');
        $this->assertSame('fulfilled', DB::table('customer_orders')->where('id', $order)->value('status'));
        $this->getJson("/api/batches/{$firstLot}/trace")->assertOk()
            ->assertJsonPath('batch.source.supplier', 'Source Supplier')
            ->assertJsonPath('batch.source.purchase_ref', 'PO-CHAIN')
            ->assertJsonPath('batch.source.goods_receipt_detail_id', $firstLine->id)
            ->assertJsonPath('reconciliation.in_sync', true);
        $trace = $this->getJson("/api/batches/{$firstLot}/trace")->json();
        $references = collect($trace['movements'])->pluck('reference_type')->unique();
        $this->assertTrue($references->contains('BranchShipment'));
        $this->assertTrue($references->contains('Sale'));
        $this->assertEquals(3, DB::table('batch_stock')->where('warehouse_id', $branch)
            ->where('product_batch_id', $firstLot)->value('qte'));
        $this->assertEquals($orderLine, DB::table('sale_details')->where('sale_id', $sale)
            ->value('customer_order_line_id'));

        $this->postJson("/api/sales/{$sale}/void", ['reason' => 'Correction before shift close'])
            ->assertOk();
        $this->assertSame('sale_voided', DB::table('customer_orders')->where('id', $order)->value('status'));
        $this->assertEquals(4, DB::table('batch_stock')->where('warehouse_id', $branch)
            ->where('product_batch_id', $firstLot)->value('qte'));
        $this->getJson("/api/batches/{$firstLot}/trace")->assertOk()
            ->assertJsonPath('reconciliation.in_sync', true);
    }
}
