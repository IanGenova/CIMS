<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K21 — CSV import endpoints (products, clients, suppliers) have no permission
 * check and skip product-code uniqueness (map A1).
 */
class K21CsvImportPermissionTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K21 — A user with no permissions can import products by CSV.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_without_product_import_permission_cannot_import_products()
    {
        $file = $this->csv('products.csv', [['name', 'code', 'category', 'brand', 'unit', 'price', 'cost', 'note', 'stock_alert'],
            ['NoPerm Mortar', 'NP-0001', 'Cement', 'N/A', 'bag', '1', '500', 'x', '0']]);

        $response = $this->actAs($this->nobody)->post('/api/Products/import/csv', ['products' => $file], ['Accept' => 'application/json']);

        $this->assertForbiddenStatus($response, 'A1: importing products needs the product_import permission');
        $this->assertSame(0, DB::table('products')->where('code', 'NP-0001')->count(), 'a refusal writes nothing');
    }

    /**
     * K21 — A user with no permissions can import customers by CSV.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_without_customer_import_permission_cannot_import_customers()
    {
        $file = $this->csv('clients.csv', [['name', 'email', 'phone', 'country', 'city', 'adresse'],
            ['NoPerm Client', 'noperm@example.invalid', '0917', 'PH', 'QC', 'x']]);

        $response = $this->actAs($this->nobody)->post('/api/clients/import/csv', ['clients' => $file], ['Accept' => 'application/json']);

        $this->assertForbiddenStatus($response, 'A1: importing customers needs the customers_import permission');
        $this->assertSame(0, DB::table('clients')->where('name', 'NoPerm Client')->count(), 'a refusal writes nothing');
    }

    /**
     * K21 — A user with no permissions can import suppliers by CSV.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_user_without_supplier_import_permission_cannot_import_suppliers()
    {
        $file = $this->csv('providers.csv', [['name', 'email', 'phone', 'country', 'city', 'adresse'],
            ['NoPerm Supplier', 'nopsup@example.invalid', '0917', 'PH', 'QC', 'x']]);

        $response = $this->actAs($this->nobody)->post('/api/providers/import/csv', ['providers' => $file], ['Accept' => 'application/json']);

        $this->assertForbiddenStatus($response, 'A1: importing suppliers needs the Suppliers_import permission');
        $this->assertSame(0, DB::table('providers')->where('name', 'NoPerm Supplier')->count(), 'a refusal writes nothing');
    }

    /**
     * K21 — The product import does not apply the product-code uniqueness rule.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_product_import_cannot_duplicate_an_existing_code()
    {
        $file = $this->csv('products.csv', [['name', 'code', 'category', 'brand', 'unit', 'price', 'cost', 'note', 'stock_alert'],
            ['Dup Cement', 'HW-0001', 'Cement', 'N/A', 'bag', '1', '500', 'x', '0']]);

        $this->actAs($this->owner)->post('/api/Products/import/csv', ['products' => $file], ['Accept' => 'application/json']);

        $this->assertSame(1, DB::table('products')->whereNull('deleted_at')->where('code', 'HW-0001')->count(),
            'K58/K21: code HW-0001 is already cement; the import must not create a second product with it');
    }
}
