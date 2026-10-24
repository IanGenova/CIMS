<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\utils\BatchService;
use Tests\GrantsPermissions;
use Tests\TestCase;

class ReceiptSourceTraceTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private function purchaseFor($productId, $warehouseId, $supplierId, $unitId, $quantity)
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'user_id' => auth()->id(), 'Ref' => 'PO-SOURCE-' . $productId,
            'date' => now()->toDateString(), 'provider_id' => $supplierId,
            'warehouse_id' => $warehouseId, 'tax_rate' => 0, 'TaxNet' => 0,
            'discount' => 0, 'shipping' => 0, 'GrandTotal' => 0,
            'paid_amount' => 0, 'payment_statut' => 'unpaid', 'statut' => 'ordered',
        ]);
        $lineId = DB::table('purchase_details')->insertGetId([
            'purchase_id' => $purchaseId, 'product_id' => $productId,
            'purchase_unit_id' => $unitId, 'quantity' => $quantity,
            'cost' => 100, 'total' => 100 * $quantity, 'TaxNet' => 0,
            'tax_method' => '2', 'discount' => 0, 'discount_method' => '2',
        ]);

        return [$purchaseId, $lineId];
    }

    /** @test */
    public function repeated_printed_lot_numbers_remain_separate_receipt_sources()
    {
        $this->actAsUserWith(['goods_receipt_add', 'products_view']);
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Central']);
        $supplier = DB::table('providers')->insertGetId([
            'name' => 'Supplier A', 'code' => 501, 'email' => 'a@example.invalid',
            'phone' => '0917', 'country' => 'PH', 'city' => 'QC', 'adresse' => 'x',
        ]);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'MAT', 'name' => 'Materials']);
        $product = DB::table('products')->insertGetId([
            'code' => 'SOURCE-1', 'Type_barcode' => 'CODE128', 'name' => 'Traceable material',
            'cost' => 100, 'price' => 150, 'category_id' => $category, 'unit_id' => $unit,
            'unit_sale_id' => $unit, 'unit_purchase_id' => $unit, 'TaxNet' => 0,
            'tax_method' => '2', 'has_batches' => 1,
        ]);
        [$purchase, $line] = $this->purchaseFor($product, $warehouse, $supplier, $unit, 3);

        foreach ([1, 2] as $quantity) {
            $this->postJson('/api/goods_receipts', [
                'purchase_id' => $purchase, 'date' => now()->toDateString(),
                'details' => [[
                    'purchase_detail_id' => $line, 'received_qte' => $quantity,
                    'rejected_qte' => 0, 'batch_number' => 'PRINTED-LOT-1',
                ]],
            ])->assertSuccessful();
        }

        $batches = DB::table('product_batches')->where('product_id', $product)
            ->orderBy('id')->get();
        $this->assertCount(2, $batches);
        $this->assertSame('PRINTED-LOT-1', $batches[0]->batch_number);
        $this->assertNotEquals($batches[0]->source_receipt_detail_id, $batches[1]->source_receipt_detail_id);
        $this->assertEquals(1, DB::table('batch_stock')->where('product_batch_id', $batches[0]->id)->value('qte'));
        $this->assertEquals(2, DB::table('batch_stock')->where('product_batch_id', $batches[1]->id)->value('qte'));

        $trace = $this->getJson('/api/batches/' . $batches[1]->id . '/trace')->assertOk();
        $trace->assertJsonPath('batch.source.goods_receipt_detail_id', $batches[1]->source_receipt_detail_id);
        $trace->assertJsonPath('batch.source.purchase_ref', 'PO-SOURCE-' . $product);
        $trace->assertJsonPath('batch.source.supplier', 'Supplier A');

        try {
            BatchService::resolveBatch([
                'product_id' => $product, 'product_variant_id' => null,
                'batch_number' => 'PRINTED-LOT-1',
            ]);
            $this->fail('An ambiguous printed lot number must not silently select a receipt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('batch', $exception->errors());
        }
    }

    /** @test */
    public function serial_lookup_reports_its_exact_receipt_and_purchase()
    {
        $this->actAsUserWith(['goods_receipt_add', 'products_view']);
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Central']);
        $supplier = DB::table('providers')->insertGetId([
            'name' => 'Supplier B', 'code' => 502, 'email' => 'b@example.invalid',
            'phone' => '0917', 'country' => 'PH', 'city' => 'QC', 'adresse' => 'x',
        ]);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'DEV', 'name' => 'Devices']);
        $product = DB::table('products')->insertGetId([
            'code' => 'SOURCE-2', 'Type_barcode' => 'CODE128', 'name' => 'Traceable device',
            'cost' => 100, 'price' => 150, 'category_id' => $category, 'unit_id' => $unit,
            'unit_sale_id' => $unit, 'unit_purchase_id' => $unit, 'TaxNet' => 0,
            'tax_method' => '2', 'has_serials' => 1,
        ]);
        [$purchase, $line] = $this->purchaseFor($product, $warehouse, $supplier, $unit, 1);
        $this->postJson('/api/goods_receipts', [
            'purchase_id' => $purchase, 'date' => now()->toDateString(),
            'details' => [[
                'purchase_detail_id' => $line, 'received_qte' => 1,
                'rejected_qte' => 0, 'serials' => ['SERIAL-RECEIPT-1'],
            ]],
        ])->assertSuccessful();

        $sourceId = DB::table('product_serials')->where('serial_number', 'SERIAL-RECEIPT-1')
            ->value('source_receipt_detail_id');
        $this->assertNotNull($sourceId);
        $lookup = $this->getJson('/api/serials/lookup?serial=SERIAL-RECEIPT-1')->assertOk();
        $lookup->assertJsonPath('serials.0.source.goods_receipt_detail_id', $sourceId);
        $lookup->assertJsonPath('serials.0.source.purchase_ref', 'PO-SOURCE-' . $product);
        $lookup->assertJsonPath('serials.0.source.supplier', 'Supplier B');
    }
}
