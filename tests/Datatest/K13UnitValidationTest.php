<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K13: units accept any operator and any (negative, zero, null) factor, and a
 * line's unit is never checked against the product's base unit (map S7:
 * quantities convert to the base unit by the unit's operator * or / and value).
 */
class K13UnitValidationTest extends DatatestCase
{
    use W1Helpers;

    /**
     * K13 — a unit with a negative factor is stored, and selling in it raises stock.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_unit_factor_must_be_positive()
    {
        $units = DB::table('units')->count();

        $response = $this->as($this->owner)->postJson('/api/units',
            ['name' => 'Negative bag', 'ShortName' => 'nbag', 'base_unit' => $this->bag, 'operator' => '*', 'operator_value' => -1]);

        $this->assertRefused($response, 'a factor of -1 is refused');
        $this->assertSame($units, DB::table('units')->count(), 'no unit is stored');
    }

    /**
     * K13 — a factor of zero is stored (every quantity in it converts to nothing, or divides by zero).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_unit_factor_cannot_be_zero()
    {
        $units = DB::table('units')->count();

        $response = $this->as($this->owner)->postJson('/api/units',
            ['name' => 'Zero bag', 'ShortName' => 'zbag', 'base_unit' => $this->bag, 'operator' => '/', 'operator_value' => 0]);

        $this->assertRefused($response, 'a factor of 0 is refused');
        $this->assertSame($units, DB::table('units')->count(), 'no unit is stored');
    }

    /**
     * K13 — an operator other than * or / is stored and silently treated as *.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_unit_operator_must_be_multiply_or_divide()
    {
        $units = DB::table('units')->count();

        $response = $this->as($this->owner)->postJson('/api/units',
            ['name' => 'Odd bag', 'ShortName' => 'obag', 'base_unit' => $this->bag, 'operator' => 'x', 'operator_value' => 5]);

        $this->assertRefused($response, 'operator "x" is refused');
        $this->assertSame($units, DB::table('units')->count(), 'no unit is stored');
    }

    /**
     * K13 — a sale line in a unit of another base (a pack of 10 pieces on cement sold by the bag) is accepted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_line_unit_must_belong_to_the_products_base_unit()
    {
        $piece = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $pack = DB::table('units')->insertGetId(['name' => 'Pack of 10', 'ShortName' => 'pk10', 'base_unit' => $piece,
            'operator' => '*', 'operator_value' => 10]);

        $response = $this->as($this->owner)->pos([$this->line(1, 265, ['sale_unit_id' => $pack])]);

        $this->assertRefused($response, 'cement is sold by the bag; a pack of 10 pieces is not one of its units');
        $this->assertEquals(50, $this->shelf(), 'no 10 bags leave for the price of one');
        $this->assertSame(0, DB::table('sales')->count());
    }
}
