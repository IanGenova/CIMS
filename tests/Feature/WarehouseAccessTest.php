<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Staff tied to their branch.
 *
 * Found while testing a Stockman meant for Main Warehouse: every form offered
 * every warehouse and every save accepted any of them, because a user had no
 * warehouses of their own. A user is now either on all warehouses (the
 * default, so nobody is locked out) or on a chosen few, and that is enforced
 * by the server -- what forms offer, what lists show, what a save accepts.
 *
 * Seeing another branch's stock level stays allowed (it is how a stockman
 * asks for a transfer); a transfer may go TO any branch, but only FROM one
 * of your own.
 */
class WarehouseAccessTest extends TestCase
{
    use RefreshDatabase;

    private $main;
    private $branch;
    private $product;
    private $unit;
    private $client;
    private $author;
    private static $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->main = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->branch = DB::table('warehouses')->insertGetId(['name' => 'Branch Warehouse']);
        $this->unit = DB::table('units')->insertGetId(['name' => 'Bag', 'ShortName' => 'bag', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'CEM', 'name' => 'Cement']);
        $this->product = DB::table('products')->insertGetId([
            'code' => 'HW-0004', 'Type_barcode' => 'CODE128', 'name' => 'White Cement 1kg', 'cost' => 58, 'price' => 75.4,
            'category_id' => $category, 'unit_id' => $this->unit, 'unit_sale_id' => $this->unit, 'unit_purchase_id' => $this->unit,
        ]);
        DB::table('product_warehouse')->insert([
            ['product_id' => $this->product, 'warehouse_id' => $this->main, 'qte' => 20],
            ['product_id' => $this->product, 'warehouse_id' => $this->branch, 'qte' => 10],
        ]);
        $this->client = DB::table('clients')->insertGetId(['name' => 'walk-in-customer', 'code' => 1]);
        // Who wrote the documents the tests seed.
        $this->author = $this->staff(['record_view'])->id;
    }

    /** A user with these permissions, on all warehouses or only the ones given. */
    private function staff(array $permissions, array $warehouses = null)
    {
        $i = ++self::$seq;
        $role = DB::table('roles')->insertGetId(['name' => "r$i", 'label' => "R$i"]);
        foreach ($permissions as $p) {
            $pid = DB::table('permissions')->where('name', $p)->value('id') ?? DB::table('permissions')->insertGetId(['name' => $p, 'label' => $p]);
            DB::table('permission_role')->insert(['permission_id' => $pid, 'role_id' => $role]);
        }
        $user = User::create(['firstname' => 'S', 'lastname' => (string) $i, 'username' => "s$i", 'email' => "s$i@example.invalid",
            'password' => bcrypt('Str0ngEnough'), 'statut' => 1, 'role_id' => $role, 'phone' => '0917']);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $user->id]);

        if ($warehouses !== null) {
            DB::table('users')->where('id', $user->id)->update(['all_warehouses' => 0]);
            foreach ($warehouses as $w) {
                DB::table('user_warehouse')->insert(['user_id' => $user->id, 'warehouse_id' => $w]);
            }
        }

        return $user->fresh();
    }

    private function stockman(array $warehouses = null)
    {
        return $this->staff(['adjustment_view', 'adjustment_add', 'transfer_view', 'transfer_add', 'products_view'], $warehouses);
    }

    private function adjustment($warehouse, $ref)
    {
        return DB::table('adjustments')->insertGetId(['user_id' => $this->author, 'date' => now()->toDateString(), 'Ref' => $ref,
            'warehouse_id' => $warehouse, 'items' => 1]);
    }

    private function adjust($user, $warehouse)
    {
        return $this->actingAs($user, 'api')->postJson('/api/adjustments', [
            'warehouse_id' => $warehouse, 'date' => now()->toDateString(), 'notes' => '',
            'details' => [['product_id' => $this->product, 'product_variant_id' => null, 'quantity' => 1, 'type' => 'add']],
        ]);
    }

    // ---------------------------------------------------------------- forms

    /** @test */
    public function a_branch_stockman_is_offered_only_their_warehouse()
    {
        $names = collect($this->actingAs($this->stockman([$this->main]), 'api')
            ->getJson('/api/adjustments/create')->assertOk()->json('warehouses'))->pluck('name')->all();

        $this->assertSame(['Main Warehouse'], $names);
    }

    /** @test */
    public function staff_on_all_warehouses_are_offered_all_of_them()
    {
        $names = collect($this->actingAs($this->stockman(), 'api')
            ->getJson('/api/adjustments/create')->assertOk()->json('warehouses'))->pluck('name')->all();

        $this->assertSame(['Main Warehouse', 'Branch Warehouse'], $names);
    }

    // ---------------------------------------------------------------- saves

    /** @test */
    public function a_branch_stockman_cannot_adjust_another_branch()
    {
        $user = $this->stockman([$this->main]);

        $this->adjust($user, $this->branch)->assertForbidden()
            ->assertJsonFragment(['message' => 'You can only work in: Main Warehouse.']);
        $this->assertSame(10.0, (float) DB::table('product_warehouse')->where('warehouse_id', $this->branch)->value('qte'));

        $this->adjust($user, $this->main)->assertSuccessful();
    }

    /** @test */
    public function a_transfer_may_go_to_any_branch_but_only_from_your_own()
    {
        $user = $this->stockman([$this->main]);
        $send = fn ($from, $to) => $this->actingAs($user, 'api')->postJson('/api/transfers', [
            'transfer' => ['date' => now()->toDateString(), 'from_warehouse' => $from, 'to_warehouse' => $to,
                'statut' => 'completed', 'notes' => '', 'tax_rate' => 0, 'discount' => 0, 'shipping' => 0],
            'details' => [['product_id' => $this->product, 'product_variant_id' => null, 'quantity' => 2,
                'purchase_unit_id' => $this->unit, 'Unit_cost' => 58, 'tax_percent' => 0, 'tax_method' => '1',
                'discount' => 0, 'discount_Method' => '2']],
        ]);

        $send($this->main, $this->branch)->assertSuccessful();
        $send($this->branch, $this->main)->assertForbidden();

        // The form offers every branch as a destination, only their own as a source.
        $list = $this->actingAs($user, 'api')->getJson('/api/transfers?page=1&limit=10&SortField=id&SortType=desc&search=')->assertOk();
        $this->assertSame(['Main Warehouse'], collect($list->json('warehouses'))->pluck('name')->all());
        $this->assertSame(['Main Warehouse', 'Branch Warehouse'], collect($list->json('to_warehouses'))->pluck('name')->all());
        // And the transfer it made still names its destination.
        $this->assertSame('Branch Warehouse', $list->json('transfers.0.to_warehouse'));
    }

    // ---------------------------------------------------------------- lists

    /** @test */
    public function lists_show_only_the_branchs_own_documents()
    {
        $this->adjustment($this->main, 'AD_MAIN');
        $this->adjustment($this->branch, 'AD_BRANCH');
        $user = $this->staff(['adjustment_view', 'record_view'], [$this->main]);

        $refs = collect($this->actingAs($user, 'api')
            ->getJson('/api/adjustments?page=1&limit=10&SortField=id&SortType=desc&search=')->assertOk()
            ->json('adjustments'))->pluck('Ref')->all();

        $this->assertSame(['AD_MAIN'], $refs);
    }

    /** @test */
    public function another_branchs_document_cannot_be_opened_by_its_address()
    {
        $id = $this->adjustment($this->branch, 'AD_BRANCH');
        $user = $this->staff(['adjustment_view', 'record_view'], [$this->main]);

        $this->actingAs($user, 'api')->getJson("/api/adjustments/detail/{$id}")->assertNotFound();
    }

    /** @test */
    public function the_till_cannot_load_another_branchs_products()
    {
        $cashier = $this->staff(['Pos_view', 'Sales_add', 'products_view'], [$this->main]);

        $this->actingAs($cashier, 'api')->getJson("/api/Products/Warehouse/{$this->branch}?stock=1")->assertForbidden();
        $this->actingAs($cashier, 'api')->getJson("/api/Products/Warehouse/{$this->main}?stock=1")->assertOk();
    }

    /** @test */
    public function another_branchs_sale_cannot_be_voided()
    {
        $sale = DB::table('sales')->insertGetId(['user_id' => $this->author, 'date' => now()->toDateString(), 'Ref' => 'SL_B1',
            'client_id' => $this->client, 'warehouse_id' => $this->branch, 'GrandTotal' => 75.4, 'paid_amount' => 0,
            'payment_statut' => 'unpaid', 'statut' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $manager = $this->staff(['Sales_delete', 'record_view'], [$this->main]);

        $this->actingAs($manager, 'api')->postJson("/api/sales/{$sale}/void", ['reason' => 'Testing branch rules'])->assertNotFound();
        $this->assertSame('completed', DB::table('sales')->where('id', $sale)->value('statut'));
    }

    // ---------------------------------------------------------------- stock levels

    /** @test */
    public function a_branch_stockman_can_still_see_other_branches_stock_levels()
    {
        $user = $this->stockman([$this->main]);

        $stock = $this->actingAs($user, 'api')->getJson("/api/Products/Detail/{$this->product}")->assertOk();

        $this->assertStringContainsString('Branch Warehouse', $stock->getContent());
    }
}
