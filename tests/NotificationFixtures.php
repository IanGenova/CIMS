<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A small hardware shop with the people who need to hear about things:
 * a purchaser, an approver, a stockman, a manager and a cashier, each with
 * only their own permissions. Used by the activity notification tests.
 */
trait NotificationFixtures
{
    protected $purchaser;
    protected $approver;
    protected $stockman;
    protected $manager;
    protected $cashier;
    protected $warehouseId;
    protected $branchId;
    protected $supplierId;
    protected $clientId;
    protected $productId;
    protected $unitId;

    private static $userSeq = 0;

    protected function setUpShop()
    {
        $this->purchaser = $this->userWith(['Purchases_view', 'Purchases_add']);
        $this->approver = $this->userWith(['purchases_approve', 'Purchases_view']);
        $this->stockman = $this->userWith(['goods_receipt_view', 'goods_receipt_add', 'transfer_view', 'transfer_add',
            'adjustment_view', 'adjustment_add', 'stock_count_view', 'stock_count_add', 'stock_count_edit']);
        $this->manager = $this->userWith(['record_view', 'Reports_profit']);
        $this->cashier = $this->userWith(['Pos_view', 'Sales_add', 'Sale_Returns_view', 'Sale_Returns_add']);

        DB::table('settings')->insert([
            'email' => 'shop@example.invalid', 'CompanyName' => 'Test Hardware', 'CompanyPhone' => '0917',
            'CompanyAdress' => 'Iloilo', 'purchase_approval_threshold' => 10000,
        ]);
        $this->warehouseId = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->branchId = DB::table('warehouses')->insertGetId(['name' => 'Branch Warehouse']);
        $this->supplierId = DB::table('providers')->insertGetId([
            'name' => 'Luzon Builders Supply', 'code' => 1, 'email' => 'l@example.invalid',
            'phone' => '0917', 'country' => 'PH', 'city' => 'QC', 'adresse' => 'x',
        ]);
        $this->clientId = DB::table('clients')->insertGetId(['name' => 'walk-in-customer', 'code' => 1]);
        $this->unitId = DB::table('units')->insertGetId(['name' => 'Bag', 'ShortName' => 'bag', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'CEM', 'name' => 'Cement']);
        $this->productId = DB::table('products')->insertGetId([
            'code' => 'HW-0004', 'Type_barcode' => 'CODE128', 'name' => 'White Cement 1kg',
            'cost' => 58, 'price' => 75.4, 'category_id' => $category,
            'unit_id' => $this->unitId, 'unit_sale_id' => $this->unitId, 'unit_purchase_id' => $this->unitId,
        ]);
        DB::table('product_warehouse')->insert([
            ['product_id' => $this->productId, 'warehouse_id' => $this->warehouseId, 'qte' => 10],
            ['product_id' => $this->productId, 'warehouse_id' => $this->branchId, 'qte' => 0],
        ]);
    }

    protected function userWith(array $permissions)
    {
        $i = ++self::$userSeq;
        $roleId = DB::table('roles')->insertGetId(['name' => "role$i", 'label' => "Role $i"]);
        foreach ($permissions as $name) {
            $pid = DB::table('permissions')->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'label' => $name]);
            DB::table('permission_role')->insert(['permission_id' => $pid, 'role_id' => $roleId]);
        }
        $user = User::create([
            'firstname' => 'User', 'lastname' => (string) $i, 'username' => "user$i",
            'email' => "user$i@example.invalid", 'password' => bcrypt('Str0ngEnough'),
            'statut' => 1, 'role_id' => $roleId, 'phone' => '0917',
        ]);
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);

        return $user;
    }

    /** The notices a user's bell shows, asked for as the bell asks. */
    protected function bellOf(User $user)
    {
        return collect($this->actingAs($user, 'api')->getJson('/api/notifications')->assertOk()->json('notifications'));
    }

    protected function seesMention(User $user, $text)
    {
        return $this->bellOf($user)->contains(fn ($n) => stripos($n['title'], $text) !== false);
    }

    protected function raisePurchase($unitCost, $quantity = 20)
    {
        $this->actingAs($this->purchaser, 'api')->postJson('/api/purchases', [
            'date' => now()->toDateString(), 'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId, 'statut' => 'ordered', 'notes' => '',
            'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'details' => [[
                'product_id' => $this->productId, 'product_variant_id' => null,
                'quantity' => $quantity, 'Unit_cost' => $unitCost, 'purchase_unit_id' => $this->unitId,
                'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            ]],
        ])->assertOk();

        return DB::table('purchases')->orderByDesc('id')->first();
    }
}
