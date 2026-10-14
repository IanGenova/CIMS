<?php

namespace Tests\Datatest\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Request bodies and fixtures for the W3 data-test regressions (.flow/DATATEST-2026-10-06.md):
 * access, master data and validation clusters. Used by DatatestCase subclasses,
 * which supply $this->cement, $this->bag, $this->main and the four people.
 */
trait W3Helpers
{
    /**
     * Client-side (emulated) prepares for the rest of the test. The shared
     * MySQL server holds ~2,300 tables against a table_definition_cache of 600,
     * so while other suites rebuild their databases a server-side prepared
     * statement on a wide table (products) fails with 1615 "needs to be
     * re-prepared" -- an environment fault that would mask the defect under test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->emulatePrepares();
    }

    /** The same, before the migrations run (they fail on 1615 the same way). */
    protected function refreshTestDatabase()
    {
        $this->emulatePrepares();
        parent::refreshTestDatabase();
    }

    protected function emulatePrepares()
    {
        DB::connection()->getPdo()->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
    }

    protected function today()
    {
        return now()->toDateString();
    }

    /** Switch the signed-in person, clearing the per-process warehouse cache. */
    protected function actAs($user)
    {
        \App\utils\WarehouseAccess::forget();

        return $this->as($user);
    }

    /** A status that is a refusal: 4xx, not a success and not a crash. */
    protected function assertRefused($response, $message)
    {
        $status = $response->getStatusCode();
        $this->assertTrue($status >= 400 && $status < 500,
            "$message (got HTTP $status: " . substr((string) $response->getContent(), 0, 300) . ')');
    }

    protected function assertForbiddenStatus($response, $message)
    {
        $status = $response->getStatusCode();
        $this->assertSame(403, $status, "$message (got HTTP $status: " . substr((string) $response->getContent(), 0, 300) . ')');
    }

    // ------------------------------------------------------------------ products

    /** The full product form for cement, as the edit screen sends it back. */
    protected function productBody(array $extra = [], $id = null)
    {
        $p = DB::table('products')->find($id ?: $this->cement);

        return array_merge(['code' => $p->code, 'Type_barcode' => 'CODE128', 'name' => $p->name, 'price' => (float) $p->price,
            'cost' => (float) $p->cost, 'category_id' => $p->category_id, 'brand_id' => 'null', 'unit_id' => $p->unit_id,
            'unit_sale_id' => $p->unit_sale_id, 'unit_purchase_id' => $p->unit_purchase_id, 'TaxNet' => 0, 'tax_method' => '2',
            'tax_group_id' => 'null', 'note' => 'W3', 'stock_alert' => 0, 'is_variant' => 'false'], $extra);
    }

    /** A new-product form (POST /api/Products). */
    protected function newProductBody($code, array $extra = [])
    {
        return array_merge(['code' => $code, 'Type_barcode' => 'CODE128', 'name' => "W3 $code", 'price' => 100, 'cost' => 50,
            'category_id' => DB::table('categories')->value('id'), 'unit_id' => $this->bag,
            'unit_sale_id' => $this->bag, 'unit_purchase_id' => $this->bag, 'TaxNet' => 0, 'tax_method' => '2',
            'tax_group_id' => 'null', 'stock_alert' => 0, 'is_variant' => 'false'], $extra);
    }

    protected function productRow(array $attrs = [], $mainQty = 0, $branchQty = 0)
    {
        static $n = 0;
        $n++;
        $id = DB::table('products')->insertGetId(array_merge([
            'code' => 'W3-' . $n . '-' . substr(uniqid(), -6), 'Type_barcode' => 'CODE128', 'name' => "W3 item $n", 'cost' => 100,
            'price' => 150, 'category_id' => DB::table('categories')->value('id'), 'unit_id' => $this->bag,
            'unit_sale_id' => $this->bag, 'unit_purchase_id' => $this->bag, 'TaxNet' => 0, 'tax_method' => '2',
        ], $attrs));
        DB::table('product_warehouse')->insert([
            ['product_id' => $id, 'warehouse_id' => $this->main, 'qte' => $mainQty],
            ['product_id' => $id, 'warehouse_id' => $this->branch, 'qte' => $branchQty],
        ]);

        return $id;
    }

