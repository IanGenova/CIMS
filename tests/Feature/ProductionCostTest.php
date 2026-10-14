<?php

namespace Tests\Feature;

use App\utils\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Building a product updates its cost.
 *
 * Completing a production order recorded what the build cost but left the
 * finished product's own cost as whatever was typed on it, so stock valuation
 * and profit used the typed figure, not what the kits actually cost to make.
 * The cost is now the weighted average of the stock already on hand (at its
 * current cost) and the units just built (at the build cost).
 *
 * Kit recipe: 1 PVC pipe (cost 100) + 1 elbow (cost 20) -> 120 a kit.
 */
class ProductionCostTest extends TestCase
{
    use RefreshDatabase;

    private $warehouse;
    private $branch;
    private $kit;
    private $elbow;
    private $bom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->branch = DB::table('warehouses')->insertGetId(['name' => 'Branch Warehouse']);
        $unit = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $category = DB::table('categories')->insertGetId(['code' => 'PLB', 'name' => 'Plumbing']);
        $make = function ($code, $name, $cost) use ($unit, $category) {
            return DB::table('products')->insertGetId([
                'code' => $code, 'Type_barcode' => 'CODE128', 'name' => $name, 'cost' => $cost, 'price' => $cost * 1.4,
                'category_id' => $category, 'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
            ]);
        };
        $pipe = $make('HW-0091', 'PVC Pipe 1/2 inch x 3m', 100);
        $this->elbow = $make('HW-0097', 'PVC Elbow 1/2 inch', 20);
        $this->kit = $make('KIT-001', 'Plumbing Starter Kit', 0);

        DB::table('product_warehouse')->insert([
            ['product_id' => $pipe, 'warehouse_id' => $this->warehouse, 'qte' => 50],
            ['product_id' => $this->elbow, 'warehouse_id' => $this->warehouse, 'qte' => 50],
        ]);

        $this->bom = DB::table('boms')->insertGetId(['name' => 'Starter kit', 'product_id' => $this->kit, 'output_quantity' => 1]);
        DB::table('bom_items')->insert([
            ['bom_id' => $this->bom, 'product_id' => $pipe, 'quantity' => 1, 'waste_percent' => 0],
            ['bom_id' => $this->bom, 'product_id' => $this->elbow, 'quantity' => 1, 'waste_percent' => 0],
        ]);
    }

    private function build($qty, $ref)
    {
        $id = DB::table('production_orders')->insertGetId([
            'Ref' => $ref, 'bom_id' => $this->bom, 'product_id' => $this->kit, 'warehouse_id' => $this->warehouse,
            'date' => now()->toDateString(), 'quantity' => $qty, 'status' => 'planned',
        ]);

        return ProductionService::complete($id);
    }

    private function kitCost()
    {
        return (float) DB::table('products')->where('id', $this->kit)->value('cost');
    }

    /** @test */
    public function the_first_build_sets_the_cost_to_what_it_cost_to_make()
    {
        $result = $this->build(5, 'MO_1');

        $this->assertEqualsWithDelta(120, $result['unit_cost'], 0.001);
        $this->assertEqualsWithDelta(120, $this->kitCost(), 0.001);
    }

    /** @test */
    public function a_dearer_build_averages_with_the_stock_already_on_hand()
    {
        $this->build(5, 'MO_1');                                  // 5 kits at 120
        DB::table('products')->where('id', $this->elbow)->update(['cost' => 40]);
        $this->build(5, 'MO_2');                                  // 5 more at 140

        $this->assertEqualsWithDelta(130, $this->kitCost(), 0.001);
    }

    /** @test */
    public function stock_in_every_warehouse_counts_towards_the_average()
    {
        $this->build(5, 'MO_1');                                  // 5 at 120 in Main
        DB::table('product_warehouse')->insert(['product_id' => $this->kit, 'warehouse_id' => $this->branch, 'qte' => 5]);
        DB::table('products')->where('id', $this->elbow)->update(['cost' => 40]);
        $this->build(10, 'MO_2');                                 // 10 at 140; 10 on hand at 120

        $this->assertEqualsWithDelta(130, $this->kitCost(), 0.001);
    }

    /** @test */
    public function stock_that_had_gone_negative_does_not_skew_the_average()
    {
        DB::table('product_warehouse')->insert(['product_id' => $this->kit, 'warehouse_id' => $this->branch, 'qte' => -3]);

        $this->build(5, 'MO_1');

        $this->assertEqualsWithDelta(120, $this->kitCost(), 0.001);
    }
}
