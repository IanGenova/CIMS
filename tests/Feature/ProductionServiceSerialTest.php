<?php

namespace Tests\Feature;

use App\utils\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Production cannot consume or produce serial-tracked units.
 *
 * A build takes components off the shelf by quantity and puts finished goods
 * on it by quantity; neither side names which units. For a serial-tracked
 * product that would break the serial list, so such a recipe is refused
 * before anything moves.
 */
class ProductionServiceSerialTest extends TestCase
{
    use RefreshDatabase;

    private $warehouse;
    private $unit;
    private $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->unit = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $this->category = DB::table('categories')->insertGetId(['code' => 'EL', 'name' => 'Electronics']);
    }

    private function product($code, $name, array $extra = [])
    {
        return DB::table('products')->insertGetId(array_merge([
            'code' => $code, 'Type_barcode' => 'CODE128', 'name' => $name, 'cost' => 100, 'price' => 150,
            'category_id' => $this->category, 'unit_id' => $this->unit, 'unit_sale_id' => $this->unit, 'unit_purchase_id' => $this->unit,
        ], $extra));
    }

    private function order($output, array $components)
    {
        $bom = DB::table('boms')->insertGetId(['name' => 'Bundle', 'product_id' => $output, 'output_quantity' => 1]);
        foreach ($components as $c) {
            DB::table('bom_items')->insert(['bom_id' => $bom, 'product_id' => $c, 'quantity' => 1, 'waste_percent' => 0]);
            DB::table('product_warehouse')->insert(['product_id' => $c, 'warehouse_id' => $this->warehouse, 'qte' => 10]);
        }

        return DB::table('production_orders')->insertGetId([
            'Ref' => 'MO_1', 'bom_id' => $bom, 'product_id' => $output, 'warehouse_id' => $this->warehouse,
            'date' => now()->toDateString(), 'quantity' => 2, 'status' => 'planned',
        ]);
    }

    private function assertRefused($orderId, $message)
    {
        try {
            ProductionService::complete($orderId);
            $this->fail('The build should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertEquals('planned', DB::table('production_orders')->where('id', $orderId)->value('status'));
    }

    /** @test */
    public function a_recipe_that_uses_a_serial_tracked_component_is_refused()
    {
        $tv = $this->product('TV-1', 'Smart TV 43in', ['has_serials' => 1]);
        $bracket = $this->product('BR-1', 'Wall Bracket');
        $bundle = $this->product('BUN-1', 'TV + Bracket Bundle');
        $order = $this->order($bundle, [$tv, $bracket]);

        $this->assertRefused($order, 'Smart TV 43in has serial numbers, so it cannot go through production.');
        $this->assertEquals(10, DB::table('product_warehouse')->where('product_id', $tv)->value('qte'));
        $this->assertEquals(10, DB::table('product_warehouse')->where('product_id', $bracket)->value('qte'));
    }

    /** @test */
    public function a_recipe_whose_output_is_serial_tracked_is_refused()
    {
        $board = $this->product('PCB-1', 'Control Board');
        $inverter = $this->product('INV-1', 'Solar Inverter 3kW', ['has_serials' => 1]);
        $order = $this->order($inverter, [$board]);

        $this->assertRefused($order, 'Solar Inverter 3kW has serial numbers, so it cannot go through production.');
        $this->assertEquals(0, (float) DB::table('product_warehouse')->where('product_id', $inverter)->value('qte'));
        $this->assertEquals(10, DB::table('product_warehouse')->where('product_id', $board)->value('qte'));
    }
}
