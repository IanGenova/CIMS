<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * "credit card" at the till.
 *
 * It used to mean "charge the card through Stripe": a card sale needed Stripe
 * keys, charged pesos as US dollars, and with no keys the till refused card
 * sales outright. Xendit replaced Stripe as the gateway (GCash/Maya through the
 * wallet buttons, everything else through a Xendit checkout), so "credit card"
 * is now what a hardware store means by it: the bank's card terminal on the
 * counter. The till records it like any other tender.
 */
class PosControllerCardPaymentTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['Pos_view', 'record_view']);

        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC']);
        DB::table('pos_settings')->insert(['note_customer' => 'Thank you', 'show_note' => 1, 'show_barcode' => 1,
            'show_discount' => 1, 'show_customer' => 1, 'show_email' => 1, 'show_phone' => 1, 'show_address' => 1]);
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $client = DB::table('clients')->insertGetId(['name' => 'walk-in-customer', 'code' => 1]);
        $unit = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'TLS', 'name' => 'Tools']);
        $product = DB::table('products')->insertGetId([
            'code' => 'HW-0101', 'Type_barcode' => 'CODE128', 'name' => 'Claw Hammer 16oz',
            'cost' => 250, 'price' => 400, 'category_id' => $category,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
        ]);
        DB::table('product_warehouse')->insert(['product_id' => $product, 'warehouse_id' => $warehouse, 'qte' => 10]);

        $this->payload = [
            'client_id' => $client, 'warehouse_id' => $warehouse,
            'GrandTotal' => 400, 'amount' => 400, 'received_amount' => 400, 'change' => 0,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'payment' => ['Reglement' => 'credit card', 'notes' => 'BDO terminal'],
            'details' => [[
                'product_id' => $product, 'product_variant_id' => null, 'sale_unit_id' => $unit,
                'quantity' => 1, 'Unit_price' => 400, 'subtotal' => 400,
                'discount' => 0, 'discount_Method' => '2', 'tax_percent' => 0, 'tax_method' => '1',
            ]],
        ];
    }

    /** @test */
    public function a_card_sale_is_recorded_without_any_gateway()
    {
        $this->postJson('/api/pos/CreatePOS', $this->payload)->assertOk()->assertJson(['success' => true]);

        $payment = DB::table('payment_sales')->first();
        $this->assertNotNull($payment, 'the card payment was recorded');
        $this->assertSame('credit card', $payment->Reglement);
        $this->assertEquals(400, $payment->montant);
        $this->assertSame('paid', DB::table('sales')->value('payment_statut'));
    }

    /** @test */
    public function the_till_is_no_longer_handed_a_stripe_key()
    {
        $data = $this->getJson('/api/pos/GetELementPos')->assertOk()->json();

        $this->assertArrayNotHasKey('stripe_key', $data);
    }
}
