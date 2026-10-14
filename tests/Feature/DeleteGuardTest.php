<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Settings records that other things still depend on cannot be deleted.
 *
 * Found while explaining Settings: a warehouse holding stock could be deleted
 * (its stock rows vanished with it), as could the base currency, or a
 * category or brand still on products. Each is now refused with the reason,
 * from the single delete and from "delete selected".
 */
class DeleteGuardTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $main;
    private $empty;
    private $category;
    private $brand;
    private $peso;
    private $usd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsUserWith(['warehouse', 'currency', 'category', 'brand']);

        $this->main = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->empty = DB::table('warehouses')->insertGetId(['name' => 'Old Bodega']);
        $this->peso = DB::table('currencies')->insertGetId(['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->usd = DB::table('currencies')->insertGetId(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 56, 'is_base' => 0]);
        DB::table('settings')->insert(['email' => 'shop@example.invalid', 'CompanyName' => 'Genova Hardware',
            'CompanyPhone' => '0917', 'CompanyAdress' => 'QC', 'currency_id' => $this->peso]);

        $unit = DB::table('units')->insertGetId(['name' => 'Bag', 'ShortName' => 'bag', 'operator' => '*', 'operator_value' => 1]);
        $this->category = DB::table('categories')->insertGetId(['code' => 'CEM', 'name' => 'Cement']);
        $this->brand = DB::table('brands')->insertGetId(['name' => 'Holcim', 'description' => '']);
        $product = DB::table('products')->insertGetId(['code' => 'HW-0001', 'Type_barcode' => 'CODE128', 'name' => 'Portland Cement 40kg',
            'cost' => 230, 'price' => 265, 'category_id' => $this->category, 'brand_id' => $this->brand,
            'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit]);
        DB::table('product_warehouse')->insert(['product_id' => $product, 'warehouse_id' => $this->main, 'qte' => 12]);
        DB::table('product_warehouse')->insert(['product_id' => $product, 'warehouse_id' => $this->empty, 'qte' => 0]);
    }

    private function gone($table, $id)
    {
        return DB::table($table)->where('id', $id)->value('deleted_at') !== null;
    }

    /** @test */
    public function a_warehouse_holding_stock_is_kept()
    {
        $this->deleteJson("/api/warehouses/{$this->main}")->assertStatus(422)
            ->assertJsonFragment(['message' => 'Main Warehouse still holds stock (12 units across 1 product). Transfer or adjust it out before deleting.']);
        $this->assertFalse($this->gone('warehouses', $this->main));

        $this->postJson('/api/warehouses/delete/by_selection', ['selectedIds' => [$this->main, $this->empty]])->assertStatus(422);
        $this->assertFalse($this->gone('warehouses', $this->empty), 'nothing is deleted when one of the selection is refused');
    }

    /** @test */
    public function an_empty_warehouse_can_go()
    {
        $this->deleteJson("/api/warehouses/{$this->empty}")->assertOk();
        $this->assertTrue($this->gone('warehouses', $this->empty));
    }

    /** @test */
    public function the_base_currency_is_kept()
    {
        $this->deleteJson("/api/currencies/{$this->peso}")->assertStatus(422);
        $this->assertFalse($this->gone('currencies', $this->peso));

        $this->deleteJson("/api/currencies/{$this->usd}")->assertOk();
        $this->assertTrue($this->gone('currencies', $this->usd));
    }

    /** @test */
    public function a_category_or_brand_on_products_is_kept()
    {
        $this->deleteJson("/api/categories/{$this->category}")->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cement is still used by 1 product. Move those products to another category first.']);
        $this->deleteJson("/api/brands/{$this->brand}")->assertStatus(422);
        $this->postJson('/api/categories/delete/by_selection', ['selectedIds' => [$this->category]])->assertStatus(422);
        $this->postJson('/api/brands/delete/by_selection', ['selectedIds' => [$this->brand]])->assertStatus(422);

        $this->assertFalse($this->gone('categories', $this->category));
        $this->assertFalse($this->gone('brands', $this->brand));
    }
}
