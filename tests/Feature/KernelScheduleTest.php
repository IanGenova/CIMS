<?php

namespace Tests\Feature;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the scheduler runs by itself.
 *
 * A basket forgotten on hold at the POS keeps its stock off the shelf until
 * something gives it back. Listing the holds does that, but only when someone
 * opens the POS; the scheduler does it every hour regardless.
 */
class KernelScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function events()
    {
        $schedule = new Schedule;
        $method = new \ReflectionMethod(Kernel::class, 'schedule');
        $method->setAccessible(true);
        $method->invoke($this->app->make(Kernel::class), $schedule);

        return collect($schedule->events());
    }

    /** @test */
    public function forgotten_holds_give_their_stock_back_every_hour()
    {
        $event = $this->events()->first(function ($e) {
            return $e->description === 'release-forgotten-holds';
        });

        $this->assertNotNull($event, 'no hourly release of forgotten holds');
        $this->assertSame('0 * * * *', $event->expression);

        // And running it does the release.
        $warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $unit = DB::table('units')->insertGetId(['name' => 'Bag', 'ShortName' => 'bag', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'CEM', 'name' => 'Cement']);
        $product = DB::table('products')->insertGetId(['code' => 'HW-0001', 'Type_barcode' => 'CODE128', 'name' => 'Portland Cement 40kg',
            'cost' => 230, 'price' => 265, 'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit]);
        DB::table('product_warehouse')->insert(['product_id' => $product, 'warehouse_id' => $warehouse, 'qte' => 7]);
        DB::table('pos_holds')->insert(['user_id' => 1, 'warehouse_id' => $warehouse, 'label' => 'Mang Jose',
            'cart' => '[]', 'item_count' => 1, 'total' => 795, 'stock_held' => true,
            'reserved' => json_encode([['product_id' => $product, 'variant_id' => null, 'qty' => 3, 'batch' => false]]),
            'created_at' => now()->subHours(13), 'updated_at' => now()->subHours(13)]);

        // The job's own callback. Not $event->run(): since Laravel 8.83 that
        // leaves a shutdown hook for the overlap lock, which fires after the
        // test app is gone and fails the whole PHPUnit process.
        $callback = new \ReflectionProperty($event, 'callback');
        $callback->setAccessible(true);
        $this->app->call($callback->getValue($event));

        $this->assertEquals(10, (float) DB::table('product_warehouse')->where('product_id', $product)->value('qte'));
    }
}
