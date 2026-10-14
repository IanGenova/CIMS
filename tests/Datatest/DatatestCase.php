<?php

namespace Tests\Datatest;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A small hardware shop for the data-test regression tests (.flow/DATATEST-2026-10-06.md).
 *
 * Each test drives the product through its API, as the testers did, and reads
 * the rows. Two branches (Main, Branch), cement sold by the bag (and a box of
 * 10 bags), a customer and a supplier, and four people: the owner (every
 * permission), a Branch-only cashier, a Main-only stockman, and someone with
 * no permissions at all.
 */
abstract class DatatestCase extends TestCase
{
    use RefreshDatabase;

    const ALL_PERMISSIONS = ['accounting', 'adjustment_add', 'adjustment_delete', 'adjustment_edit', 'adjustment_view', 'audit_view',
        'backup', 'barcode_view', 'brand', 'category', 'currency', 'customer_group', 'Customers_add', 'Customers_delete', 'Customers_edit',
        'customers_import', 'Customers_view', 'expense_add', 'expense_delete', 'expense_edit', 'expense_view', 'goods_receipt_add',
        'goods_receipt_delete', 'goods_receipt_edit', 'goods_receipt_view', 'manufacturing', 'payment_purchases_add',
        'payment_purchases_delete', 'payment_purchases_edit', 'payment_purchases_view', 'payment_returns_add', 'payment_returns_delete',
        'payment_returns_edit', 'payment_returns_view', 'payment_sales_add', 'payment_sales_delete', 'payment_sales_edit',
        'payment_sales_view', 'permissions_add', 'permissions_delete', 'permissions_edit', 'permissions_view', 'Pos_view', 'product_import',
        'products_add', 'products_delete', 'products_edit', 'products_view', 'Purchase_Returns_add', 'Purchase_Returns_delete',
        'Purchase_Returns_edit', 'Purchase_Returns_view', 'Purchases_add', 'purchases_approve', 'Purchases_delete', 'Purchases_edit',
        'Purchases_view', 'Quotations_add', 'Quotations_delete', 'Quotations_edit', 'Quotations_view', 'record_view', 'Reports_customers',
        'Reports_payments_purchase_Return', 'Reports_payments_Purchases', 'Reports_payments_Sale_Returns', 'Reports_payments_Sales',
        'Reports_profit', 'Reports_purchase', 'Reports_quantity_alerts', 'Reports_sales', 'Reports_suppliers', 'Sale_Returns_add',
        'Sale_Returns_delete', 'Sale_Returns_edit', 'Sale_Returns_view', 'Sales_add', 'Sales_delete', 'Sales_edit', 'Sales_view',
        'setting_system', 'stock_count_add', 'stock_count_delete', 'stock_count_edit', 'stock_count_view', 'Suppliers_add',
        'Suppliers_delete', 'Suppliers_edit', 'Suppliers_import', 'Suppliers_view', 'tax_group', 'transfer_add', 'transfer_delete',
        'transfer_edit', 'transfer_view', 'unit', 'users_add', 'users_delete', 'users_edit', 'users_view', 'warehouse', 'Warehouse_report', 'price_override'];

    protected $main;
    protected $branch;
    protected $bag;
    protected $box;
    protected $cement;
    protected $client;
    protected $supplier;
    protected $owner;
    protected $cashier;
    protected $stockman;
    protected $nobody;
    private static $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Warehouse access is cached per process; ids repeat across tests.
        \App\utils\WarehouseAccess::forget();
        foreach (self::ALL_PERMISSIONS as $name) {
            DB::table('permissions')->insert(['name' => $name, 'label' => $name]);
        }
        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC', 'purchase_approval_threshold' => 10000]);
        DB::table('pos_settings')->insert(['note_customer' => 'Thank you', 'show_note' => 1, 'show_barcode' => 1,
            'show_discount' => 1, 'show_customer' => 1, 'show_email' => 1, 'show_phone' => 1, 'show_address' => 1]);
        $this->main = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->branch = DB::table('warehouses')->insertGetId(['name' => 'Branch Warehouse']);
        $this->client = DB::table('clients')->insertGetId(['name' => 'Juan Dela Cruz', 'code' => 1]);
        $this->supplier = DB::table('providers')->insertGetId(['name' => 'Luzon Builders Supply', 'code' => 1,
            'email' => 'l@example.invalid', 'phone' => '0917', 'country' => 'PH', 'city' => 'QC', 'adresse' => 'x']);
        $this->bag = DB::table('units')->insertGetId(['name' => 'Bag', 'ShortName' => 'bag', 'operator' => '*', 'operator_value' => 1]);
        $this->box = DB::table('units')->insertGetId(['name' => 'Box of 10', 'ShortName' => 'box', 'base_unit' => $this->bag,
            'operator' => '*', 'operator_value' => 10]);
        $category = DB::table('categories')->insertGetId(['code' => 'CEM', 'name' => 'Cement']);
        $this->cement = DB::table('products')->insertGetId([
            'code' => 'HW-0001', 'Type_barcode' => 'CODE128', 'name' => 'Portland Cement 40kg', 'cost' => 230, 'price' => 265,
            'category_id' => $category, 'unit_id' => $this->bag, 'unit_sale_id' => $this->bag, 'unit_purchase_id' => $this->bag,
            'TaxNet' => 0, 'tax_method' => '2',
        ]);
        DB::table('product_warehouse')->insert([
            ['product_id' => $this->cement, 'warehouse_id' => $this->main, 'qte' => 50],
            ['product_id' => $this->cement, 'warehouse_id' => $this->branch, 'qte' => 20],
        ]);

