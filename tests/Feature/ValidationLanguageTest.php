<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Validation errors are sentences a cashier can act on (DATATEST K69).
 */
class ValidationLanguageTest extends TestCase
{
    /** @test */
    public function the_common_rules_read_as_sentences()
    {
        $v = Validator::make(
            ['name' => '', 'qty' => 0, 'email' => 'x', 'date' => 'hello', 'price' => 'abc', 'code' => str_repeat('a', 300)],
            ['name' => 'required', 'qty' => 'numeric|gt:0', 'email' => 'email', 'date' => 'date',
                'price' => 'numeric', 'code' => 'max:191']
        );

        foreach ($v->errors()->all() as $message) {
            $this->assertStringStartsNotWith('validation.', $message, "a rule answered with its key: $message");
        }
        $this->assertSame('The name field is required.', $v->errors()->first('name'));
    }

    /** @test */
    public function detail_lines_are_named_in_words()
    {
        $v = Validator::make(['details' => [['quantity' => 0]]], ['details.*.quantity' => 'numeric|gt:0']);

        $this->assertSame('The quantity must be greater than 0.', $v->errors()->first('details.0.quantity'));
    }
}
