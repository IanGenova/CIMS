<?php

namespace Tests\Feature;

use App\Models\product_warehouse;
use App\utils\SerialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * A customer return takes back only units sold to that customer.
 *
 * Before, any unit recorded as sold could be booked back in under any
 * customer: Juan's aircon could be "returned" by Maria, refunding Maria for a
 * unit she never bought and leaving Juan's warranty record pointing at a unit
 * now back on the shelf. The return now has to name the customer it was sold to.
 */
class SalesReturnControllerSerialCustomerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $main;
    private $unit;
    private $product;
    private $juan;
    private $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['Pos_view', 'Sales_add', 'Sales_view', 'Sale_Returns_add', 'record_view']);
        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Appliances',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC']);
        DB::table('pos_settings')->insert(['note_customer' => 'Thank you', 'show_note' => 1, 'show_barcode' => 1,
            'show_discount' => 1, 'show_customer' => 1, 'show_email' => 1, 'show_phone' => 1, 'show_address' => 1]);
        $this->main = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->juan = DB::table('clients')->insertGetId(['name' => 'Juan Dela Cruz', 'code' => 1]);
        $this->maria = DB::table('clients')->insertGetId(['name' => 'Maria Santos', 'code' => 2]);
        $this->unit = DB::table('units')->insertGetId(['name' => 'Unit', 'ShortName' => 'unit', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'AC', 'name' => 'Aircon']);
        $this->product = DB::table('products')->insertGetId([
            'code' => 'AC-0001', 'Type_barcode' => 'CODE128', 'name' => 'Inverter Aircon 1.5HP', 'cost' => 25000, 'price' => 32000,
            'category_id' => $category, 'unit_id' => $this->unit, 'unit_sale_id' => $this->unit, 'unit_purchase_id' => $this->unit,
            'TaxNet' => 0, 'tax_method' => '2', 'has_serials' => 1, 'warranty_months' => 12,
        ]);
        DB::table('product_warehouse')->insert(['product_id' => $this->product, 'warehouse_id' => $this->main, 'qte' => 0]);

        SerialService::receive($this->product, null, $this->main, ['AC15-0001', 'AC15-0002'], 2, 'Opening', 0);
        $row = product_warehouse::where('product_id', $this->product)->where('warehouse_id', $this->main)->first();
        $row->qte = 2;
        $row->save();
    }

    private function line($serial)
    {
        return ['product_id' => $this->product, 'product_variant_id' => null, 'sale_unit_id' => $this->unit, 'quantity' => 1,
            'Unit_price' => 32000, 'subtotal' => 32000, 'discount' => 0, 'discount_Method' => '2',
            'tax_percent' => 0, 'tax_method' => '2', 'serials' => [$serial]];
    }

    private function sellTo($client, $serial)
    {
        $this->postJson('/api/pos/CreatePOS', [
            'client_id' => $client, 'warehouse_id' => $this->main, 'GrandTotal' => 32000, 'amount' => 32000,
            'received_amount' => 32000, 'change' => 0, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'payment' => ['Reglement' => 'Cash'], 'details' => [$this->line($serial)],
        ])->assertOk();

        return DB::table('sales')->orderByDesc('id')->value('Ref');
    }

    /** A return names the sale it comes from (DATATEST K5): here, the latest sale. */
    private function returnBy($client, $serial)
    {
        return $this->postJson('/api/returns/sale', ['date' => now()->toDateString(), 'client_id' => $client,
            'sale_id' => DB::table('sales')->orderByDesc('id')->value('id'),
            'warehouse_id' => $this->main, 'statut' => 'received', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0,
            'shipping' => 0, 'GrandTotal' => 32000, 'details' => [$this->line($serial)]]);
    }

    private function serial($number)
    {
        return DB::table('product_serials')->where('serial_number', $number)->first();
    }

    /** @test */
    public function a_unit_sold_to_juan_cannot_be_returned_by_maria()
    {
        $ref = $this->sellTo($this->juan, 'AC15-0001');

        // Refused at the sale itself now: a return names its sale, and Juan's sale
        // is not Maria's (DATATEST K5). The serial check stays underneath (SerialServiceTest).
        $this->returnBy($this->maria, 'AC15-0001')
            ->assertStatus(422)
            ->assertJsonFragment(["Sale {$ref} was not made to this customer."]);

        $this->assertSame('sold', $this->serial('AC15-0001')->status);
        $this->assertEquals($this->juan, $this->serial('AC15-0001')->client_id);
        $this->assertEquals(0, DB::table('sale_returns')->count(), 'nothing is booked in on a refusal');
        $this->assertEquals(1, (float) DB::table('product_warehouse')->where('product_id', $this->product)->value('qte'));
    }

    /** @test */
    public function juan_can_return_the_unit_he_bought()
    {
        $this->sellTo($this->juan, 'AC15-0001');

        $this->returnBy($this->juan, 'AC15-0001')->assertOk();

        $this->assertSame('in_stock', $this->serial('AC15-0001')->status);
        $this->assertEquals(2, (float) DB::table('product_warehouse')->where('product_id', $this->product)->value('qte'));
    }
}