    protected function serviceProduct()
    {
        return $this->productRow(['is_service' => 1, 'name' => 'Delivery service']);
    }

    /** A product sold in variants (Red, Blue), each with an empty stock row. */
    protected function variantProduct()
    {
        $id = $this->productRow(['is_variant' => 1, 'name' => 'Paint 1L']);
        foreach (['Red', 'Blue'] as $name) {
            $v = DB::table('product_variants')->insertGetId(['product_id' => $id, 'name' => $name, 'qty' => 0]);
            DB::table('product_warehouse')->insert(['product_id' => $id, 'product_variant_id' => $v, 'warehouse_id' => $this->main, 'qte' => 0]);
        }

        return $id;
    }

    // ------------------------------------------------------------------ documents

    protected function saleBody(array $lines, array $extra = [])
    {
        $total = array_sum(array_column($lines, 'subtotal'));

        return array_merge(['date' => $this->today(), 'client_id' => $this->client, 'warehouse_id' => $this->main,
            'statut' => 'completed', 'notes' => 'W3', 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => $total, 'amount' => 0, 'received_amount' => 0, 'change' => 0,
            'payment' => ['status' => 'pending', 'Reglement' => 'Cash'], 'details' => $lines], $extra);
    }

    protected function sale(array $lines, array $extra = [])
    {
        return $this->postJson('/api/sales', $this->saleBody($lines, $extra));
    }

    /** An unpaid POS sale of two bags at Main; returns its id. */
    protected function unpaidSale($warehouse = null)
    {
        $this->pos([$this->line(2)], $warehouse, ['amount' => 0, 'received_amount' => 0])->assertOk();

        return $this->lastId('sales');
    }

    protected function buyLine($qty = 10, $cost = 230, array $extra = [])
    {
        return array_merge(['id' => 0, 'no_unit' => 1, 'product_id' => $this->cement, 'product_variant_id' => null,
            'purchase_unit_id' => $this->bag, 'quantity' => $qty, 'Unit_cost' => $cost, 'subtotal' => $qty * $cost,
            'discount' => 0, 'discount_Method' => '2', 'tax_percent' => 0, 'tax_method' => '1'], $extra);
    }

    protected function purchaseBody(array $lines, array $extra = [])
    {
        return array_merge(['date' => $this->today(), 'supplier_id' => $this->supplier, 'warehouse_id' => $this->main,
            'statut' => 'ordered', 'notes' => 'W3', 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => array_sum(array_column($lines, 'subtotal')), 'details' => $lines], $extra);
    }

    protected function purchase(array $lines, array $extra = [])
    {
        return $this->postJson('/api/purchases', $this->purchaseBody($lines, $extra));
    }

    protected function saleReturn(array $lines, array $extra = [])
    {
        // A return names its sale (DATATEST K5): by default the customer's latest one.
        return $this->postJson('/api/returns/sale', array_merge(['date' => $this->today(), 'client_id' => $this->client,
            'sale_id' => \Illuminate\Support\Facades\DB::table('sales')->where('client_id', $this->client)->orderByDesc('id')->value('id'),
            'warehouse_id' => $this->main, 'statut' => 'received', 'notes' => 'W3', 'tax_rate' => 0, 'TaxNet' => 0,
            'discount' => 0, 'shipping' => 0, 'GrandTotal' => array_sum(array_column($lines, 'subtotal')),
            'details' => $lines], $extra));
    }

