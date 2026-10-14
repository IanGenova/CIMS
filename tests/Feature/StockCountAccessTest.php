<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * A stockman can open a stock count with the stock count permissions alone.
 *
 * Found by signing in as a test Stockman (stock_count_view + stock_count_add,
 * no product editing): "Open Stock Count" bounced to the 403 page, because the
 * screen borrowed its category list from Products/create, which needs
 * products_add. The count's own list endpoint already sent the warehouses; it
 * now sends the categories too, so the screen needs nothing else.
 */
class StockCountAccessTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function the_count_list_carries_the_categories_a_count_can_be_narrowed_to()
    {
        $this->actAsUserWith(['stock_count_view', 'stock_count_add']);
        DB::table('warehouses')->insert(['name' => 'Main Warehouse']);
        DB::table('categories')->insert([
            ['code' => 'CEM', 'name' => 'Cement & Aggregates'],
            ['code' => 'PLB', 'name' => 'Plumbing'],
        ]);
        DB::table('categories')->insert(['code' => 'OLD', 'name' => 'Retired', 'deleted_at' => now()]);

        $response = $this->getJson('/api/stock_counts?limit=1')->assertOk();

        $this->assertEquals(['Main Warehouse'], collect($response->json('warehouses'))->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Cement & Aggregates', 'Plumbing'],
            collect($response->json('categories'))->pluck('name')->all());
    }

    /** @test */
    public function the_category_list_still_needs_the_stock_count_permission()
    {
        $this->actAsUserWith(['Sales_view']);

        $this->getJson('/api/stock_counts?limit=1')->assertForbidden();
    }
}