        $this->owner = $this->person(self::ALL_PERMISSIONS);
        $this->cashier = $this->person(['Pos_view', 'Sales_add', 'Sales_view', 'Sale_Returns_add', 'Sale_Returns_view',
            'payment_sales_add', 'payment_sales_view'], [$this->branch]);
        $this->stockman = $this->person(['transfer_add', 'transfer_view', 'adjustment_add', 'adjustment_view', 'goods_receipt_add',
            'goods_receipt_view', 'stock_count_add', 'stock_count_edit', 'stock_count_view', 'products_view'], [$this->main]);
        $this->nobody = $this->person([]);
    }

    /**
     * Every request starts clean, as it would in its own PHP process in
     * production. PHPUnit sends all of a test's requests through one process, so
     * static state (StockLedger's document context above all) would otherwise
     * leak from one request into the next and hide the very defects these tests
     * exist to show. (Found by the W1 writer: a false green on K1.)
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        \App\utils\StockLedger::clear();
        \App\utils\StockLedger::fallback(null);
        \App\utils\BatchService::forget();
        \App\utils\PricingService::forget();
        \App\utils\ProductKind::forget();
        \App\utils\VatService::forget();
        \App\utils\CurrencyService::forget();
        \App\utils\LedgerService::forget();
        \App\utils\LocationService::forget();
        \App\utils\WarehouseAccess::forget();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * migrate:fresh, retried when MySQL answers 1615 ("Prepared statement needs
     * to be re-prepared") while other test databases are rebuilt on the same
     * server. A server hiccup, not a product defect.
     */
    protected function refreshTestDatabase()
    {
        if (! \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated) {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->artisan('migrate:fresh', $this->migrateFreshUsing());
                    break;
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($attempt >= 5 || strpos($e->getMessage(), '1615') === false) {
                        throw $e;
                    }
                }
            }
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    /** A user holding exactly these permissions, tied to these warehouses (none = all). */
    protected function person(array $permissions, array $warehouses = []): User
    {
        $i = ++self::$seq;
        $role = DB::table('roles')->insertGetId(['name' => "dt_role_$i", 'label' => "DT role $i"]);
        foreach ($permissions as $name) {
            DB::table('permission_role')->insert([
                'permission_id' => DB::table('permissions')->where('name', $name)->value('id'), 'role_id' => $role]);
        }
        $user = User::create(['firstname' => 'DT', 'lastname' => "User $i", 'username' => "dt_user_$i",
            'email' => "dt_user_$i@example.invalid", 'password' => bcrypt('Str0ngEnough'), 'statut' => 1, 'role_id' => $role]);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $user->id]);
        if ($warehouses) {
            DB::table('users')->where('id', $user->id)->update(['all_warehouses' => 0]);
        }
        foreach ($warehouses as $w) {
            DB::table('user_warehouse')->insert(['user_id' => $user->id, 'warehouse_id' => $w]);
        }

        return $user;
    }

    protected function as(User $user)
    {
        $this->actingAs($user, 'api');

        return $this;
    }

    protected function shelf($warehouse = null, $product = null)
    {
        return (float) DB::table('product_warehouse')->where('product_id', $product ?: $this->cement)
            ->where('warehouse_id', $warehouse ?: $this->main)->value('qte');
    }

    /** A sale line for cement as the POS sends it. */
    protected function line($qty = 1, $price = 265, array $extra = [])
    {
        return array_merge(['product_id' => $this->cement, 'product_variant_id' => null, 'sale_unit_id' => $this->bag,
            'quantity' => $qty, 'Unit_price' => $price, 'subtotal' => $price * $qty, 'discount' => 0, 'discount_Method' => '2',
            'tax_percent' => 0, 'tax_method' => '2'], $extra);
    }

    protected function pos(array $lines, $warehouse = null, array $extra = [])
    {
        $total = array_sum(array_column($lines, 'subtotal'));

        return $this->postJson('/api/pos/CreatePOS', array_merge([
            'client_id' => $this->client, 'warehouse_id' => $warehouse ?: $this->main,
            'GrandTotal' => $total, 'amount' => $total, 'received_amount' => $total, 'change' => 0,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'payment' => ['Reglement' => 'Cash'],
            'details' => $lines,
        ], $extra));
    }

    protected function lastId($table)
    {
        return DB::table($table)->orderByDesc('id')->value('id');
    }
}
