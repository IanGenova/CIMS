<?php

namespace Tests\Feature;

use App\Models\product_warehouse;
use App\utils\BatchService;
use App\utils\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture($tracked = false)
    {
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Central']);
        $unit = DB::table('units')->insertGetId([
            'name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
        ]);
        $category = DB::table('categories')->insertGetId(['code' => 'RSV', 'name' => 'Reservation goods']);
        $product = DB::table('products')->insertGetId([
            'code' => $tracked ? 'RSV-BATCH' : 'RSV-PLAIN', 'Type_barcode' => 'CODE128',
            'name' => 'Promised item', 'cost' => 100, 'price' => 150,
            'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit,
            'unit_purchase_id' => $unit, 'TaxNet' => 0, 'tax_method' => '2',
            'has_batches' => $tracked ? 1 : 0,
        ]);
        if ($tracked) {
            $batch = DB::table('product_batches')->insertGetId([
                'product_id' => $product, 'batch_number' => 'PROMISED-LOT',
                'expiry_date' => now()->addMonth()->toDateString(),
            ]);
            BatchService::receive($batch, $warehouse, 3, 'receipt');
        } else {
            $batch = null;
            $stock = product_warehouse::ensure($product, $warehouse);
            $stock->qte = 3;
            $stock->save();
        }
        return compact('warehouse', 'product', 'batch');
    }

    /** @test */
    public function durable_plain_stock_promise_blocks_an_unrelated_sale_until_released()
    {
        $f = $this->fixture();
        ReservationService::reserve('customer_order_line', 1, $f['warehouse'], $f['product'], null, 2);
        ReservationService::reserve('customer_order_line', 1, $f['warehouse'], $f['product'], null, 2);
        $this->assertEquals(2, ReservationService::quantity($f['warehouse'], $f['product']));
        $this->assertEquals(1, ReservationService::available($f['warehouse'], $f['product']));
        $this->assertEquals(1, DB::table('stock_reservations')->count());

        $stock = product_warehouse::where('warehouse_id', $f['warehouse'])->first();
        $stock->qte = 1;
        try {
            $stock->save();
            $this->fail('An unrelated sale consumed reserved stock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }
        ReservationService::close('customer_order_line', 1, 'released');
        $this->assertEquals(3, ReservationService::available($f['warehouse'], $f['product']));
        $this->assertEquals(0, ReservationService::close('customer_order_line', 1, 'released'));
        $stock->qte = 1;
        $stock->save();
        $this->assertEquals(1, product_warehouse::where('warehouse_id', $f['warehouse'])->value('qte'));
    }

    /** @test */
    public function a_reserved_lot_cannot_be_picked_for_an_unrelated_sale()
    {
        $f = $this->fixture(true);
        ReservationService::reserve('branch_request_line', 1, $f['warehouse'], $f['product'], null, 2);
        $this->assertEquals(1, ReservationService::available($f['warehouse'], $f['product']));
        $this->assertEquals($f['batch'], DB::table('stock_reservations')->value('product_batch_id'));

        try {
            DB::transaction(fn () => BatchService::allocate($f['product'], null, $f['warehouse'], 2, 'sale'));
            $this->fail('An unrelated sale consumed reserved lot stock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch', $e->errors());
        }
        $this->assertEquals(3, DB::table('batch_stock')->where('product_batch_id', $f['batch'])->value('qte'));
        ReservationService::close('branch_request_line', 1, 'consumed');
        BatchService::allocate($f['product'], null, $f['warehouse'], 2, 'transfer');
        $this->assertEquals(1, DB::table('batch_stock')->where('product_batch_id', $f['batch'])->value('qte'));
    }

    /** @test */
    public function a_named_lot_issue_preserves_other_customers_reserved_physical_stock()
    {
        $f = $this->fixture(true);
        ReservationService::reserve('customer_order_line', 1, $f['warehouse'], $f['product'], null, 2);

        BatchService::allocateFromBatch($f['batch'], $f['warehouse'], 1, 'transfer');

        $this->assertEquals(2, DB::table('batch_stock')->where('product_batch_id', $f['batch'])
            ->where('warehouse_id', $f['warehouse'])->value('qte'));
        $this->assertEquals(2, DB::table('product_warehouse')->where('product_id', $f['product'])
            ->where('warehouse_id', $f['warehouse'])->value('qte'));
        $this->assertEquals(0, ReservationService::available($f['warehouse'], $f['product']));
    }
}
