<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K23 — Stock documents do not refuse service products, and production does
 * not refuse a variant product without its variant.
 */
class K23ServiceVariantStockDocsTest extends DatatestCase
{
    use W3Helpers;

    private function bom($output)
    {
        return $this->postJson('/api/manufacturing/boms', ['name' => 'W3 BOM', 'product_id' => $output, 'output_quantity' => 1,
            'items' => [['product_id' => $this->cement, 'quantity' => 2]]]);
    }

    /**
     * K23 — A service product is accepted as the output of a bill of materials.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_service_cannot_be_the_output_of_a_bill_of_materials()
    {
        $service = $this->serviceProduct();

        $response = $this->actAs($this->owner)->bom($service);

        $this->assertRefused($response, 'building a service would consume cement and produce nothing');
        $this->assertSame(0, DB::table('boms')->where('product_id', $service)->count());
    }

    /**
     * K23 — Completing a production order for a service consumes components and produces nothing.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_producing_a_service_does_not_consume_components()
    {
        $service = $this->serviceProduct();
        $bom = DB::table('boms')->insertGetId(['name' => 'Legacy service BOM', 'product_id' => $service, 'output_quantity' => 1]);
        DB::table('bom_items')->insert(['bom_id' => $bom, 'product_id' => $this->cement, 'quantity' => 2]);
        $this->actAs($this->owner)->postJson('/api/manufacturing/orders', ['bom_id' => $bom, 'warehouse_id' => $this->main,
            'quantity' => 1, 'date' => $this->today()])->assertOk();
        $order = $this->lastId('production_orders');

        $response = $this->postJson("/api/manufacturing/orders/{$order}/complete");

        $this->assertRefused($response, 'a service cannot be produced');
        $this->assertEquals(50, $this->shelf($this->main), 'the 2 bags of cement must not be consumed');
    }

    /**
     * K23 — A variant product without its variant is accepted as a BOM output.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_variant_product_cannot_be_a_bom_output_without_its_variant()
    {
        $paint = $this->variantProduct();

        $response = $this->actAs($this->owner)->bom($paint);

        $this->assertRefused($response, 'a variant product needs its variant, or the output lands on a variant-less row');
        $this->assertSame(0, DB::table('boms')->where('product_id', $paint)->count());
    }

    /**
     * K23 — An adjustment records stock for a service product.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_an_adjustment_cannot_add_stock_of_a_service()
    {
        $service = $this->serviceProduct();

        $response = $this->actAs($this->owner)->adjust([$this->adjLine(10, 'add', ['product_id' => $service])]);

        $this->assertRefused($response, 'a service is never stocked');
        $this->assertSame(0, DB::table('adjustment_details')->where('product_id', $service)->count(),
            'no adjustment line may record +10 of a service');
    }
}
