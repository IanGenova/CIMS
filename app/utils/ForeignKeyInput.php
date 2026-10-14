<?php

namespace App\utils;

/**
 * An optional foreign key as it arrives from a form.
 *
 * The product screens build their request with FormData, and
 * `FormData.append(key, null)` stringifies to the literal `"null"`. The old
 * `$request['tax_group_id'] ?: null` only catches falsy values, so `"null"`
 * went straight to the database and the constraint refused it:
 *
 *   SQLSTATE[23000]: 1452 Cannot add or update a child row: a foreign key
 *   constraint fails (`cims`.`products`, CONSTRAINT products_tax_group_id_foreign)
 *
 * Every product update failed on that, and the screen showed nothing because
 * the browser's own error handler threw while trying to report it.
 */
class ForeignKeyInput
{
    /**
     * The id this input refers to, or null when it refers to nothing.
     *
     * Anything that is not a positive whole number is nothing: no row has id 0,
     * and "null", "undefined" and "" are all a form's way of saying empty.
     *
     * @param  mixed $value
     * @return int|null
     */
    public static function id($value)
    {
        if (is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === 'null' || $value === 'undefined') {
            return null;
        }

        if (!ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
