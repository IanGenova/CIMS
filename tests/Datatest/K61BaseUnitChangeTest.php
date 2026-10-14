<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K61 — The base unit of a product with stock on hand can be changed, which
 * silently restates the quantity (70 bags become 70 kilograms).
 */
class K61BaseUnitChangeTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K61 — The product edit changes cement's base unit from Bag to Kilogram with 70 bags on hand.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_base_unit_cannot_change_while_stock_is_on_hand()
    {
        $kg = DB::table('units')->insertGetId(['name' => 'Kilogram', 'ShortName' => 'kg', 'operator' => '*', 'operator_value' => 1]);

        $response = $this->actAs($this->owner)->putJson("/api/Products/{$this->cement}",
            $this->productBody(['unit_id' => $kg, 'unit_sale_id' => $kg, 'unit_purchase_id' => $kg]));

        $this->assertRefused($response, '70 bags are on hand; changing the base unit would restate them as 70 kilograms');
        $this->assertSame($this->bag, (int) DB::table('products')->where('id', $this->cement)->value('unit_id'));
    }
}
