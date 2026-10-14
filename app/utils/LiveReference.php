<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A document may only name a customer, supplier or currency that still exists
 * (DATATEST K38/K68).
 *
 * A sale went through for a deleted customer, a purchase for a deleted
 * supplier, and a sale in a deleted or made-up currency was quietly priced at
 * rate 1.0; an unknown supplier id was a 500 from the foreign key.
 */
class LiveReference
{
    public static function assert($table, $id, $field, $what, $required = true)
    {
        if ($id === null || $id === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => ["Choose a {$what}."]]);
            }

            return;
        }

        $row = DB::table($table)->where('id', $id)->first(['id', 'deleted_at']);
        if (! $row) {
            throw ValidationException::withMessages([$field => ["That {$what} does not exist."]]);
        }
        if ($row->deleted_at !== null) {
            throw ValidationException::withMessages([$field => ["That {$what} has been deleted. Choose another."]]);
        }
    }
}