    protected function purchaseReturn(array $lines, array $extra = [])
    {
        return $this->postJson('/api/returns/purchase', array_merge(['date' => $this->today(), 'supplier_id' => $this->supplier,
            'warehouse_id' => $this->main, 'statut' => 'completed', 'notes' => 'W3', 'tax_rate' => 0, 'discount' => 0,
            'shipping' => 0, 'GrandTotal' => array_sum(array_column($lines, 'subtotal')), 'details' => $lines], $extra));
    }

    protected function quotation(array $lines, array $extra = [])
    {
        return $this->postJson('/api/quotations', array_merge(['date' => $this->today(), 'client_id' => $this->client,
            'warehouse_id' => $this->main, 'statut' => 'pending', 'notes' => 'W3', 'tax_rate' => 0, 'TaxNet' => 0,
            'discount' => 0, 'shipping' => 0, 'GrandTotal' => array_sum(array_column($lines, 'subtotal')),
            'details' => $lines], $extra));
    }

    protected function moveLine($qty, array $extra = [])
    {
        return array_merge(['id' => 0, 'no_unit' => 1, 'product_id' => $this->cement, 'product_variant_id' => null,
            'purchase_unit_id' => $this->bag, 'quantity' => $qty, 'Unit_cost' => 230, 'subtotal' => 230 * $qty,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2'], $extra);
    }

    protected function transfer(array $lines, array $transferExtra = [])
    {
        return $this->postJson('/api/transfers', ['transfer' => array_merge(['date' => $this->today(),
            'from_warehouse' => $this->main, 'to_warehouse' => $this->branch, 'statut' => 'completed', 'notes' => 'W3',
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0], $transferExtra),
            'details' => $lines, 'GrandTotal' => array_sum(array_column($lines, 'subtotal'))]);
    }

    protected function adjLine($qty, $type = 'add', array $extra = [])
    {
        return array_merge(['id' => 0, 'product_id' => $this->cement, 'product_variant_id' => null, 'quantity' => $qty,
            'type' => $type], $extra);
    }

    protected function adjust(array $lines, array $extra = [])
    {
        return $this->postJson('/api/adjustments', array_merge(['date' => $this->today(), 'warehouse_id' => $this->main,
            'notes' => 'W3', 'details' => $lines], $extra));
    }

    protected function expenseCategory()
    {
        return DB::table('expense_categories')->insertGetId(['name' => 'Utilities', 'description' => 'W3', 'user_id' => $this->owner->id]);
    }

    protected function expense(array $extra = [])
    {
        return $this->postJson('/api/expenses', ['expense' => array_merge(['date' => $this->today(),
            'warehouse_id' => $this->main, 'category_id' => $this->expenseCategory(), 'details' => 'Electricity',
            'amount' => 10], $extra)]);
    }

    protected function clientBody(array $extra = [])
    {
        return array_merge(['name' => 'Maria Santos', 'adresse' => 'Quezon City', 'phone' => '0917', 'email' => 'maria@example.invalid',
            'country' => 'PH', 'city' => 'QC'], $extra);
    }

    protected function providerBody(array $extra = [])
    {
        return array_merge(['name' => 'Visayas Hardware Supply', 'adresse' => 'Cebu', 'phone' => '0918', 'email' => 'v@example.invalid',
            'country' => 'PH', 'city' => 'Cebu'], $extra);
    }

    protected function currency($code = 'USD', $rate = 58, array $extra = [])
    {
        return DB::table('currencies')->insertGetId(array_merge(['code' => $code, 'name' => $code, 'symbol' => '$',
            'exchange_rate' => $rate], $extra));
    }

    /** A CSV file for the import endpoints. */
    protected function csv($name, array $rows)
    {
        $text = '';
        foreach ($rows as $row) {
            $text .= implode(',', $row) . "\n";
        }

        return UploadedFile::fake()->createWithContent($name, $text);
    }

    /** Audit rows for one record, newest first. */
    protected function auditRows($typeSuffix, $id)
    {
        return DB::table('audit_logs')->where('auditable_type', 'like', '%' . $typeSuffix)->where('auditable_id', $id)
            ->orderByDesc('id')->get();
    }
}
