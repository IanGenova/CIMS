<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;
use App\utils\BatchService;

class GoodsReceiptQcTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private function fixtures($kind = 'batch')
    {
        $serials = $kind === 'serial';
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Central']);
        $supplier = DB::table('providers')->insertGetId([
            'name' => 'QC Supplier', 'code' => 801, 'email' => 'qc@example.invalid',
            'phone' => '0917', 'country' => 'PH', 'city' => 'Manila', 'adresse' => 'x',
        ]);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'QC', 'name' => 'QC Goods']);
        $product = DB::table('products')->insertGetId([
            'code' => 'QC-' . strtoupper($kind), 'Type_barcode' => 'CODE128',
            'name' => 'Inspected item', 'cost' => 100, 'price' => 150,
            'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit,
            'unit_purchase_id' => $unit, 'TaxNet' => 0, 'tax_method' => '2',
            'has_batches' => $kind === 'batch' ? 1 : 0, 'has_serials' => $serials ? 1 : 0,
            'requires_qc' => 1,
        ]);
        $purchase = DB::table('purchases')->insertGetId([
            'user_id' => auth()->id(), 'Ref' => 'PO-QC', 'date' => now()->toDateString(),
            'provider_id' => $supplier, 'warehouse_id' => $warehouse,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 0, 'paid_amount' => 0, 'payment_statut' => 'unpaid',
            'statut' => 'ordered',
        ]);
        $line = DB::table('purchase_details')->insertGetId([
            'purchase_id' => $purchase, 'product_id' => $product,
            'purchase_unit_id' => $unit, 'quantity' => $serials ? 2 : 4,
            'cost' => 100, 'total' => $serials ? 200 : 400,
            'TaxNet' => 0, 'tax_method' => '2', 'discount' => 0,
            'discount_method' => '2',
        ]);

        return compact('warehouse', 'supplier', 'unit', 'product', 'purchase', 'line');
    }

    private function receive($fixture, $quantity, $extra)
    {
        return $this->postJson('/api/goods_receipts', [
            'purchase_id' => $fixture['purchase'], 'date' => now()->toDateString(),
            'details' => [array_merge([
                'purchase_detail_id' => $fixture['line'], 'received_qte' => $quantity,
                'rejected_qte' => 0,
            ], $extra)],
        ])->assertSuccessful()->json('id');
    }

    /** @test */
    public function network_trace_finds_a_qc_lot_from_receipt_po_and_sale()
    {
        $user = $this->actAsUserWith(['goods_receipt_add', 'goods_receipt_qc_approve', 'products_view']);
        $f = $this->fixtures();
        $receiptId = $this->receive($f, 4, ['batch_number' => 'TRACE-LOT-1']);
        $line = DB::table('goods_receipt_details')->where('goods_receipt_id', $receiptId)->first();
        $this->getJson('/api/traceability/overview')->assertOk()
            ->assertJsonPath('exceptions.pending_qc_lines', 1);
        $this->postJson("/api/goods_receipts/{$receiptId}/qc", [
            'receipt_detail_id' => $line->id, 'passed_qte' => 3, 'failed_qte' => 1,
            'notes' => 'One damaged unit', 'hold_location' => 'QC cage A1',
        ])->assertSuccessful();
        $lotId = DB::table('product_batches')->where('source_receipt_detail_id', $line->id)->value('id');
        $clientId = DB::table('clients')->insertGetId(['name' => 'Recall Customer', 'code' => 9001]);
        $saleId = DB::table('sales')->insertGetId([
            'user_id' => $user->id, 'Ref' => 'SALE-TRACE-1', 'date' => now()->toDateString(),
            'client_id' => $clientId, 'warehouse_id' => $f['warehouse'],
            'GrandTotal' => 150, 'paid_amount' => 0, 'payment_statut' => 'unpaid',
            'statut' => 'completed',
        ]);
        BatchService::allocate($f['product'], null, $f['warehouse'], 1, 'sale', 'Sale', $saleId);

        foreach (['TRACE-LOT-1', 'PO-QC', 'SALE-TRACE-1', 'QC-BATCH'] as $term) {
            $this->getJson('/api/traceability/search?query=' . urlencode($term))->assertOk()
                ->assertJsonPath('batches.0.id', $lotId);
        }
        $receiptRef = DB::table('goods_receipts')->where('id', $receiptId)->value('Ref');
        $this->getJson('/api/traceability/search?query=' . urlencode($receiptRef))->assertOk()
            ->assertJsonPath('batches.0.id', $lotId);

        $trace = $this->getJson("/api/batches/{$lotId}/trace")->assertOk();
        $trace->assertJsonPath('batch.source.supplier', 'QC Supplier')
            ->assertJsonPath('batch.source.qc_decider', $user->username)
            ->assertJsonPath('reconciliation.in_sync', true)
            ->assertJsonPath('reconciliation.stock_balance', 2)
            ->assertJsonPath('consumed_by.0.reference_label', 'Sale SALE-TRACE-1')
            ->assertJsonPath('consumed_by.0.customer', 'Recall Customer');
        $this->getJson('/api/traceability/overview')->assertOk()
            ->assertJsonPath('exceptions.pending_qc_lines', 0)
            ->assertJsonPath('locations.0.on_hand', '2.000000');

        DB::table('users')->where('id', $user->id)->update(['all_warehouses' => 0]);
        DB::table('user_warehouse')->insert(['user_id' => $user->id, 'warehouse_id' => $f['warehouse']]);
        \App\utils\WarehouseAccess::forget();
        $this->getJson('/api/traceability/search?query=TRACE-LOT-1')->assertForbidden();
        $this->getJson('/api/traceability/overview')->assertForbidden();
        $this->getJson("/api/batches/{$lotId}/trace")->assertForbidden();
    }

    /** @test */
    public function a_batch_is_held_until_a_separately_authorized_qc_decision()
    {
        $user = $this->actAsUserWith(['goods_receipt_add', 'goods_receipt_view', 'products_edit', 'products_view']);
        $f = $this->fixtures();
        $receipt = $this->receive($f, 4, ['batch_number' => 'LOT-QC-1']);
        $detail = DB::table('goods_receipt_details')->where('goods_receipt_id', $receipt)->first();
        $batch = DB::table('product_batches')->where('source_receipt_detail_id', $detail->id)->first();

        $this->assertSame('pending', $detail->qc_status);
        $this->assertNotNull($batch);
        $this->assertEquals(0, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
        $this->assertSame('pending_qc', DB::table('goods_receipts')->where('id', $receipt)->value('status'));
        $this->assertSame('pending', DB::table('purchases')->where('id', $f['purchase'])->value('statut'));
        $this->putJson('/api/batches/tracking/' . $f['product'], ['has_batches' => false])->assertStatus(422);

        $decision = ['receipt_detail_id' => $detail->id, 'passed_qte' => 3,
            'failed_qte' => 1, 'notes' => 'One damaged bag', 'hold_location' => 'QC cage A1'];
        $this->postJson("/api/goods_receipts/{$receipt}/qc", $decision)->assertForbidden();

        $permission = DB::table('permissions')->where('name', 'goods_receipt_qc_approve')->value('id');
        DB::table('permission_role')->insert(['permission_id' => $permission, 'role_id' => $user->role_id]);
        $this->postJson("/api/goods_receipts/{$receipt}/qc", $decision)->assertSuccessful();

        $this->assertEquals(3, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
        $this->assertEquals(3, DB::table('batch_stock')->where('product_batch_id', $batch->id)->sum('qte'));
        $this->assertEquals(3, DB::table('purchase_details')->where('id', $f['line'])->value('received_qte'));
        $this->assertSame('partial', DB::table('goods_receipt_details')->where('id', $detail->id)->value('qc_status'));
        $this->getJson('/api/batches/' . $batch->id . '/trace')->assertOk()
            ->assertJsonPath('batch.source.qc_status', 'partial')
            ->assertJsonPath('batch.source.qc_decider', $user->username);
        $this->postJson("/api/goods_receipts/{$receipt}/qc", $decision)->assertStatus(422);

        $replacement = $this->receive($f, 1, ['batch_number' => 'LOT-QC-1']);
        $this->assertNotEquals($receipt, $replacement);
        $this->assertCount(2, DB::table('product_batches')->where('batch_number', 'LOT-QC-1')->get());
        $replacementLine = DB::table('goods_receipt_details')->where('goods_receipt_id', $replacement)->first();
        $this->postJson("/api/goods_receipts/{$replacement}/qc", [
            'receipt_detail_id' => $replacementLine->id, 'passed_qte' => 1, 'failed_qte' => 0,
        ])->assertSuccessful();
        $this->assertSame('received', DB::table('purchases')->where('id', $f['purchase'])->value('statut'));
        $this->assertEquals(4, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
    }

    /** @test */
    public function only_passed_serials_are_released_and_a_failed_unit_cannot_be_sold()
    {
        $this->actAsUserWith(['goods_receipt_add', 'goods_receipt_view', 'products_view',
            'goods_receipt_qc_approve', 'goods_receipt_delete']);
        $f = $this->fixtures('serial');
        $receipt = $this->receive($f, 2, ['serials' => ['QC-001', 'QC-002']]);
        $detail = DB::table('goods_receipt_details')->where('goods_receipt_id', $receipt)->first();

        $this->assertEquals(0, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
        $this->assertSame('qc_hold', DB::table('product_serials')->where('serial_number', 'QC-001')->value('status'));

        $this->postJson("/api/goods_receipts/{$receipt}/qc", [
            'receipt_detail_id' => $detail->id, 'passed_qte' => 1,
            'failed_qte' => 1, 'notes' => 'QC-002 failed inspection',
            'hold_location' => 'QC serial shelf',
            'accepted_serials' => ['QC-001'],
        ])->assertSuccessful();

        $this->assertEquals(1, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
        $this->assertSame('in_stock', DB::table('product_serials')->where('serial_number', 'QC-001')->value('status'));
        $this->assertSame('qc_rejected', DB::table('product_serials')->where('serial_number', 'QC-002')->value('status'));
        $this->getJson('/api/serials/lookup?serial=QC-002')->assertOk()
            ->assertJsonPath('serials.0.source.qc_status', 'partial');
        $this->assertEquals(1, DB::table('purchase_details')->where('id', $f['line'])->value('received_qte'));

        $this->deleteJson("/api/goods_receipts/{$receipt}")->assertStatus(422);
        $this->assertEquals(1, DB::table('qc_rejected_custody')
            ->where('source_type', 'goods_receipt_detail')->where('source_id', $detail->id)
            ->sum('remaining_quantity'));
        $this->getJson('/api/traceability/overview')->assertOk()
            ->assertJsonPath('exceptions.rejected_custody_items', 1);
        $this->getJson('/api/traceability/exceptions?type=rejected_custody_items')->assertOk()
            ->assertJsonPath('rows.0.location', 'QC serial shelf')
            ->assertJsonPath('rows.0.serial_number', 'QC-002');
    }

    /** @test */
    public function ordinary_qc_goods_stay_unavailable_until_passed_and_cannot_be_released_twice()
    {
        $this->actAsUserWith(['goods_receipt_add', 'goods_receipt_view', 'goods_receipt_qc_approve']);
        $f = $this->fixtures('plain');
        $receipt = $this->receive($f, 4, []);
        $detail = DB::table('goods_receipt_details')->where('goods_receipt_id', $receipt)->first();

        $this->assertEquals(0, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
        $this->postJson("/api/goods_receipts/{$receipt}/qc", [
            'receipt_detail_id' => $detail->id, 'passed_qte' => 3, 'failed_qte' => 1,
        ])->assertStatus(422);
        $this->postJson("/api/goods_receipts/{$receipt}/qc", [
            'receipt_detail_id' => $detail->id, 'passed_qte' => 3, 'failed_qte' => 1,
            'notes' => 'One damaged unit', 'hold_location' => 'QC cage A1',
        ])->assertSuccessful();
        $this->assertEquals(3, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
        $this->assertSame('partial', DB::table('goods_receipt_details')->where('id', $detail->id)->value('qc_status'));
        $this->assertEquals(1, DB::table('stock_movements')->where('reference_type', 'GoodsReceiptDetail')
            ->where('reference_id', $detail->id)->count());
    }

    /** @test */
    public function a_qc_product_cannot_bypass_inspection_via_a_direct_received_purchase()
    {
        $this->actAsUserWith(['Purchases_add']);
        $f = $this->fixtures('plain');

        $this->postJson('/api/purchases', [
            'date' => now()->toDateString(), 'supplier_id' => $f['supplier'],
            'warehouse_id' => $f['warehouse'], 'statut' => 'received',
            'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'details' => [[
                'product_id' => $f['product'], 'purchase_unit_id' => $f['unit'],
                'quantity' => 1, 'Unit_cost' => 100, 'discount' => 0,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('statut');
        $this->assertEquals(0, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
    }

    /** @test */
    public function failed_goods_need_a_different_manager_to_approve_supplier_return_or_writeoff()
    {
        $requester = $this->actAsUserWith([
            'goods_receipt_add', 'goods_receipt_view', 'goods_receipt_qc_approve', 'products_view',
            'goods_receipt_qc_disposition_request', 'goods_receipt_qc_disposition_approve',
            'goods_receipt_qc_disposition_execute',
        ]);
        $f = $this->fixtures('plain');
        $receipt = $this->receive($f, 4, []);
        $line = DB::table('goods_receipt_details')->where('goods_receipt_id', $receipt)->first();
        $this->postJson("/api/goods_receipts/{$receipt}/qc", [
            'receipt_detail_id' => $line->id, 'passed_qte' => 3, 'failed_qte' => 1,
            'notes' => 'Damaged package', 'hold_location' => 'QC cage A1',
        ])->assertSuccessful();

        $payload = ['receipt_detail_id' => $line->id, 'action' => 'supplier_return',
            'quantity' => 1, 'reason' => 'Return damaged goods to supplier'];
        $id = $this->postJson("/api/goods_receipts/{$receipt}/qc_dispositions", $payload)
            ->assertCreated()->json('id');
        $this->postJson("/api/goods_receipts/{$receipt}/qc_dispositions", $payload)
            ->assertStatus(422);
        $this->postJson("/api/qc_dispositions/{$id}/decision", ['decision' => 'approve'])
            ->assertStatus(422);

        $manager = \App\Models\User::create([
            'firstname' => 'Second', 'lastname' => 'Manager', 'username' => 'second_manager',
            'email' => 'second.manager@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $requester->role_id,
        ]);
        DB::table('role_user')->insert(['role_id' => $requester->role_id, 'user_id' => $manager->id]);
        $this->actingAs($manager, 'api');
        $this->postJson("/api/qc_dispositions/{$id}/decision", ['decision' => 'approve'])
            ->assertOk();
        $this->assertEquals($manager->id, DB::table('goods_receipt_qc_dispositions')
            ->where('id', $id)->value('decided_by'));
        $this->postJson("/api/qc_dispositions/{$id}/decision", ['decision' => 'approve'])
            ->assertStatus(422);
        $execution = ['execution_reference' => 'SUPPLIER-RMA-100',
            'execution_note' => 'Damaged package handed to supplier driver'];
        $this->postJson("/api/qc_dispositions/{$id}/execute", $execution)->assertStatus(422);
        $this->actingAs($requester, 'api');
        $custodyId = DB::table('qc_rejected_custody')->where('source_type', 'goods_receipt_detail')
            ->where('source_id', $line->id)->value('id');
        $this->postJson("/api/qc_custody/{$custodyId}/move", [
            'location' => 'Returns dispatch shelf', 'note' => 'Prepared for supplier collection',
        ])->assertOk();
        $this->postJson("/api/qc_dispositions/{$id}/execute", $execution)->assertOk();
        $this->postJson("/api/qc_dispositions/{$id}/execute", $execution)->assertStatus(422);
        $this->assertSame('executed', DB::table('goods_receipt_qc_dispositions')->where('id', $id)->value('status'));
        $this->assertEquals(0, DB::table('qc_rejected_custody')->where('id', $custodyId)->value('remaining_quantity'));
        $this->assertEquals(3, DB::table('qc_custody_events')->where('custody_id', $custodyId)->count());
        $this->assertEquals(3, DB::table('product_warehouse')->where('product_id', $f['product'])->sum('qte'));
        $this->getJson('/api/traceability/overview')->assertOk()
            ->assertJsonPath('exceptions.rejected_custody_items', 0);
    }

    /** @test */
    public function rejected_serial_disposition_names_the_exact_unit_and_preserves_its_history()
    {
        $requester = $this->actAsUserWith([
            'goods_receipt_add', 'goods_receipt_delete', 'goods_receipt_qc_approve',
            'goods_receipt_qc_disposition_request', 'goods_receipt_qc_disposition_approve',
            'goods_receipt_qc_disposition_execute',
        ]);
        $f = $this->fixtures('serial');
        $receipt = $this->receive($f, 2, ['serials' => ['QC-101', 'QC-102']]);
        $line = DB::table('goods_receipt_details')->where('goods_receipt_id', $receipt)->first();
        $this->postJson("/api/goods_receipts/{$receipt}/qc", [
            'receipt_detail_id' => $line->id, 'passed_qte' => 1, 'failed_qte' => 1,
            'accepted_serials' => ['QC-101'], 'notes' => 'QC-102 is damaged',
            'hold_location' => 'QC serial shelf',
        ])->assertSuccessful();
        $payload = ['receipt_detail_id' => $line->id, 'action' => 'supplier_return',
            'quantity' => 1, 'reason' => 'Damaged at delivery',
            'serial_numbers' => ['QC-102']];
        $id = $this->postJson("/api/goods_receipts/{$receipt}/qc_dispositions", $payload)
            ->assertCreated()->json('id');
        $manager = \App\Models\User::create([
            'firstname' => 'QC', 'lastname' => 'Manager', 'username' => 'qc_manager',
            'email' => 'qc.manager@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $requester->role_id,
        ]);
        DB::table('role_user')->insert(['role_id' => $requester->role_id, 'user_id' => $manager->id]);
        $this->actingAs($manager, 'api');
        $this->postJson("/api/qc_dispositions/{$id}/decision", ['decision' => 'approve'])
            ->assertOk();
        $this->assertSame('qc_rejected', DB::table('product_serials')
            ->where('serial_number', 'QC-102')->value('status'));
        $this->actingAs($requester, 'api');
        $this->postJson("/api/qc_dispositions/{$id}/execute", [
            'execution_reference' => 'RMA-SERIAL-102',
            'execution_note' => 'Serial handed back to supplier',
        ])->assertOk();
        $this->assertSame('returned_to_supplier', DB::table('product_serials')
            ->where('serial_number', 'QC-102')->value('status'));
        $this->assertEquals(1, DB::table('product_serial_events')
            ->where('reference_type', 'QcDisposition')->where('reference_id', $id)->count());
        $this->deleteJson("/api/goods_receipts/{$receipt}")->assertStatus(422);
    }
}
