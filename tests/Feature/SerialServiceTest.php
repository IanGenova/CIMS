<?php

namespace Tests\Feature;

use App\Models\product_warehouse;
use App\utils\SerialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Serial numbers, end to end, through the real endpoints.
 *
 * Asked for so CIMS fits appliance, electronics and phone shops: every unit of
 * a serial-tracked product is named when it arrives, when it is sold (which
 * starts its warranty), when it comes back, moves branch, or leaves; and a
 * serial number can be looked up to see where it is and who bought it.
 *
 * Fixture: "Inverter Aircon 1.5HP", serial-tracked, 12-month warranty, sold
 * from Main Warehouse; Branch Warehouse for transfers.
 */
class SerialServiceTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $main;
    private $branch;
    private $unit;
    private $product;
    private $client;
    private $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['Pos_view', 'Sales_add', 'Sales_edit', 'Sales_delete', 'Sales_view', 'Sale_Returns_add',
            'Purchases_add', 'Purchase_Returns_add', 'goods_receipt_add', 'adjustment_add', 'transfer_add', 'products_view', 'record_view']);
        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Appliances',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC']);
        DB::table('pos_settings')->insert(['note_customer' => 'Thank you', 'show_note' => 1, 'show_barcode' => 1,
            'show_discount' => 1, 'show_customer' => 1, 'show_email' => 1, 'show_phone' => 1, 'show_address' => 1]);
        $this->main = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->branch = DB::table('warehouses')->insertGetId(['name' => 'Branch Warehouse']);
        $this->client = DB::table('clients')->insertGetId(['name' => 'Juan Dela Cruz', 'code' => 1]);
        $this->supplier = DB::table('providers')->insertGetId(['name' => 'Cool Air Distributors', 'code' => 1,
            'email' => 'c@example.invalid', 'phone' => '0917', 'country' => 'PH', 'city' => 'QC', 'adresse' => 'x']);
        $this->unit = DB::table('units')->insertGetId(['name' => 'Unit', 'ShortName' => 'unit', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'AC', 'name' => 'Aircon']);
        $this->product = DB::table('products')->insertGetId([
            'code' => 'AC-0001', 'Type_barcode' => 'CODE128', 'name' => 'Inverter Aircon 1.5HP', 'cost' => 25000, 'price' => 32000,
            'category_id' => $category, 'unit_id' => $this->unit, 'unit_sale_id' => $this->unit, 'unit_purchase_id' => $this->unit,
            'TaxNet' => 12, 'tax_method' => '2', 'has_serials' => 1, 'warranty_months' => 12,
        ]);
        foreach ([$this->main, $this->branch] as $w) {
            DB::table('product_warehouse')->insert(['product_id' => $this->product, 'warehouse_id' => $w, 'qte' => 0]);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function onShelf(array $serials, $warehouse = null)
    {
        $warehouse = $warehouse ?: $this->main;
        SerialService::receive($this->product, null, $warehouse, $serials, count($serials), 'Opening', 0);
        $row = product_warehouse::where('product_id', $this->product)->where('warehouse_id', $warehouse)->first();
        $row->qte = (float) $row->qte + count($serials);
        $row->save();
    }

    private function shelf($warehouse = null)
    {
        return (float) DB::table('product_warehouse')->where('product_id', $this->product)->where('warehouse_id', $warehouse ?: $this->main)->value('qte');
    }

    private function serial($number)
    {
        return DB::table('product_serials')->where('serial_number', $number)->first();
    }

    private function line(array $serials = null, $qty = 1, $field = 'sale_unit_id', $price = 32000)
    {
        $line = ['product_id' => $this->product, 'product_variant_id' => null, $field => $this->unit, 'quantity' => $qty,
            'Unit_price' => $price, 'Unit_cost' => 25000, 'subtotal' => $price * $qty, 'discount' => 0, 'discount_Method' => '2',
            'tax_percent' => 12, 'tax_method' => '2'];
        if ($serials !== null) {
            $line['serials'] = $serials;
        }

        return $line;
    }

    private function pos(array $line, $warehouse = null)
    {
        $total = $line['subtotal'];

        return $this->postJson('/api/pos/CreatePOS', [
            'client_id' => $this->client, 'warehouse_id' => $warehouse ?: $this->main,
            'GrandTotal' => $total, 'amount' => $total, 'received_amount' => $total, 'change' => 0,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'payment' => ['Reglement' => 'Cash'],
            'details' => [$line],
        ]);
    }

    private function purchase($statut = 'ordered', array $serials = null)
    {
        return $this->postJson('/api/purchases', ['date' => now()->toDateString(), 'supplier_id' => $this->supplier,
            'warehouse_id' => $this->main, 'statut' => $statut, 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 50000,
            'details' => [$this->line($serials, 2, 'purchase_unit_id', 25000)]]);
    }

    // ------------------------------------------------------------------ arriving

    /** @test */
    public function goods_receipt_needs_one_serial_per_unit_and_records_them()
    {
        $this->purchase()->assertOk();
        $purchaseId = DB::table('purchases')->value('id');
        $detailId = DB::table('purchase_details')->value('id');
        $receive = function ($serials) use ($purchaseId, $detailId) {
            return $this->postJson('/api/goods_receipts', ['purchase_id' => $purchaseId, 'date' => now()->toDateString(),
                'details' => [['purchase_detail_id' => $detailId, 'received_qte' => 2, 'serials' => $serials]]]);
        };

        $receive(['AC15-0001'])->assertStatus(422);
        $receive(['AC15-0001', 'AC15-0001'])->assertStatus(422);
        $this->assertEquals(0, $this->shelf(), 'nothing received on a refusal');

        $receive(['AC15-0001', 'AC15-0002'])->assertOk();
        $this->assertEquals(2, $this->shelf());
        $this->assertSame('in_stock', $this->serial('AC15-0002')->status);
        $this->assertEquals($this->main, $this->serial('AC15-0002')->warehouse_id);
    }

    /** @test */
    public function a_serial_number_is_received_only_once()
    {
        $this->onShelf(['AC15-0001']);

        $this->postJson('/api/adjustments', ['date' => now()->toDateString(), 'warehouse_id' => $this->main,
            'details' => [['product_id' => $this->product, 'product_variant_id' => null, 'quantity' => 1, 'type' => 'add', 'serials' => ['AC15-0001']]]])
            ->assertStatus(422);
        $this->assertEquals(1, $this->shelf());
    }

    /** @test */
    public function a_purchase_cannot_be_saved_as_received_without_a_goods_receipt()
    {
        $this->purchase('received')->assertStatus(422);
        $this->assertEquals(0, $this->shelf());
    }

    // ------------------------------------------------------------------ selling

    /** @test */
    public function the_pos_needs_the_serial_of_the_unit_it_sells()
    {
        $this->onShelf(['AC15-0001', 'AC15-0002']);

        $this->pos($this->line())->assertStatus(422);
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertEquals(2, $this->shelf());
    }

    /** @test */
    public function selling_a_unit_marks_it_sold_and_starts_its_warranty()
    {
        $this->onShelf(['AC15-0001', 'AC15-0002']);

        $this->pos($this->line(['AC15-0002']))->assertOk();

        $sold = $this->serial('AC15-0002');
        $this->assertSame('sold', $sold->status);
        $this->assertEquals(DB::table('sales')->value('id'), $sold->sale_id);
        $this->assertEquals($this->client, $sold->client_id);
        $this->assertSame(now()->addMonths(12)->toDateString(), $sold->warranty_until);
        $this->assertSame('in_stock', $this->serial('AC15-0001')->status);
        $this->assertEquals(1, $this->shelf());
    }

    /** @test */
    public function a_unit_at_another_branch_or_already_sold_cannot_be_sold()
    {
        $this->onShelf(['AC15-0009'], $this->branch);
        $this->onShelf(['AC15-0001']);

        $this->pos($this->line(['AC15-0009']))->assertStatus(422);
        $this->pos($this->line(['AC15-0001']))->assertOk();
        $this->pos($this->line(['AC15-0001']))->assertStatus(422);
        $this->assertSame(1, DB::table('sales')->count());
    }

    /** @test */
    public function the_sales_screen_needs_and_records_serials_too()
    {
        $this->onShelf(['AC15-0001']);
        $sale = function ($line) {
            return $this->postJson('/api/sales', ['date' => now()->toDateString(), 'client_id' => $this->client,
                'warehouse_id' => $this->main, 'statut' => 'completed', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'GrandTotal' => 32000, 'details' => [$line], 'payment' => ['status' => 'pending', 'Reglement' => 'Cash'],
                'amount' => 0, 'received_amount' => 0, 'change' => 0]);
        };

        $sale($this->line())->assertStatus(422);
        $sale($this->line(['AC15-0001']))->assertOk();
        $this->assertSame('sold', $this->serial('AC15-0001')->status);
    }

    // ------------------------------------------------------------------ coming back

    /** @test */
    public function voiding_or_deleting_a_sale_puts_its_units_back()
    {
        $this->onShelf(['AC15-0001', 'AC15-0002']);
        $this->pos($this->line(['AC15-0001']))->assertOk();
        $this->pos($this->line(['AC15-0002']))->assertOk();
        [$first, $second] = DB::table('sales')->orderBy('id')->pluck('id')->all();

        $this->postJson("/api/sales/{$first}/void", ['reason' => 'Customer changed their mind'])->assertOk();
        $this->assertSame('in_stock', $this->serial('AC15-0001')->status);
        $this->assertNull($this->serial('AC15-0001')->warranty_until);

        $this->deleteJson("/api/sales/{$second}")->assertOk();
        $this->assertSame('in_stock', $this->serial('AC15-0002')->status);
        $this->assertEquals($this->main, $this->serial('AC15-0002')->warehouse_id);
    }

    /** @test */
    public function a_customer_return_takes_back_a_sold_unit_only()
    {
        $this->onShelf(['AC15-0001', 'AC15-0002']);
        $this->pos($this->line(['AC15-0001']))->assertOk();
        $return = function ($serials) {
            return $this->postJson('/api/returns/sale', ['date' => now()->toDateString(), 'client_id' => $this->client,
                'sale_id' => DB::table('sales')->value('id'),     // a return names its sale (DATATEST K5)
                'warehouse_id' => $this->main, 'statut' => 'received', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
                'GrandTotal' => 32000, 'details' => [$this->line($serials)]]);
        };

        $return(['AC15-0002'])->assertStatus(422);
        $return(['AC15-0001'])->assertOk();
        $this->assertSame('in_stock', $this->serial('AC15-0001')->status);
        $this->assertEquals(2, $this->shelf());
    }

    /** @test */
    public function a_customer_can_only_return_a_unit_that_was_sold_to_them()
    {
        $this->onShelf(['AC15-0001', 'AC15-0002']);
        $maria = DB::table('clients')->insertGetId(['name' => 'Maria Santos', 'code' => 2]);
        $this->pos($this->line(['AC15-0001']))->assertOk();
        $ref = DB::table('sales')->value('Ref');

        try {
            DB::transaction(function () use ($maria) {
                SerialService::returnToStock($this->product, $this->main, ['AC15-0001'], 1, 'SaleReturn', 0, $maria);
            });
            $this->fail('Maria returned a unit sold to someone else.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(["Serial number AC15-0001 of Inverter Aircon 1.5HP was sold to another customer ({$ref}), so it cannot be returned by Maria Santos."],
                $e->errors()['serials']);
        }
        $this->assertSame('sold', $this->serial('AC15-0001')->status);
    }

    // ------------------------------------------------------------------ moving and leaving

    /** @test */
    public function a_transfer_moves_the_units_it_names()
    {
        $this->onShelf(['AC15-0001', 'AC15-0002']);
        $transfer = function ($serials) {
            return $this->postJson('/api/transfers', [
                'transfer' => ['date' => now()->toDateString(), 'from_warehouse' => $this->main, 'to_warehouse' => $this->branch,
                    'statut' => 'completed', 'notes' => 'To branch', 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0],
                'details' => [$this->line($serials, 1, 'purchase_unit_id', 25000)], 'GrandTotal' => 25000]);
        };

        $transfer(['AC15-0404'])->assertStatus(422);
        $transfer(['AC15-0002'])->assertOk();
        $this->assertEquals($this->branch, $this->serial('AC15-0002')->warehouse_id);
        $this->assertEquals(1, $this->shelf($this->branch));
    }

    /** @test */
    public function an_adjustment_names_the_units_it_adds_or_writes_off()
    {
        $this->onShelf(['AC15-0001']);
        $adjust = function ($type, $serials) {
            return $this->postJson('/api/adjustments', ['date' => now()->toDateString(), 'warehouse_id' => $this->main,
                'details' => [['product_id' => $this->product, 'product_variant_id' => null, 'quantity' => 1, 'type' => $type, 'serials' => $serials]]]);
        };

        $adjust('add', ['AC15-0777'])->assertOk();
        $this->assertSame('in_stock', $this->serial('AC15-0777')->status);

        $adjust('sub', ['AC15-0001'])->assertOk();
        $this->assertSame('written_off', $this->serial('AC15-0001')->status);
        $this->assertEquals(1, $this->shelf());
    }

    /** @test */
    public function a_supplier_return_marks_the_unit_returned_to_the_supplier()
    {
        $this->onShelf(['AC15-0001']);

        $this->postJson('/api/returns/purchase', ['date' => now()->toDateString(), 'supplier_id' => $this->supplier,
            'warehouse_id' => $this->main, 'statut' => 'completed', 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 25000,
            'details' => [$this->line(['AC15-0001'], 1, 'purchase_unit_id', 25000)]])->assertOk();

        $this->assertSame('returned_to_supplier', $this->serial('AC15-0001')->status);
        $this->assertEquals(0, $this->shelf());
    }

    /** @test */
    public function a_saved_sale_with_serials_cannot_be_edited_line_by_line()
    {
        $this->onShelf(['AC15-0001']);
        $this->postJson('/api/sales', ['date' => now()->toDateString(), 'client_id' => $this->client, 'warehouse_id' => $this->main,
            'statut' => 'completed', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 32000,
            'details' => [$this->line(['AC15-0001'])], 'payment' => ['status' => 'pending', 'Reglement' => 'Cash'],
            'amount' => 0, 'received_amount' => 0, 'change' => 0])->assertOk();
        $id = DB::table('sales')->value('id');

        $this->putJson("/api/sales/{$id}", ['date' => now()->toDateString(), 'client_id' => $this->client, 'warehouse_id' => $this->main,
            'statut' => 'completed', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 64000,
            'details' => [$this->line(null, 2)]])->assertStatus(422);
        $this->assertSame('sold', $this->serial('AC15-0001')->status);
    }

    // ------------------------------------------------------------------ finding a unit

    /** @test */
    public function a_serial_number_can_be_looked_up_with_its_history_and_warranty()
    {
        $this->onShelf(['AC15-0001', 'AC15-0002']);
        $this->pos($this->line(['AC15-0001']))->assertOk();

        $found = $this->getJson('/api/serials/lookup?serial=AC15-0001')->assertOk()->json('serials.0');
        $this->assertSame('Inverter Aircon 1.5HP', $found['product']);
        $this->assertSame('sold', $found['status']);
        $this->assertSame('Juan Dela Cruz', $found['customer']);
        $this->assertTrue($found['under_warranty']);
        $this->assertSame(['sold', 'received'], array_column($found['history'], 'event'));

        $available = $this->getJson("/api/serials/available?product_id={$this->product}&warehouse_id={$this->main}")->assertOk()->json('serials');
        $this->assertSame(['AC15-0002'], $available);
    }
}
