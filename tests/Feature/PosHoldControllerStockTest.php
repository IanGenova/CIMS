<?php

namespace Tests\Feature;

use App\utils\BatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * A held basket keeps its stock.
 *
 * Asked for by the owner: while a basket is on hold at the POS, its goods are
 * set aside -- taken off the shelf count -- so another till cannot sell the
 * same last bags of cement. They come back when the basket is discarded,
 * when it is recalled (the sale then takes them for good), or by themselves
 * once a forgotten hold is older than 12 hours.
 *
 * Fixture: Portland Cement 40kg, 10 bags in Main Warehouse; it is also sold
 * by the "pack of 5" unit (operator *, 5).
 */
class PosHoldControllerStockTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $warehouse;
    private $product;
    private $bag;
    private $pack;
    private $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['Pos_view', 'record_view']);

        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC']);
        DB::table('pos_settings')->insert(['note_customer' => 'Thank you', 'show_note' => 1, 'show_barcode' => 1,
            'show_discount' => 1, 'show_customer' => 1, 'show_email' => 1, 'show_phone' => 1, 'show_address' => 1]);
        $this->warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->client = DB::table('clients')->insertGetId(['name' => 'walk-in-customer', 'code' => 1]);
        $this->bag = DB::table('units')->insertGetId(['name' => 'Bag', 'ShortName' => 'bag', 'operator' => '*', 'operator_value' => 1]);
        $this->pack = DB::table('units')->insertGetId(['name' => 'Pack of 5', 'ShortName' => 'pk5', 'operator' => '*', 'operator_value' => 5, 'base_unit' => $this->bag]);
        $category = DB::table('categories')->insertGetId(['code' => 'CEM', 'name' => 'Cement']);
        $this->product = DB::table('products')->insertGetId([
            'code' => 'HW-0001', 'Type_barcode' => 'CODE128', 'name' => 'Portland Cement 40kg',
            'cost' => 230, 'price' => 265, 'category_id' => $category,
            'unit_id' => $this->bag, 'unit_sale_id' => $this->bag, 'unit_purchase_id' => $this->bag,
        ]);
        DB::table('product_warehouse')->insert(['product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'qte' => 10]);
    }

    private function line($qty, $unit = null)
    {
        return [
            'product_id' => $this->product, 'product_variant_id' => null, 'sale_unit_id' => $unit ?: $this->bag,
            'quantity' => $qty, 'Unit_price' => 265, 'subtotal' => 265 * $qty, 'name' => 'Portland Cement 40kg',
            'discount' => 0, 'discount_Method' => '2', 'tax_percent' => 0, 'tax_method' => '1',
        ];
    }

    private function hold(array $cart)
    {
        return $this->postJson('/api/pos/holds', [
            'warehouse_id' => $this->warehouse, 'client_id' => $this->client,
            'label' => 'Mang Jose', 'cart' => $cart, 'total' => 0,
        ]);
    }

    private function onShelf()
    {
        return (float) DB::table('product_warehouse')->where('product_id', $this->product)
            ->where('warehouse_id', $this->warehouse)->value('qte');
    }

    /** @test */
    public function holding_a_basket_sets_its_stock_aside()
    {
        $this->hold([$this->line(3)])->assertOk();

        $this->assertEquals(7, $this->onShelf());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'pos_hold')->count());
    }

    /** @test */
    public function a_sale_unit_is_set_aside_in_base_units()
    {
        $this->hold([$this->line(1, $this->pack)])->assertOk();

        $this->assertEquals(5, $this->onShelf());
    }

    /** @test */
    public function a_basket_bigger_than_the_shelf_cannot_be_held()
    {
        $this->hold([$this->line(11)])->assertStatus(422);

        $this->assertEquals(10, $this->onShelf());
        $this->assertSame(0, DB::table('pos_holds')->count());
    }

    /** @test */
    public function discarding_a_hold_puts_its_stock_back()
    {
        $id = $this->hold([$this->line(3)])->json('hold.id');

        $this->deleteJson("/api/pos/holds/{$id}")->assertOk();
        $this->assertEquals(10, $this->onShelf());

        // Once only.
        $this->deleteJson("/api/pos/holds/{$id}")->assertStatus(404);
        $this->assertEquals(10, $this->onShelf());
    }

    /** @test */
    public function recalling_then_selling_takes_the_stock_once()
    {
        $id = $this->hold([$this->line(3)])->json('hold.id');

        $cart = $this->postJson("/api/pos/holds/{$id}/recall")->assertOk()->json('hold.cart');
        // Still set aside for this till while it rings the basket up, so no
        // other till can sell them in between (DATATEST K77).
        $this->assertEquals(7, $this->onShelf(), 'kept for the till that recalled the basket');

        $this->postJson('/api/pos/CreatePOS', [
            'client_id' => $this->client, 'warehouse_id' => $this->warehouse,
            'GrandTotal' => 795, 'amount' => 795, 'received_amount' => 795, 'change' => 0,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'payment' => ['Reglement' => 'Cash'], 'details' => $cart,
        ])->assertOk();

        $this->assertEquals(7, $this->onShelf());
    }

    /** @test */
    public function a_forgotten_hold_gives_its_stock_back_but_stays_recallable()
    {
        $id = $this->hold([$this->line(3)])->json('hold.id');
        DB::table('pos_holds')->where('id', $id)->update(['created_at' => now()->subHours(13)]);

        $holds = $this->getJson('/api/pos/holds?warehouse_id=' . $this->warehouse)->assertOk()->json('holds');

        $this->assertEquals(10, $this->onShelf());
        $this->assertFalse((bool) $holds[0]['stock_held']);

        // Recalling it later does not hand the same stock back a second time.
        $this->postJson("/api/pos/holds/{$id}/recall")->assertOk();
        $this->assertEquals(10, $this->onShelf());
    }

    /** @test */
    public function a_basket_held_before_this_change_returns_nothing()
    {
        // Held under the old rule: the basket only, no stock set aside.
        $id = DB::table('pos_holds')->insertGetId([
            'user_id' => auth('api')->id(), 'warehouse_id' => $this->warehouse, 'label' => 'Old basket',
            'cart' => json_encode([$this->line(3)]), 'item_count' => 1, 'total' => 795,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/pos/holds/{$id}/recall")->assertOk();
        $this->assertEquals(10, $this->onShelf());
    }

    /** @test */
    public function batch_tracked_stock_comes_back_to_the_same_lot()
    {
        DB::table('products')->where('id', $this->product)->update(['has_batches' => 1]);
        BatchService::forget();
        DB::table('product_warehouse')->where('product_id', $this->product)->update(['qte' => 0]);
        $lot = DB::table('product_batches')->insertGetId(['product_id' => $this->product, 'batch_number' => 'LOT-2026-09',
            'expiry_date' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        BatchService::receive($lot, $this->warehouse, 10, 'purchase');

        $id = $this->hold([$this->line(4)])->assertOk()->json('hold.id');
        $this->assertEquals(6, (float) DB::table('batch_stock')->where('product_batch_id', $lot)->value('qte'));
        $this->assertEquals(6, $this->onShelf());

        $this->deleteJson("/api/pos/holds/{$id}")->assertOk();
        $this->assertEquals(10, (float) DB::table('batch_stock')->where('product_batch_id', $lot)->value('qte'));
        $this->assertEquals(10, $this->onShelf());
    }
}
