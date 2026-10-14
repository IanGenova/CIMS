<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * A sale's invoice still downloads as a PDF.
 *
 * The PDF library (dompdf, via barryvdh/laravel-dompdf) was moved from 0.9 to
 * 2.x to clear its critical security advisories before CIMS goes online. That
 * release dropped the facade class the `PDF` alias pointed at, which would
 * break every invoice, quotation, purchase, return and payment PDF at once.
 */
class SalesControllerPdfTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function a_sale_invoice_downloads_as_a_real_pdf()
    {
        $this->actAsUserWith(['Pos_view', 'Sales_view', 'Sales_add']);
        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC', 'currency_id' => null, 'logo' => 'logo-default.png']);
        DB::table('pos_settings')->insert(['note_customer' => 'Thank you', 'show_note' => 1, 'show_barcode' => 1,
            'show_discount' => 1, 'show_customer' => 1, 'show_email' => 1, 'show_phone' => 1, 'show_address' => 1]);
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $client = DB::table('clients')->insertGetId(['name' => 'Juan Dela Cruz', 'code' => 1]);
        $unit = DB::table('units')->insertGetId(['name' => 'Bag', 'ShortName' => 'bag', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'CEM', 'name' => 'Cement']);
        $product = DB::table('products')->insertGetId([
            'code' => 'CEM-001', 'Type_barcode' => 'CODE128', 'name' => 'Portland Cement 40kg', 'cost' => 230, 'price' => 265,
            'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            'TaxNet' => 0, 'tax_method' => '2',
        ]);
        DB::table('product_warehouse')->insert(['product_id' => $product, 'warehouse_id' => $warehouse, 'qte' => 50]);

        $this->postJson('/api/pos/CreatePOS', [
            'client_id' => $client, 'warehouse_id' => $warehouse, 'GrandTotal' => 530, 'amount' => 530,
            'received_amount' => 530, 'change' => 0, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'payment' => ['Reglement' => 'Cash'],
            'details' => [['product_id' => $product, 'product_variant_id' => null, 'sale_unit_id' => $unit, 'quantity' => 2,
                'Unit_price' => 265, 'subtotal' => 530, 'discount' => 0, 'discount_Method' => '2', 'tax_percent' => 0, 'tax_method' => '2']],
        ])->assertOk();
        $saleId = DB::table('sales')->value('id');

        $response = $this->get("/api/Sale_PDF/{$saleId}");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        // The shop logo is on the invoice, read from disk. The templates used to
        // fetch it (and the stylesheet) over HTTP from the app itself: a hang on
        // a one-at-a-time server, and the remote-fetch path several dompdf
        // advisories are about. Remote fetching is now off.
        $this->assertFalse(config('dompdf.options.enable_remote'));
        $this->assertMatchesRegularExpression('#/Subtype\s*/Image#', $response->getContent(), 'the logo is not on the invoice');
    }
}
