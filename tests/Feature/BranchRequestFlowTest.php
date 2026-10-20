<?php

namespace Tests\Feature;

use App\Models\User;
use App\utils\BatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

class BranchRequestFlowTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function a_named_lot_moves_from_branch_request_through_transit_to_branch_receipt()
    {
        $requester = $this->actAsUserWith([
            'transfer_view', 'transfer_add', 'transfer_edit', 'branch_request_approve', 'products_view',
        ]);
        $central = DB::table('warehouses')->insertGetId([
            'name' => 'Central', 'site_type' => 'central_warehouse', 'code' => 'CENTRAL',
        ]);
        $branch = DB::table('warehouses')->insertGetId([
            'name' => 'Branch A', 'site_type' => 'branch', 'code' => 'BR-A',
        ]);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'BRQ', 'name' => 'Branch goods']);
        $product = DB::table('products')->insertGetId([
            'code' => 'BRQ-001', 'Type_barcode' => 'CODE128', 'name' => 'Traceable stock',
            'cost' => 100, 'price' => 150, 'category_id' => $category,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2', 'has_batches' => 1,
        ]);
        $lot = DB::table('product_batches')->insertGetId([
            'product_id' => $product, 'batch_number' => 'BRQ-LOT',
            'expiry_date' => now()->addMonth()->toDateString(),
        ]);
        BatchService::receive($lot, $central, 4, 'receipt');

        $id = $this->postJson('/api/branch_requests', [
            'from_warehouse_id' => $central, 'to_warehouse_id' => $branch,
            'priority' => 'normal', 'lines' => [['product_id' => $product, 'quantity' => 3]],
        ])->assertCreated()->json('id');
        $line = DB::table('branch_request_lines')->where('branch_request_id', $id)->first();
        $this->postJson("/api/branch_requests/{$id}/allocate", [
            'line_id' => $line->id, 'quantity' => 2,
        ])->assertStatus(422);

        $manager = User::create([
            'firstname' => 'Branch', 'lastname' => 'Manager', 'username' => 'branch_manager',
            'email' => 'branch.manager@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $requester->role_id,
        ]);
        DB::table('role_user')->insert(['role_id' => $requester->role_id, 'user_id' => $manager->id]);
        $this->actingAs($manager, 'api');
        $shipment = $this->postJson("/api/branch_requests/{$id}/allocate", [
            'line_id' => $line->id, 'quantity' => 2,
        ])->assertCreated()->json('shipment_id');
        $this->assertEquals(2, DB::table('stock_reservations')
            ->where('owner_id', $shipment)->where('owner_type', 'branch_shipment')->sum('quantity'));
        $this->postJson("/api/branch_shipments/{$shipment}/dispatch")->assertOk();
        $this->postJson("/api/branch_shipments/{$shipment}/dispatch")->assertStatus(422);
        $this->assertEquals(2, DB::table('batch_stock')->where('product_batch_id', $lot)
            ->where('warehouse_id', $central)->value('qte'));
        $this->assertEquals(0, DB::table('batch_stock')->where('product_batch_id', $lot)
            ->where('warehouse_id', $branch)->sum('qte'));
        $overview = collect($this->getJson('/api/traceability/overview')->assertOk()->json('locations'));
        $this->assertEquals(2, $overview->firstWhere('id', $branch)['in_transit_to_location']);
        $item = DB::table('branch_shipment_items')->where('branch_shipment_id', $shipment)->first();
        $this->assertEquals($lot, $item->product_batch_id);

        $this->postJson("/api/branch_shipments/{$shipment}/receive", [
            'items' => [['id' => $item->id, 'received_qte' => 1]],
            'discrepancy_notes' => 'One bag still with courier',
        ])->assertOk();
        $this->assertEquals(1, DB::table('batch_stock')->where('product_batch_id', $lot)
            ->where('warehouse_id', $branch)->value('qte'));
        $this->assertSame('discrepancy', DB::table('branch_shipments')->where('id', $shipment)->value('status'));
        $this->postJson("/api/branch_shipments/{$shipment}/receive", [
            'items' => [['id' => $item->id, 'received_qte' => 1]],
        ])->assertOk();
        $this->assertEquals(2, DB::table('batch_stock')->where('product_batch_id', $lot)
            ->where('warehouse_id', $branch)->value('qte'));
        $this->assertSame('received', DB::table('branch_shipments')->where('id', $shipment)->value('status'));
        $this->assertSame('partial', DB::table('branch_requests')->where('id', $id)->value('status'));
        $this->getJson("/api/batches/{$lot}/trace")->assertOk()
            ->assertJsonPath('reconciliation.in_sync', true)
            ->assertJsonPath('consumed_by.0.reference_label', 'BranchShipment ' . DB::table('branch_shipments')->where('id', $shipment)->value('Ref'));
        $this->assertEquals(4, DB::table('batch_stock')->where('product_batch_id', $lot)->sum('qte'));
        $this->assertEquals(0, DB::table('stock_reservations')->where('owner_id', $shipment)
            ->where('status', 'active')->count());

        $this->actingAs($requester, 'api');
        $cancelId = $this->postJson('/api/branch_requests', [
            'from_warehouse_id' => $central, 'to_warehouse_id' => $branch,
            'lines' => [['product_id' => $product, 'quantity' => 1]],
        ])->assertCreated()->json('id');
        $cancelLine = DB::table('branch_request_lines')->where('branch_request_id', $cancelId)->value('id');
        $this->actingAs($manager, 'api');
        $cancelShipment = $this->postJson("/api/branch_requests/{$cancelId}/allocate", [
            'line_id' => $cancelLine, 'quantity' => 1,
        ])->assertCreated()->json('shipment_id');
        $this->actingAs($requester, 'api');
        $this->postJson("/api/branch_requests/{$cancelId}/cancel", [
            'reason' => 'Branch no longer needs this stock',
        ])->assertOk();
        $this->assertSame('cancelled', DB::table('branch_requests')->where('id', $cancelId)->value('status'));
        $this->assertEquals(0, DB::table('stock_reservations')->where('owner_type', 'branch_shipment')
            ->where('owner_id', $cancelShipment)->where('status', 'active')->count());
        $this->postJson("/api/branch_shipments/{$cancelShipment}/dispatch")->assertStatus(422);
    }

    /** @test */
    public function qc_marked_branch_pullout_stays_unsellable_until_another_approver_releases_it()
    {
        $requester = $this->actAsUserWith([
            'transfer_view', 'transfer_add', 'transfer_edit', 'branch_request_approve',
            'goods_receipt_qc_approve', 'products_view',
        ]);
        $central = DB::table('warehouses')->insertGetId([
            'name' => 'Central', 'site_type' => 'central_warehouse', 'code' => 'CENTRAL',
        ]);
        $branch = DB::table('warehouses')->insertGetId([
            'name' => 'Branch A', 'site_type' => 'branch', 'code' => 'BR-A',
        ]);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'POUT', 'name' => 'Pull-out goods']);
        $product = DB::table('products')->insertGetId([
            'code' => 'POUT-001', 'Type_barcode' => 'CODE128', 'name' => 'Returned lot',
            'cost' => 100, 'price' => 150, 'category_id' => $category,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2', 'has_batches' => 1, 'requires_qc' => 1,
        ]);
        $lot = DB::table('product_batches')->insertGetId([
            'product_id' => $product, 'batch_number' => 'RETURN-LOT',
            'expiry_date' => now()->addMonth()->toDateString(),
        ]);
        BatchService::receive($lot, $branch, 3, 'receipt');

        $requestId = $this->postJson('/api/branch_requests', [
            'kind' => 'pullout', 'from_warehouse_id' => $branch,
            'to_warehouse_id' => $central, 'notes' => 'Damaged outer cartons',
            'lines' => [['product_id' => $product, 'quantity' => 2]],
        ])->assertCreated()->json('id');
        $lineId = DB::table('branch_request_lines')->where('branch_request_id', $requestId)->value('id');
        $this->postJson("/api/branch_requests/{$requestId}/allocate", [
            'line_id' => $lineId, 'quantity' => 2,
        ])->assertStatus(422);

        $manager = User::create([
            'firstname' => 'Central', 'lastname' => 'Manager', 'username' => 'return_manager',
            'email' => 'return.manager@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $requester->role_id,
        ]);
        DB::table('role_user')->insert(['role_id' => $requester->role_id, 'user_id' => $manager->id]);
        $this->actingAs($manager, 'api');
        $shipmentId = $this->postJson("/api/branch_requests/{$requestId}/allocate", [
            'line_id' => $lineId, 'quantity' => 2,
        ])->assertCreated()->json('shipment_id');
        $this->postJson("/api/branch_shipments/{$shipmentId}/dispatch")->assertOk();
        $item = DB::table('branch_shipment_items')->where('branch_shipment_id', $shipmentId)->first();
        $this->postJson("/api/branch_shipments/{$shipmentId}/receive", [
            'items' => [['id' => $item->id, 'received_qte' => 2]],
        ])->assertOk();
        $this->assertSame('awaiting_qc', DB::table('branch_shipments')->where('id', $shipmentId)->value('status'));
        $this->assertEquals(0, DB::table('product_warehouse')->where('product_id', $product)
            ->where('warehouse_id', $central)->sum('qte'));
        $this->getJson('/api/traceability/overview')->assertOk()
            ->assertJsonPath('exceptions.pending_return_qc_items', 1);
        $this->getJson('/api/traceability/exceptions?type=pending_return_qc_items')->assertOk()
            ->assertJsonPath('rows.0.quantity', '2.000000');
        $this->postJson("/api/branch_shipments/{$shipmentId}/qc", [
            'item_id' => $item->id, 'passed_qte' => 1, 'failed_qte' => 1,
            'notes' => 'One carton rejected',
        ])->assertStatus(422);

        $approver = User::create([
            'firstname' => 'QC', 'lastname' => 'Approver', 'username' => 'return_qc',
            'email' => 'return.qc@example.invalid', 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $requester->role_id,
        ]);
        DB::table('role_user')->insert(['role_id' => $requester->role_id, 'user_id' => $approver->id]);
        $this->actingAs($approver, 'api');
        $this->postJson("/api/branch_shipments/{$shipmentId}/qc", [
            'item_id' => $item->id, 'passed_qte' => 1, 'failed_qte' => 1,
            'notes' => 'One carton rejected',
        ])->assertOk();
        $this->assertEquals(1, DB::table('batch_stock')->where('product_batch_id', $lot)
            ->where('warehouse_id', $central)->value('qte'));
        $this->assertEquals(1, DB::table('batch_stock')->where('product_batch_id', $lot)
            ->where('warehouse_id', $branch)->value('qte'));
        $this->assertEquals(1, DB::table('branch_shipment_items')->where('id', $item->id)->value('qc_failed_qte'));
        $this->assertSame('received', DB::table('branch_requests')->where('id', $requestId)->value('status'));
        $this->getJson('/api/traceability/overview')->assertOk()
            ->assertJsonPath('exceptions.pending_return_qc_items', 0);
        $this->getJson("/api/batches/{$lot}/trace")->assertOk()
            ->assertJsonPath('reconciliation.in_sync', true);
    }
}
