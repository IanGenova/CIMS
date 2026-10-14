<?php

namespace Tests\Unit;

use App\utils\ForeignKeyInput;
use PHPUnit\Framework\TestCase;

/**
 * Normalising an optional foreign key that arrives from a form.
 *
 * The product edit screen builds its request with FormData, and
 * FormData.append(key, null) stringifies to the literal "null". The controller
 * used `$request['tax_group_id'] ?: null`, which leaves a non-empty string
 * intact, so "null" reached the database and the foreign key refused it:
 *
 *   SQLSTATE[23000]: 1452 Cannot add or update a child row: a foreign key
 *   constraint fails (`cims`.`products`, CONSTRAINT products_tax_group_id_foreign)
 *
 * Every product update failed on it.
 */
class ForeignKeyInputTest extends TestCase
{
    /** @test */
    public function the_literal_string_null_becomes_null()
    {
        $this->assertNull(ForeignKeyInput::id('null'));
    }

    /** @test */
    public function the_literal_string_undefined_becomes_null()
    {
        $this->assertNull(ForeignKeyInput::id('undefined'));
    }

    /** @test */
    public function an_empty_value_becomes_null()
    {
        $this->assertNull(ForeignKeyInput::id(''));
        $this->assertNull(ForeignKeyInput::id(null));
    }

    /** @test */
    public function zero_becomes_null_because_no_row_has_that_id()
    {
        $this->assertNull(ForeignKeyInput::id(0));
        $this->assertNull(ForeignKeyInput::id('0'));
    }

    /** @test */
    public function a_real_id_survives_as_an_integer()
    {
        $this->assertSame(7, ForeignKeyInput::id(7));
        $this->assertSame(7, ForeignKeyInput::id('7'));
    }

    /** @test */
    public function anything_that_is_not_a_number_becomes_null()
    {
        $this->assertNull(ForeignKeyInput::id('abc'));
        $this->assertNull(ForeignKeyInput::id('7abc'));
        $this->assertNull(ForeignKeyInput::id([]));
    }
}
